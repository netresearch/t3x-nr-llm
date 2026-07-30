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

Delegate to nr-vault's :php:`Netresearch\NrVault\Crypto\EncryptionServiceInterface`
— the same managed-key envelope AEAD the vault uses for secrets — rather than
hand-rolling crypto. nr-vault is already a hard dependency (API-key storage), so
this is one crypto implementation to audit, not two, and the key management is
the vault's, not ours: a per-value data key (DEK) is wrapped by a **rotatable
master key** (:php:`MasterKeyProviderInterface`, with a rotate command and a
``MasterKeyRotatedEvent``), so the master can be rotated without re-encrypting
every row. Each envelope is authenticated (a tampered or truncated row fails to
decrypt rather than yielding a forged plaintext) and a fresh DEK/nonce per
encryption means identical state never yields identical ciphertext.

The per-column **identifier is passed as additional authenticated data (AAD)** —
``nrllm:agent-state:queued-request`` vs ``…:suspended-state`` — so a ciphertext
authenticates only against the column it was written for: moving a queued-request
envelope into the suspended-state column fails authentication.

Format and versioning
---------------------

Stored as ``v2:`` + base64( json of :php:`EncryptedData::toArray()` — the wrapped
DEK, both nonces, the value checksum, and the version/algorithm markers ). The
version prefix distinguishes the envelope from the legacy cleartext it replaces.

Seam
----

The codec lives at the repository boundary: the two columns are encrypted on
write and decrypted in ``hydrateRun`` on read, so the persister and the runtime
keep handling plaintext JSON and nothing above the repository changes.

.. _adr-114-consequences:

Consequences
============

- **Backwards compatible.** ``decode()`` returns any value WITHOUT the ``v2:``
  marker verbatim, so a row written before this landed (plaintext JSON) still
  rehydrates. New writes are always encrypted; an upgrade needs no data
  migration.
- **Fail-closed.** When the master key is unavailable nr-vault refuses to encrypt
  (the fail-soft persister then does not store a QUEUED/suspended row) rather
  than silently storing cleartext, and a payload that fails authentication throws
  — the fail-soft read path then treats the run as unreadable rather than
  resuming a forged or corrupt state.
- The columns stay ``mediumtext``; the base64 JSON envelope is larger than the
  plaintext but well within the 16 MB bound.
- The privacy-retention policy (:ref:`ADR-064 <adr-064>`) still governs how long
  state is kept — encryption protects it while it exists, it does not extend its
  life.

.. _adr-114-rotation:

Key rotation
============

Covered, but only because this extension registers for it.

An earlier revision of this ADR claimed rotation was handled: "key rotation is
the vault's … rotating the master key re-wraps the DEKs without touching the row
ciphertext", citing nr-vault's rotate command and its
:php:`MasterKeyRotatedEvent`. **That was wrong on both counts, and it shipped in
0.24.0.** nr-vault's ``vault:rotate-master-key`` re-wrapped the data keys it found
by walking its own ``tx_nrvault_secret``; the data key of an agent-state envelope
lives in ``tx_nrllm_agentrun``, where that walk never reached it. And the event,
though declared and documented upstream, was never dispatched from anywhere — so
this extension could not have subscribed to learn a rotation had happened either.
Encrypting these columns was therefore a delayed data-loss bug: the first master
key rotation made every encrypted queued and suspended run permanently
unreadable, silently, because the rotation succeeded at everything it knew about.

The correction is recorded rather than edited away, because a wrong claim about
where data survives is worth remembering.

nr-vault now exposes :php:`ForeignEnvelopeRotatorInterface` (nr-vault ADR-033),
and :php:`AgentStateEnvelopeRotator` implements it. Registering it is what makes
rotation cover these rows:

.. code-block:: yaml
   :caption: Configuration/Services.yaml

   Netresearch\NrLlm\Service\Tool\AgentStateEnvelopeRotator:
     tags: ['nrvault.foreign_envelope_rotator']

The rotator re-wraps both columns inside the vault's own rotation transaction, so
a failure rolls the whole rotation back rather than leaving half the installation
under a key the operator is about to destroy. It re-wraps the DEK layer only —
the payload is never decrypted — and it covers BOTH the current ``nrv1:`` marker
and the legacy ``v2:`` one written by 0.24.0-0.25.x, since both are sealed under
the same master key. Default query restrictions are removed so no row is hidden
from the pass, and the walk pages by ``uid`` rather than ``OFFSET`` because it is
rewriting the rows as it goes.

Requires nr-vault ^0.13.0. On an older nr-vault the interface does not exist, so
the constraint is a hard one rather than a suggestion.
