.. include:: /Includes.rst.txt

.. _adr-061:

==================================================================
ADR-061: Skill trust levels, signed manifests, injection scanning
and per-group egress policies
==================================================================

:Status: Accepted
:Date: 2026-07-14
:Authors: Netresearch DTT GmbH

.. _adr-061-context:

Context
=======

The skill and tool subsystems already have a solid, layered base:

- **Ingest** (:ref:`ADR-035 <adr-035>`) fetches ``SKILL.md`` behind a GitHub
  host allow-list, resolves refs to an immutable commit SHA, checksums each
  body, and arrives ``enabled = false`` for repo/marketplace skills.
- **Injection** (:ref:`ADR-036 <adr-036>`) re-verifies the checksum fail-closed
  at compose time, never uses the system role, and fences the block behind a
  guard preamble.
- **Tools** (:ref:`ADR-038 <adr-038>`, :ref:`ADR-043 <adr-043>`) enforce a
  fail-closed allow-list, per-tool admin gating against the acting backend
  user, and a group enable-cascade.

Four gaps remain. The SHA-pin binds *bytes to a URL* but not *bytes to a
publisher identity*. The ingest inspects structure but never looks at the prose
for prompt-injection payloads. The instruction/data boundary rests on a guard
sentence and message role, without an explicit data delimiter. And there is no
immutable record of *who* imported or enabled *which* skill *when* — the sync
overwrites its own state. Separately, network egress is decided per tool
(``probe_url`` hard-codes its own site allow-list) rather than declared per tool
group, so a new or third-party tool's egress is governed by nothing.

.. _adr-061-decision:

Decision
========

1. **Publisher trust levels, separate from support status.**
   :php:`SkillTrustLevel` (``untrusted`` < ``community`` < ``verified`` <
   ``first_party``) classifies a :php:`SkillSource`'s *provenance*. It is
   explicitly **not** ``support_status`` (which is not a safety signal,
   :ref:`ADR-035 <adr-035>`). The level is denormalised onto each :php:`Skill`
   at ingest exactly as ``support_status`` is, and an unknown/legacy stored
   value reads as the lowest level (``fromStringOrUntrusted``). Injection and
   the allowed-tools union are gated against a configurable **minimum** trust
   level (``skills.minTrustLevel``, default ``untrusted`` = accept every enabled
   skill): :php:`SkillComposer::effectiveSkills()` — the single source of truth
   for both paths — drops any skill below the floor. This composes with, and
   does not replace, the existing ``enabled = false`` default: ``untrusted``
   still requires an explicit admin enable.

2. **Manifest fingerprint over a full public-key signature.** A source may
   declare an ``expected_fingerprint``: the sha256 its whole discovered skill
   set must hash to (a canonical, order-independent digest over the
   ``identifier → body-checksum`` pairs, degenerating to one entry for a
   single-file source). :php:`SkillManifestVerifier` recomputes it after collect
   and verifies with ``hash_equals`` **before** any skill is materialised; a
   mismatch fails closed — no upsert, no orphaning — leaving the last known-good
   skills untouched, and is audited. We chose a declared expected digest over a
   detached public-key signature deliberately: it binds a publisher-declared
   identity to the exact reviewed bytes (beyond the URL SHA-pin) with **no
   key-management infrastructure**. Full detached signatures over a signed
   manifest listing per-skill digests are a documented follow-up (see
   :ref:`consequences <adr-061-consequences>`).

3. **Prompt-injection scanning at ingest.** :php:`PromptInjectionScanner` runs
   a data-driven, auditable pattern set over each body and records the findings
   on the skill. Tiering is conservative to avoid over-blocking legitimate
   prose: only **high-confidence** jailbreak markers (instruction override,
   role reset, DAN/developer-mode personas, chat-template control tokens)
   **force-disable** the skill fail-closed at import — even a single-file source
   that would otherwise default enabled. Medium/low findings (secret-exposure
   verbs, guardrail-bypass wording, covert behaviour, long encoded blobs) only
   *flag* the record for review, since the repo/marketplace skills they most
   often appear on already arrive disabled.

4. **Explicit instruction/data channel separation.** :php:`SkillComposer` keeps
   the trusted guard preamble and additionally fences the untrusted bodies
   between explicit ``BEGIN/END UNTRUSTED SKILL DATA`` markers that label them
   as reference data the model must not execute as instructions. Message role
   remains defence-in-depth, **not** a trust boundary (:ref:`ADR-036
   <adr-036>`); the markers give the model an unambiguous boundary in-band.

5. **Immutable import audit trail.** ``tx_nrllm_skill_audit`` records who / when
   / which source / which SHA / which checksum / which trust level / which scan
   result for every ingest outcome, enable, disable and fail-closed rejection.
   It is **append-only by construction**: :php:`SkillAuditRepository` exposes
   ``record()`` and read helpers and offers no update or delete path, and the
   table carries no soft-delete column. Any purge is a separate, documented
   retention operation — never the regular write path. The trail is additive to
   the existing sync; no backend UI (UI-less log, like ``tx_nrllm_tool_state``).

6. **Per-group egress policies.** :php:`EgressPolicyService` declares a
   network-egress scope per tool group (:ref:`ADR-043 <adr-043>`) as data,
   fail-closed: a group with no entry resolves to
   :php:`ToolEgressScope::NONE` and may make no outbound request. The one
   positive scope, ``own_site``, resolves the instance's own site hosts through
   ``SiteFinder`` — the allow-listing ``probe_url`` previously hard-coded, now
   lifted to the group boundary and consulted by the tool through the shared
   gate. There is no free-form "any host" scope, so a new or mis-declared group
   can never egress to an arbitrary target.

.. _adr-061-consequences:

Consequences
============

- ● Publisher identity is now a first-class, fail-closed control: trust gates
  injection and tool grants, a declared fingerprint binds the reviewed bytes to
  the publisher, and an unknown trust value reads as the lowest.
- ● Prompt-injection payloads are caught at the door: high-confidence jailbreaks
  never reach an enabled state, and every body's scan result is retained for
  review and audit.
- ● The audit trail makes the provenance of any skill that can reach a prompt
  reconstructable after the fact, and cannot be rewritten through the app.
- ● Egress is declared per group and fail-closed; the network reach of the tool
  surface is now one auditable table, not scattered per-tool constants.
- ◐ The audit table grows monotonically (one row per ingest/enable/disable). At
  the expected admin-driven cadence this is negligible; a retention command is a
  documented follow-up if ever needed.
- ◐ Egress scope is keyed by the existing coarse group taxonomy, so ``system``
  (which carries ``probe_url``) is granted ``own_site`` even though it also
  holds non-egressing diagnostics. Those tools never call the gate, so the grant
  does not loosen them; a dedicated network group is a possible follow-up.
- ✕ **Deferred:** full detached public-key signatures over a signed per-skill
  manifest (the fingerprint is a declared expected digest, not a PKI
  signature); an admin-review queue UI for medium/low injection findings; and a
  dedicated egress group split. Each is additive to the fail-closed base
  delivered here.

See :ref:`ADR-035 <adr-035>` and :ref:`ADR-036 <adr-036>` for the ingest /
injection base, :ref:`ADR-038 <adr-038>` and :ref:`ADR-043 <adr-043>` for the
tool runtime and groups, and :ref:`the administration guide
<administration-skills>` for operation.
