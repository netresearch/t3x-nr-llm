.. include:: /Includes.rst.txt

.. _adr-064:

==========================================
ADR-064: Central Privacy Model
==========================================

:Status: Accepted
:Date: 2026-07
:Authors: Netresearch DTT GmbH

.. _adr-064-context:

Context
=======

The extension is **metadata-only by construction** almost everywhere. Telemetry
(:ref:`adr-058`) stores no prompts or responses — only an exception FQCN on
failure — and already bounds its growth with a retention purge
(:bash:`nrllm:telemetry:purge`). But two tables *do* persist per-request
**content**, and until now they did so unconditionally:

* :sql:`tx_nrllm_eval_result.details` — a JSON snapshot of the per-prompt graded
  model output (:ref:`adr-060`). This is real model output.
* :sql:`tx_nrllm_skill_audit.scan_result` and :sql:`detail` — the
  prompt-injection scan output and a free-form detail string (:ref:`adr-061`).

Both tables also carry pure-metadata columns (identifiers, counts, checksums,
trust level, timestamps) that are not sensitive.

ADR-058 named the gap explicitly:

    The central privacy model (retention tiers none/metadata/redacted/full) is a
    later workstream; this middleware is metadata-only by construction.

So there was no single, operator-configurable answer to "how much per-request
content does this extension keep, and for how long?", and no purge for the two
content tables — only telemetry had one.

.. _adr-064-decision:

Decision
========

Introduce one central privacy model, read from the extension configuration and
applied at every content sink before a write.

**1. Four levels** — a backed :php:`PrivacyLevel` enum on a strict-to-loose
scale:

* ``none`` — drop content, keep metadata.
* ``metadata`` — drop content, keep metadata (**the default**).
* ``redacted`` — store a bounded, credential-scrubbed copy.
* ``full`` — store content verbatim.

The enum exposes ``persistsContent()`` (true for ``redacted``/``full``),
``requiresRedaction()`` (true for ``redacted``) and a ``severity()`` ordering
with ``strictest(a, b)`` so a global default and any future per-scope override
combine with **"strictest wins"** — ``none`` is strictest, ``full`` loosest.

**2. Safe default** — :php:`PrivacyPolicy::level()` returns
:php:`PrivacyLevel::METADATA` when the setting is unset or invalid. That is the
behaviour the extension already had by construction, so an un-configured
instance keeps content out of the database, not in it.

**3. Two governed sinks.** :php:`EvaluationResultRepository` and
:php:`SkillAuditRepository` inject :php:`PrivacyPolicyInterface` and pass their
content columns through ``filterContent()`` before insert
(``details``; ``scan_result`` and ``detail``). ``null`` (dropped content) is
stored as the empty string so the columns stay well-typed. Every metadata column
is untouched.

**4. Honest, bounded redaction.** :php:`ContentRedactor` (the ``redacted`` level)
masks a small set of high-signal secrets — credential-bearing URL query
parameters (reusing :php:`ErrorMessageSanitizerTrait`, not a copied regex),
obvious bearer / API tokens, and email addresses — and caps length at 2000
characters. Its doc block states plainly that it is a heuristic, **not** a
guaranteed PII scrubber; operators who must not store content at all use
``none``/``metadata``.

**5. Centralised retention.** Each content repository gains a
``purgeOlderThan(int $timestamp): int`` mirroring
:php:`TelemetryRepository::purgeOlderThan()`
(``run_date`` for eval results, ``crdate`` for the audit trail). A new command
:bash:`nrllm:privacy:purge` (``--days``, defaulting to the configured
``privacy.retentionDays``, floor 1) purges **all three** log tables — eval
results, skill audit and telemetry — and reports the count per table. The
retention window clamps a missing / zero / negative setting to 30 days: ``0``
must never mean "delete everything immediately". The existing
:bash:`nrllm:telemetry:purge` stays for backward compatibility.

**6. Configuration.** Two settings under a new ``privacy`` category in
:file:`ext_conf_template.txt`: ``privacy.level``
(``options[None|Metadata|Redacted|Full]``, default ``metadata``) and
``privacy.retentionDays`` (``int+``, default 30).

.. _adr-064-consequences:

Consequences
============

* ●● **One operator-facing answer to "what is stored".** A single setting, read
  in one place and enforced at every content sink, replaces the previous
  implicit, per-table behaviour.
* ●● **Safe by default.** An un-configured instance stays metadata-only — the
  historical behaviour — so this is not a silent behaviour change for existing
  installs. It generalises the principle telemetry already followed.
* ● **Bounded, purgeable growth for the content tables too.** The two content
  logs get the retention story telemetry already had; :bash:`nrllm:privacy:purge`
  bounds all three from the scheduler.
* ● **Strictest-wins ordering is ready for per-scope overrides.** The
  ``severity()`` / ``strictest()`` API expresses tightening today even though
  only the global default is wired, so a future per-configuration override
  composes without a rework.
* ◐ **Redaction is deliberately shallow.** ``redacted`` removes known secret
  shapes and caps length; it does not guarantee PII removal. The honest doc
  block prevents a false sense of safety, and ``none``/``metadata`` remain the
  answer when content must not be stored.
* ✕ **Two purge commands now exist.** :bash:`nrllm:telemetry:purge` is kept for
  backward compatibility alongside the broader :bash:`nrllm:privacy:purge`.
  Operators scheduling the new command can retire the old one.
* ◑ **Lossy at redacted/full boundaries.** At ``redacted`` the stored
  ``details`` JSON may be truncated or masked and is no longer guaranteed to
  round-trip; consumers that need the verbatim snapshot must run at ``full``.

**Net Score: +6** (2×●● +4, 2×● +2, 1×◐ +0.5, 1×✕ −1.5, 1×◑ −1).

.. _adr-064-alternatives:

Alternatives considered
=======================

* **Per-table settings instead of one model.** Rejected: it multiplies operator
  surface and drifts, and gives no single answer to the compliance question.
  One enum applied at every sink is the point.
* **A comprehensive PII scrubber.** Rejected as dishonest for the scope: robust
  PII detection is a large, error-prone problem. A bounded heuristic with an
  explicit "not a guarantee" contract, plus ``none``/``metadata`` for
  store-nothing, is the truthful design.
* **Drop content unconditionally (no ``full``/``redacted``).** Rejected: the
  eval snapshot and injection-scan output have legitimate debugging and
  provenance uses. Making retention a choice — with a metadata-only default —
  serves both the privacy-first and the diagnostics case.
* **Reuse ``nrllm:telemetry:purge`` for all tables.** Rejected: overloading a
  command named for one table is surprising. A dedicated
  :bash:`nrllm:privacy:purge` owns cross-table retention; the old command stays
  for compatibility.

.. _adr-064-references:

References
==========

* :ref:`adr-058` — Telemetry Middleware (metadata-only by construction; named
  this workstream as the follow-up).
* :ref:`adr-060` — Quality Evaluation (owns :sql:`tx_nrllm_eval_result`).
* :ref:`adr-061` — Skill Trust, Egress and Audit (owns
  :sql:`tx_nrllm_skill_audit`).
* ADR-017 — SafeCastTrait / ErrorMessageSanitizerTrait (the shared credential
  redaction reused here).
