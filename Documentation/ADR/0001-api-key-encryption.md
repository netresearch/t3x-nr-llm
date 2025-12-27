# ADR-0001: API Key Encryption at Application Level

**Status:** Accepted
**Date:** 2024-12-27
**Authors:** Netresearch DTT GmbH

## Context

The nr_llm extension stores API keys for various LLM providers (OpenAI, Anthropic, etc.) in the database. These credentials are sensitive and require protection.

### Problem Statement

TYPO3's TCA `type=password` field has two modes:

1. **Hashed mode (default):** Uses bcrypt/argon2 - irreversible, suitable for user passwords
2. **Unhashed mode (`hashed => false`):** Stores plaintext - required for API keys that must be retrieved

API keys must be retrievable to authenticate with external services, so hashing is not an option. However, storing them in plaintext exposes them to:

- Database dumps/backups
- SQL injection attacks
- Unauthorized database access
- Accidental exposure in logs

### Requirements

1. API keys must be retrievable (not hashed)
2. Keys must be encrypted at rest in the database
3. Encryption must be transparent to the application
4. Solution must work without external dependencies (self-contained)
5. Must support key rotation
6. Backwards compatible with existing plaintext values

## Decision

Implement application-level encryption using **sodium_crypto_secretbox** (XSalsa20-Poly1305) with key derivation from TYPO3's encryptionKey.

### Architecture

```
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
└─────────────────────────────┬───────────────────────────────────┘
                              │ encrypted
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                Provider::getDecryptedApiKey()                    │
│              ProviderEncryptionService::decrypt()                │
│                                                                  │
│  1. Check "enc:" prefix (if missing, return as-is)              │
│  2. Base64 decode                                                │
│  3. Extract nonce (first 24 bytes)                               │
│  4. Decrypt with XSalsa20-Poly1305                               │
│  5. Verify authentication tag (Poly1305)                         │
└─────────────────────────────┬───────────────────────────────────┘
                              │ plaintext
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      API Request                                 │
│                  Authorization: Bearer <key>                     │
└─────────────────────────────────────────────────────────────────┘
```

### Key Derivation

```php
// Domain-separated key derivation
$key = hash('sha256', $typo3EncryptionKey . ':nr_llm_provider_encryption', true);
```

The domain separator `:nr_llm_provider_encryption` ensures:
- Keys are unique to this use case
- Same encryptionKey produces different keys for different purposes
- No collision with other extensions using similar patterns

### Encryption Format

```
enc:{base64(nonce || ciphertext || auth_tag)}

Where:
- "enc:" = 4-byte prefix marker
- nonce = 24 bytes (SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)
- ciphertext = variable length
- auth_tag = 16 bytes (Poly1305 MAC, included by sodium)
```

## Implementation

### Files Created/Modified

| File | Purpose |
|------|---------|
| `Classes/Service/Crypto/ProviderEncryptionServiceInterface.php` | Interface definition |
| `Classes/Service/Crypto/ProviderEncryptionService.php` | Encryption implementation |
| `Classes/Domain/Model/Provider.php` | Updated setApiKey/getDecryptedApiKey |
| `Configuration/TCA/tx_nrllm_provider.php` | Added `hashed => false` |
| `Configuration/Services.yaml` | Service registration |

### Key Methods

```php
// ProviderEncryptionService
public function encrypt(string $plaintext): string;
public function decrypt(string $ciphertext): string;
public function isEncrypted(string $value): bool;

// Provider Model
public function setApiKey(string $apiKey): void;      // Encrypts before storage
public function getApiKey(): string;                   // Returns raw (encrypted)
public function getDecryptedApiKey(): string;          // Returns decrypted
public function toAdapterConfig(): array;              // Uses decrypted key
```

## Consequences

### Positive

1. **Encryption at rest:** Database dumps no longer expose plaintext credentials
2. **Transparent operation:** Encryption/decryption handled automatically
3. **No external dependencies:** Uses PHP's built-in sodium extension
4. **Authenticated encryption:** Tampering is detected (Poly1305 MAC)
5. **Backwards compatible:** Unencrypted values work without migration
6. **Industry standard:** XSalsa20-Poly1305 is used by NaCl/libsodium

### Negative

1. **Single point of failure:** If encryptionKey is compromised, all keys are exposed
2. **No key rotation:** Changing encryptionKey requires re-encryption of all keys
3. **In-memory exposure:** Decrypted keys exist briefly in memory
4. **Performance overhead:** Encryption/decryption on every save/load (minimal)

### Risks and Mitigations

| Risk | Mitigation |
|------|------------|
| encryptionKey in git | TYPO3 best practice: keep in environment variable |
| Backup exposure | Backups contain encrypted data only |
| Memory dump | Keys decrypted on-demand, not cached |
| Algorithm weakness | XSalsa20-Poly1305 is proven secure |

## Alternatives Considered

### 1. TYPO3 Core Password Type with Custom Transformer

**Rejected:** TCA doesn't support custom encryption transformers for password fields.

### 2. Defuse PHP Encryption Library

**Rejected:** Adds external dependency. Sodium is built into PHP 7.2+.

### 3. OpenSSL AES-256-GCM

**Rejected:** Sodium's API is simpler and less prone to misuse.

### 4. Database-Level Encryption (TDE)

**Rejected:** Requires database configuration, not portable across environments.

### 5. External Vault (HashiCorp, AWS KMS)

**Deferred:** Planned for nr-vault extension. Current solution works standalone.

## Future Considerations

1. **Master key rotation CLI command:** Re-encrypt all API keys with new key
2. **Per-provider DEKs:** Envelope encryption for isolated key compromise
3. **Integration with nr-vault:** Migrate to dedicated secrets management
4. **Audit logging:** Track API key access patterns

## References

- [libsodium Documentation](https://doc.libsodium.org/)
- [PHP Sodium Extension](https://www.php.net/manual/en/book.sodium.php)
- [TYPO3 TCA Password Type](https://docs.typo3.org/m/typo3/reference-tca/main/en-us/ColumnsConfig/Type/Password/Index.html)
- [OWASP Cryptographic Storage Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Cryptographic_Storage_Cheat_Sheet.html)
