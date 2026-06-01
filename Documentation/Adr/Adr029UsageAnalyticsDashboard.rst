.. include:: /Includes.rst.txt

.. _adr-029:

==========================================
ADR-029: Usage Analytics Dashboard
==========================================

:Status: Accepted
:Date: 2026-06-01
:Authors: Netresearch DTT GmbH

.. _adr-029-context:

Context
=======

``tx_nrllm_service_usage`` has recorded request counts and token totals
per service type and provider since day one, and the per-request cost
*column* (``estimated_cost``) existed from the start. The plumbing to fill
it never did: :php:`UsageMiddleware` always passed a null cost,
:php:`Model::estimateCost()` had zero callers, and so every row carried
``estimated_cost = 0.000000``. The downstream effect was visible — the
*AI cost this month* dashboard widget (see :ref:`adr-024`) summed a column
that was structurally always zero and showed ``$0`` regardless of real
spend.

The table also had no model dimension. Usage could be sliced by provider
and service type, but not by the specific model that produced it, so a
``gpt-4o`` call and a ``gpt-4o-mini`` call against the same provider were
indistinguishable in the data — even though their pricing differs by an
order of magnitude.

Reporting itself was thin. The only at-a-glance surfaces were the two
global dashboard widgets from :ref:`adr-024`; there was no dedicated view
that combined cost trends, model-level breakdowns, and per-user
consumption. With usage now flowing through the middleware pipeline
(:ref:`adr-026`), there is a single, well-defined place to compute cost as
a side effect of every productive provider call.

.. _adr-029-decision:

Decision
========

Ship a read-only usage analytics module backed by a richer usage table and
real cost computation:

1. **Schema.** Add ``model_uid``, ``model_id``, ``prompt_tokens``, and
   ``completion_tokens`` to ``tx_nrllm_service_usage``. Daily granularity
   is kept — rows still aggregate per day — and ``model_uid`` joins the
   aggregation key (alongside ``service_type``, ``service_provider``, and
   ``request_date``) so model-level usage rolls up without a second write
   per request.

2. **Cost computation.** :php:`UsageMiddleware` now derives
   ``estimated_cost`` from the configuration's :php:`Model` pricing via
   :php:`Model::estimateCost()`, using the prompt/completion token split
   recorded on the usage object. Pricing is stored as cents-per-1M tokens;
   the estimate is the per-side token count times its rate. When a caller
   already supplies a cost it is preserved; otherwise the model-derived
   value is recorded. This fixes the long-standing
   always-zero-cost defect.

3. **Read layer.** Add :php:`UsageAnalyticsService`, a read-only reporting
   service over the usage table. It exposes KPI totals
   (:php:`getKpiTotals`), a daily cost/requests trend with filled gaps
   (:php:`getDailyTrend`), breakdowns by provider, model, and service
   (:php:`getBreakdownByProvider` / :php:`getBreakdownByModel` /
   :php:`getBreakdownByService`), and per-user usage with this-month budget
   consumption (:php:`getPerUserUsage`). A small :php:`AnalyticsPeriod`
   value object normalizes the date-range presets ``7d`` / ``30d`` /
   ``90d`` / ``month`` and defaults unknown values to ``30d``.

4. **Backend submodule.** Register ``nrllm_analytics`` as an admin-only
   child of the main LLM module (:guilabel:`Admin Tools > LLM > Analytics`),
   driven by :php:`AnalyticsController` and a Fluid template: KPI tiles, a
   cost-plus-requests trend line, provider / model / service breakdown bar
   charts, and a per-user table with monthly-budget bars. The active range
   is a plain ``?range=`` GET parameter — the page is a full reload with no
   AJAX. Charts render with Chart.js (vendored under
   :file:`Resources/Public/JavaScript/Vendor/`).

5. **Demo data.** Ship a dev-only ``ddev seed-usage`` generator that
   populates roughly 90 days of realistic historic usage so the module and
   widgets have something to show during local development.

.. _adr-029-consequences:

Consequences
============
**Positive:**

- ●● Real cost reporting. ``estimated_cost`` reflects actual model
  pricing, so the *AI cost this month* widget (:ref:`adr-024`) and the new
  module both show real figures instead of ``$0``.
- ● Model-level breakdowns. The added ``model_uid`` / ``model_id`` columns
  let usage and cost be sliced per model, not just per provider.
- ◐ A single dedicated reporting surface combines trend, breakdowns, and
  per-user consumption that previously had no home.

**Negative:**

- ◑ One extra write column-set per request (``model_uid``, ``model_id``,
  ``prompt_tokens``, ``completion_tokens``). Negligible — the row was
  already being written; this widens it, it does not add a second write.
- ✕ Specialized-service cost and streaming usage are out of scope for v1
  and documented as such. DALL·E / TTS / Whisper / DeepL still record
  requests and units but their cost stays ``0`` (no token-based pricing
  model yet), and streaming responses are skipped by the usage middleware
  because chunked output has no single terminal token count to price.
- ◑ No backfill of pre-migration rows. Rows written before the schema
  change keep ``model_uid = 0`` and ``estimated_cost = 0``; analytics only
  reflect cost from the migration forward.

**Net Score:** +3 (Positive)

.. _adr-029-alternatives:

Alternatives considered
=======================

* **Per-request (non-aggregated) rows** to enable arbitrary slicing.
  Rejected — daily aggregation keyed on
  ``service_type / service_provider / request_date / model_uid`` keeps the
  table small and the existing widget queries fast; the model dimension is
  the only slice that was actually missing.
* **Compute cost lazily in the read layer** from stored token counts and
  current model pricing. Rejected — pricing drifts over time, so cost must
  be captured at call time against the pricing in effect then. Storing
  ``estimated_cost`` at write time is the durable record.
* **A third dashboard widget** instead of a dedicated module. Rejected —
  the dashboard widget shapes (:ref:`adr-024`) cannot host a trend line,
  multiple breakdown charts, and a per-user table together; those belong in
  a full module view.
