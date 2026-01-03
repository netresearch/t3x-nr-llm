.. include:: /Includes.rst.txt

.. _adr-012:

==================================================
ADR-012: API key encryption at application level
==================================================

:Status: Superseded
:Date: 2024-12-27
:Superseded: 2025-01 by nr-vault integration
:Authors: Netresearch DTT GmbH

.. note::

   This ADR documents the original encryption approach which has been replaced.
   API keys are now stored using the :composer:`netresearch/nr-vault` extension
   which provides enterprise-grade secrets management with envelope encryption,
   audit logging, and access control.

.. _adr-012-context:

Context
=======

The nr_llm extension stores API keys for various LLM providers (OpenAI, Anthropic, etc.)
in the database. These credentials are sensitive and require protection.

.. _adr-012-problem-statement:

Problem statement
-----------------

TYPO3's TCA :php:`type=password` field has two modes:

1. **Hashed mode (default):** Uses bcrypt/argon2 - irreversible, suitable for user passwords
2. **Unhashed mode (hashed => false):** Stores plaintext - required for API keys that must be retrieved

API keys must be retrievable to authenticate with external services, so hashing is not an option.
However, storing them in plaintext exposes them to:

- Database dumps/backups
- SQL injection attacks
- Unauthorized database access
- Accidental exposure in logs

.. _adr-012-requirements:

Requirements
------------

1. API keys must be retrievable (not hashed).
2. Keys must be encrypted at rest in the database.
3. Encryption must be transparent to the application.
4. Solution must work without external dependencies (self-contained).
5. Must support key rotation.
6. Backwards compatible with existing plaintext values.

.. _adr-012-decision:

Decision
========

Implement application-level encryption using **sodium_crypto_secretbox** (XSalsa20-Poly1305)
with key derivation from TYPO3's encryptionKey.

.. _adr-012-architecture:

Architecture
------------

::

   ┌─────────────────────────────────────────────────────────────────┐
   │                        Backend Form                              │
   │                    (user enters API key)                         │
   └─────────────────────────────┬───────────────────────────────────┘
                                 │ plaintext
                                 ▼
   ┌─────────────────────────────────────────────────────────────────┐
   │                    Provider::setApiKey()                         │
   │              ProviderEncryptionService::encrypt()                │
   │                                                                  │
   │  1. Generate random nonce (24 bytes)                             │
   │  2. Derive key from TYPO3 encryptionKey via SHA-256              │
   │  3. Encrypt with XSalsa20-Poly1305                               │
   │  4. Prefix with "enc:" marker                                    │
   │  5. Base64 encode for storage                                    │
   └─────────────────────────────┬───────────────────────────────────┘
                                 │ "enc:base64(nonce+ciphertext+tag)"
                                 ▼
   ┌─────────────────────────────────────────────────────────────────┐
   │                         Database                                 │
   │                   tx_nrllm_provider.api_key                      │
   └─────────────────────────────────────────────────────────────────┘

.. _adr-012-key-derivation:

Key derivation
--------------

.. code-block:: php
   :caption: Example: Domain-separated key derivation

   // Domain-separated key derivation
   $key = hash('sha256', $typo3EncryptionKey . ':nr_llm_provider_encryption', true);

The domain separator ``:nr_llm_provider_encryption`` ensures:

- Keys are unique to this use case.
- Same encryptionKey produces different keys for different purposes.
- No collision with other extensions using similar patterns.

.. _adr-012-encryption-format:

Encryption format
-----------------

::

   enc:{base64(nonce || ciphertext || auth_tag)}

   Where:
   - "enc:" = 4-byte prefix marker
   - nonce = 24 bytes (SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)
   - ciphertext = variable length
   - auth_tag = 16 bytes (Poly1305 MAC, included by sodium)

.. _adr-012-implementation:

Implementation
==============

.. _adr-012-files:

Files created/modified
----------------------

.. csv-table::
   :header: "File", "Purpose"

   ":file:`Classes/Service/Crypto/ProviderEncryptionServiceInterface.php`", "Interface definition"
   ":file:`Classes/Service/Crypto/ProviderEncryptionService.php`", "Encryption implementation"
   ":file:`Classes/Domain/Model/Provider.php`", "Updated setApiKey/getDecryptedApiKey"
   ":file:`Configuration/TCA/tx_nrllm_provider.php`", "Added hashed => false"
   ":file:`Configuration/Services.yaml`", "Service registration"

.. _adr-012-key-methods:

Key methods
-----------

.. code-block:: php
   :caption: Example: Encryption service methods

   // ProviderEncryptionService
   public function encrypt(string $plaintext): string;
   public function decrypt(string $ciphertext): string;
   public function isEncrypted(string $value): bool;

   // Provider Model
   public function setApiKey(string $apiKey): void;      // Encrypts before storage
   public function getApiKey(): string;                   // Returns raw (encrypted)
   public function getDecryptedApiKey(): string;          // Returns decrypted
   public function toAdapterConfig(): array;              // Uses decrypted key

.. _adr-012-consequences:

Consequences
============

.. _adr-012-positive:

Positive
--------

◐ **Encryption at rest:** Database dumps no longer expose plaintext credentials.

◐ **Transparent operation:** Encryption/decryption handled automatically.

◐ **No external dependencies:** Uses PHP's built-in sodium extension.

◐ **Authenticated encryption:** Tampering is detected (Poly1305 MAC).

◐ **Backwards compatible:** Unencrypted values work without migration.

◐ **Industry standard:** XSalsa20-Poly1305 is used by NaCl/libsodium.

.. _adr-012-negative:

Negative
--------

◑ **Single point of failure:** If encryptionKey is compromised, all keys are exposed.

◑ **No key rotation:** Changing encryptionKey requires re-encryption of all keys.

◑ **In-memory exposure:** Decrypted keys exist briefly in memory.

◑ **Performance overhead:** Encryption/decryption on every save/load (minimal).

**Net Score:** +4 (Strong positive)

.. _adr-012-alternatives:

Alternatives considered
=======================

1. TYPO3 Core password type with custom transformer.
   **Rejected:** TCA doesn't support custom encryption transformers for password fields.

2. Defuse PHP Encryption library.
   **Rejected:** Adds external dependency. Sodium is built into PHP 7.2+.

3. OpenSSL AES-256-GCM.
   **Rejected:** Sodium's API is simpler and less prone to misuse.

4. Database-level encryption (TDE).
   **Rejected:** Requires database configuration, not portable across environments.

5. External vault (HashiCorp, AWS KMS).
   **Deferred:** Planned for nr-vault extension. Current solution works standalone.

.. _adr-012-references:

References
==========

- `libsodium Documentation <https://doc.libsodium.org/>`__
- `PHP Sodium Extension <https://www.php.net/manual/en/book.sodium.php>`__
- `TYPO3 TCA Password Type <https://docs.typo3.org/m/typo3/reference-tca/main/en-us/ColumnsConfig/Type/Password/Index.html>`__
- `OWASP Cryptographic Storage Cheat Sheet <https://cheatsheetseries.owasp.org/cheatsheets/Cryptographic_Storage_Cheat_Sheet.html>`__
