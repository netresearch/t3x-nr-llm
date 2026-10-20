.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-220:

====================================================
ADR-220: Evaluation results name their generator
====================================================

:Status: Accepted
:Date: 2026-10-10
:Amends: :ref:`ADR-060 <adr-060>` (model-specific results require one
    consistently reported generator identity)
:Authors: Netresearch DTT GmbH

Context
=======

A golden-set run may use a fallback model. EvaluationService previously
overwrote its run model with each response and stored every prompt's score
under the final model. The real quality-routing consumer then attributed a
primary model's failure to the fallback that answered another prompt.

The grading yardstick and the generator are separate facts. Deterministic
assertions can fairly grade answers from several models, while their
aggregate is not a measurement of any one model on the complete set.

Decision
========

1. Distinguish the DB provider instance from the adapter key returned in
   ``CompletionResponse.provider``. After a configuration-driven terminal
   succeeds, its actual resolved DB provider, final outbound model alias
   and response-reported model become reserved additive response metadata.
   The actual fallback terminal supplies its own identity. Requested or
   primary configuration values are not serving evidence.

   The terminal overwrites any adapter-supplied reserved value and preserves
   the rest of the response. Cache/idempotency replay preserves the original
   served identity. Adapter-only paths without a DB instance remain unknown.
   The existing public response constructor remains unchanged.

2. Keep this provenance in prompt details and identify a run only when all
   prompts share its DB provider instance, actual outbound alias and
   reported model. The outbound alias is the homogeneous run's model key;
   its reported model is a separate fact, including for snapshot names.
   Mixed, partly unknown and empty runs keep an empty run model, their
   grades and aggregate. No subset becomes a full-set measurement.

3. Keep grading comparability independent. ``sharesOneYardstick()`` still
   checks graders. A run without one generator is recorded but cannot
   provide a model-specific regression or quality-routing signal. The
   strict CLI gate fails when such a comparison cannot be made.

4. Keep versioned, content-free generator provenance in a dedicated column
   of the existing result table. It survives metadata privacy separately
   from the filtered details. Absent, malformed, unsupported-version or
   row-inconsistent provenance supplies no eligibility evidence. Retention
   removes it with the result. Retrieval provenance retains its own column.

5. Core quality reads and generator baselines require verified provenance,
   including provider instance and outbound model alias. Baselines also
   require the same reported model, explicitly passed to the scoped read
   and filtered before choosing its latest row. A newer different reported
   snapshot cannot mask an earlier matching baseline. Add optional scoped
   capabilities beside the existing repository and quality-provider interfaces,
   without adding
   required members to those contracts. Routing uses the capability when
   present; custom model-ID-only sources retain their existing contract.
   The core adapter treats old repositories without verified reads as an
   unknown signal. An unscoped core read is unknown when several verified
   providers share the same model name.

6. Preserve historical aggregate/history reads. Legacy records are
   unverified and cannot drive core quality routing or a new generator
   baseline. Do not backfill invented evidence. Trailing optional internal
   DTO fields preserve existing constructors; no frozen ``@api`` contract
   changes.

Consequences
============

Homogeneous runs retain their established behavior. Mixed runs remain
useful aggregate measurements but cannot be presented as the final model's
quality. Reported identifiers describe the provider response; mutable
model aliases do not establish fixed weights.

Old rows kept only the collapsed model identity. They remain readable but
stop contributing to core quality routing because they cannot be corrected
reliably from that value, including when details were removed by privacy.
Operators update the database schema and re-evaluate relevant model/set
series to restore verified quality signals.

The specification is :file:`specs/018-evaluation-generator-attribution/spec.md`.
