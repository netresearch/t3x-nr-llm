.. include:: /Includes.rst.txt

.. _adr-114:

============================================================================
ADR-114: Encrypt queued and suspended agent-run state at rest
============================================================================

:Status: Accepted
:Date: 2026-07-23
:Authors: Netresearch DTT GmbH

.. _adr-114-context:

Context
=======

An agent run parks two payloads in ``tx_nrllm_agentrun`` while it waits: the
serialised request of a QUEUED run (:ref:`ADR-102 <adr-102>`) and the transcript
plus pending tool calls of a run suspended for approval or input
(:ref:`ADR-084 <adr-084>`, :ref:`ADR-105 <adr-105>`). Both were stored as
cleartext JSON. Unlike the event stream — which passes through the privacy
filter (:ref:`ADR-064 <adr-064>`) — these are stored VERBATIM, because a resume
must replay them exactly. They hold user prompts, tool arguments and internal
TYPO3 content, readable by anyone with database access (a backup, a replica, a
support dump).

.. _adr-114-decision:

Decision
========

Encrypt both columns at rest with an :php:`AgentStateCodec`.

Primitive
---------

libsodium XChaCha20-Poly1305 AEAD — authenticated encryption, bundled with PHP,
no userland crypto. The library owns the dangerous parts: a fresh random 24-byte
nonce per encryption (so identical state never yields identical ciphertext, and
there is no equality oracle across rows) and an authentication tag verified on
decrypt (so a tampered, truncated, or foreign-key row fails to decrypt rather
than yielding a forged plaintext). The key is derived from the instance
``encryptionKey`` via HKDF-SHA256 with a fixed context, so it is
instance-specific and never the raw site secret.

Format and versioning
---------------------

Stored as ``v1:`` + base64( nonce ‖ ciphertext‖tag ). The version prefix makes a
later scheme (a rotated key, a different cipher) distinguishable at read time
rather than guessed.

Seam
----

The codec lives at the repository boundary: the two columns are encrypted on
write and decrypted in ``hydrateRun`` on read, so the persister and the runtime
keep handling plaintext JSON and nothing above the repository changes.

.. _adr-114-consequences:

Consequences
============

- **Backwards compatible.** ``decode()`` returns any value WITHOUT the ``v1:``
  marker verbatim, so a row written before this landed (plaintext JSON) still
  rehydrates. New writes are always encrypted; an upgrade needs no data
  migration.
- **Fail-closed.** With no ``encryptionKey`` the codec refuses to encrypt rather
  than silently storing cleartext, and a payload that fails authentication
  throws — the fail-soft read path then treats the run as unreadable rather than
  resuming a forged or corrupt state.
- The columns stay ``mediumtext``; base64 ciphertext is larger than the JSON but
  well within the 16 MB bound.
- Key ROTATION beyond the single ``v1`` key (a key id in the prefix, a
  re-encrypt pass) is future work; the version tag reserves room for it. The
  privacy-retention policy (:ref:`ADR-064 <adr-064>`) still governs how long
  state is kept — encryption protects it while it exists, it does not extend its
  life.
