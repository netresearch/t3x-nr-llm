.. include:: /Includes.rst.txt

.. _adr-178:

==============================================================================
ADR-178: Caller source on the cost path
==============================================================================

:Status: Accepted
:Date: 2026-08-19
:Authors: Netresearch DTT GmbH
:Amends: :ref:`ADR-177 <adr-177>` (its consequence that analytics
   aggregation by source is out of scope, and its confinement of the
   caller identity to the telemetry table)

Context
=======

:ref:`ADR-177 <adr-177>` gave a caller a way to name itself and persisted
that name on the telemetry row. It deliberately stopped there: "analytics
aggregation by source is not part of this record; it can follow as UI work
once rows carry the columns."

The question the attribution exists to answer turns out to be a cost
question: *which extension spends what*. That number does not live in
telemetry. Telemetry is the per-call observability log (:ref:`ADR-063
<adr-063>` retention window, purged); the money is aggregated daily in
``tx_nrllm_service_usage`` by ``UsageTrackerService``, and every analytics
figure — the KPI totals, the provider and model breakdowns, the per-user
report — reads that table alone. A source column on telemetry therefore
cannot be joined to a cost, and telemetry retention would truncate the
answer anyway.

The follow-up work is not "UI work" as ADR-177 assumed. It is a second
persistence decision.

Decision
========

The caller identity travels the cost path as well: ``source_extension``
becomes a column on ``tx_nrllm_service_usage`` and part of that table's
daily aggregation key, so two extensions calling the same model on the same
day produce two rows instead of one.

``UsageMiddleware`` reads the identity from the same request metadata
``TelemetryMiddleware`` reads, so both write-sites are fed from one source
and cannot drift apart. ``UsageTrackerServiceInterface::trackUsage()``
grows one optional trailing parameter — additive on the ``@api`` surface,
defaulting to ``''`` so every existing caller keeps compiling and keeps
writing the unattributed row it writes today.

Only the extension key is carried, not the operation. The operation stays a
telemetry detail: it multiplies the aggregation key without answering the
cost question, and a per-operation cost breakdown is a report, not a
dashboard axis.

The Analytics module gains a "By extension" breakdown next to provider and
model — same table, same query shape, same chart. Unattributed usage
(everything not annotated, which is the normal case for a wizard task or a
scheduler run) groups under an explicit label rather than being hidden or
silently folded into another bucket.

Alternatives
============

* **Join telemetry to usage** — no shared key exists (usage is a daily
  aggregate, telemetry is per call), and telemetry is purged on its own
  retention schedule, so the join would lose history.
* **Cost estimated from telemetry token counts** — recomputing money
  outside ``UsageMiddleware`` duplicates the pricing logic and would drift
  from the figure the budget enforcement uses.
* **Carrying the operation too** — a finer aggregation key on the cost
  table for a breakdown nobody asked for; can be added later, cannot be
  taken back cheaply once rows exist.

Consequences
============

* Per-extension cost, requests and tokens are answerable from the same
  table and the same range the rest of the dashboard uses.
* Aggregation granularity on ``tx_nrllm_service_usage`` grows: an install
  where several extensions use one model sees more rows per day. Bounded by
  the number of distinct callers, which is the number of installed
  integrations.
* Rows written before this change carry ``''`` and appear as unattributed —
  no migration invents an origin for them.
* Attribution stays honest but unauthenticated (ADR-177): a caller can
  claim any name, so the breakdown is an inventory, not an accounting
  control.
* ``api-surface.txt`` grows additively (one optional parameter).
