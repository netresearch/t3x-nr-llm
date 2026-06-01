..  include:: /Includes.rst.txt

..  _administration-analytics:

============================
Usage analytics
============================

The Analytics submodule turns the per-request data in
:sql:`tx_nrllm_service_usage` into an at-a-glance view of what your AI
spend and usage look like over time — cost and request trends,
breakdowns by provider, model, and service, and per-user consumption
against this month's budget.

..  _administration-analytics-open:

Opening the module
==================

Open :guilabel:`Admin Tools > LLM > Analytics`. The submodule sits next
to the other LLM sections in the left-hand navigation and is
admin-only, like the rest of the module.

..  _administration-analytics-range:

Choosing a date range
=====================

A range switcher at the top of the page selects the reporting window.
The range is a plain ``?range=`` link, so changing it is an ordinary
page reload — there is no AJAX. Four presets are available:

..  list-table::
    :header-rows: 1
    :widths: 20 80

    * - Preset
      - Window
    * - ``7d``
      - The last 7 days (today and the six preceding days).
    * - ``30d``
      - The last 30 days. This is the default — any unknown range
        value falls back to ``30d``.
    * - ``90d``
      - The last 90 days.
    * - ``month``
      - From the first of the current calendar month to today.

..  _administration-analytics-kpis:

KPI tiles
=========

A row of tiles summarises the selected range:

- **Total cost** — the summed estimated cost across the window.
- **Total requests** — the number of AI requests recorded.
- **Total tokens** — prompt plus completion tokens consumed.
- **Providers** — how many distinct providers were used.
- **Models** — how many distinct models were used.

These are totals for the chosen range, not all-time figures.

..  _administration-analytics-trend:

Cost and requests trend
=======================

A line chart plots daily estimated cost and daily request count across
the range. Days with no usage are filled in as zero so the line is
continuous rather than skipping gaps.

..  _administration-analytics-breakdowns:

Breakdown charts
================

Three bar charts split the window's usage along different axes:

- **By provider** — cost and requests per ``service_provider``
  (OpenAI, Anthropic, Ollama, …).
- **By model** — cost and requests per model. This dimension is new:
  it relies on the ``model_uid`` / ``model_id`` columns added to the
  usage table, so it only reflects usage recorded after that change.
- **By service** — cost and requests per service type (chat, vision,
  translation, speech, image).

..  _administration-analytics-per-user:

Per-user table
==============

A table lists usage grouped by backend user, ordered by cost. Each row
shows the user's request count, token total, and estimated cost for the
selected range, plus a **monthly-budget bar** that visualises how much
of their per-user budget (see :ref:`administration-user-budgets`) they
have consumed.

..  note::
    The budget bar always reflects the **current calendar month**,
    regardless of the date range selected above. The other columns
    follow the selected range; the budget bar does not, because a
    budget is a monthly ceiling.

Requests made without an authenticated backend user (CLI, scheduler,
``be_user = 0``) are grouped under a **system** row.

..  _administration-analytics-cost-note:

A note on cost
==============

All cost figures are **estimated**. They are computed from the model
pricing you configured (cents per 1M tokens, applied to the recorded
prompt/completion token split), not billed back from the provider.
Treat them as a planning and trend signal, not as an invoice. Costs are
captured at call time, so they reflect the pricing in effect when each
request ran. See :ref:`adr-029` for the design rationale.

Specialized services (DALL·E, text-to-speech, Whisper, DeepL) still
record their requests and units, but their cost is currently shown as
``0`` — token-based pricing does not apply to them yet. Streaming
responses are not recorded at all, because chunked output has no single
terminal token count to price.

..  _administration-analytics-demo-data:

Demo data for local development
===============================

To populate the module with something to look at during local
development, run the dev-only DDEV command:

..  code-block:: bash

    ddev seed-usage

It generates roughly 90 days of realistic historic usage across
providers, models, services, and users so the trend line, breakdown
charts, and per-user table all have content. This command is for local
DDEV environments only — do not run it against production data.
