.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

==================================
Skill trust and integrity controls
==================================

.. _administration-skills-isolation:

Isolation controls: trust, fingerprint, injection scan, audit
=============================================================

On top of the SHA-pin and checksum controls above, each source and skill
carries the isolation controls introduced in :ref:`ADR-061 <adr-061>`.

**Publisher trust level.** Every source is classified — ``untrusted`` (the
default, for anonymous public GitHub content), ``community``, ``verified`` or
``first_party`` (operator-controlled). This is *provenance*, independent of the
``partial`` support badge. Each synced skill denormalises its source's level, so
re-classifying a source takes effect on the next sync. The instance-wide floor
``skills.minTrustLevel`` (extension configuration, default ``untrusted``) gates
use: a skill below the floor is dropped from **both** prompt injection and the
allowed-tools union. Raising the floor to ``verified`` therefore hides every
community/untrusted skill without deleting it. Trust is *separate from* the
``enabled = false`` default — an ``untrusted`` skill still needs an explicit
enable. An unreadable or mistyped floor falls back to ``untrusted``; the value
actually in force is shown under :ref:`administration-governance`.

**Skill-block byte budget.** ``skills.maxBytes`` (extension configuration,
default ``24000``) caps the composed block that is prepended to the user
prompt. The measure is bytes, not tokens — a deliberate over-estimate, since no
tokenizer is available. When the block exceeds the budget, skills are dropped
from the tail first: task-additive skills go before the configuration baseline,
and every drop is logged as a warning. Lower the value to reserve more of the
model's context window for the conversation itself; raise it if a large
configuration baseline is being trimmed. An empty, non-numeric or zero value
falls back to ``24000`` — the cap cannot be switched off, so an emptied field
never puts an unbounded block on the wire. The budget is instance-wide and
independent of the model's context window; the per-request window bound is
handled separately by the context-window manager (:ref:`ADR-107 <adr-107>`).

The budget bounds the prompt block only, **not** the ``allowed-tools`` union
(:ref:`Gating tools <administration-tools-allowed>`). The union is computed
over the effective skills before the block is assembled, so a skill dropped
for the budget still grants its tools while its usage rules stay out of the
prompt. That is deliberate — a budget-aware union would *widen* the gate,
because dropping the last declaring skill removes the restriction entirely.
Watch the drop warnings when lowering the value: they name every skill whose
prose stopped shipping while its tools kept being offered.

**Manifest fingerprint (optional).** A source may declare an
``expected_fingerprint`` over its collected identifiers and body checksums.
Sort the identifiers, encode each as ``identifier:body-checksum``, join the
lines with LF without a final LF, then hash those UTF-8 bytes with SHA-256.
Body checksums are SHA-256 hashes of the parsed markdown bodies. This
fingerprint does not include frontmatter, trust labels or approval state;
the version digest used for approval is a separate control.

When an expected fingerprint is set,
the digest is recomputed at sync and verified before anything is materialised; a
mismatch fails closed (no skill is imported, the source goes to ``error``) and
leaves the last known-good skills untouched. Leave it empty to rely on the
commit-SHA pin alone. This binds the reviewed bytes to a publisher-declared
identity beyond "these bytes from this URL"; it is a declared digest, not a
public-key signature.

**Prompt-injection scan.** Each body is scanned at ingest for known injection
signatures. A **high-confidence** jailbreak marker (e.g. "ignore all previous
instructions", role reset, chat-template control tokens) force-disables the
skill at import — even a single-file source that would otherwise default
enabled — and must be re-reviewed before enabling. Lower-confidence findings are
recorded on the skill (``Injection scan findings``) for review without blocking.
The scanner matches a fixed set of heuristic signatures, including control
markers and long encoded blobs. It can also flag quoted examples; an absence
of findings does not establish that a skill is trustworthy.

**Audit trail and retention.** Every ingest, enable, disable and fail-closed
rejection is written to ``tx_nrllm_skill_audit`` with who / when / source / SHA /
checksum / trust level. Scan output and free-form details follow the central
privacy policy. Ordinary writes append rows and there is no update path.
The explicit retention exception is :bash:`nrllm:privacy:purge`: it deletes
rows created strictly before the cutoff for the skill-audit category. Retained
rows support provenance reconstruction; purged rows are no longer available.
See :ref:`ADR-064 <adr-064>` and
:ref:`Data retention <administration-data-retention>`.
