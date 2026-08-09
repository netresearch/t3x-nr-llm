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

..  figure:: /Images/backend-analytics.png
    :alt: LLM Usage Analytics dashboard — KPI tiles, a cost and request
        trend chart, breakdowns by provider, model, and service, and a
        per-user table with monthly budget bars
    :class: with-border with-shadow
    :zoom: lightbox

    The Analytics dashboard: KPI summary tiles, the cost/request trend,
    the provider / model / service breakdowns, and per-user consumption
    against each user's monthly budget.

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

..  _administration-analytics-rescues:

Fallback rescues
================

A table lists the runs a **different** configuration answered after the
requested one failed — each line is one request the configuration you
configured did not serve. It shows what was requested and what answered,
each with its provider and model, how many configurations were tried, and
how long the whole run took.

Unlike the rest of this module the list is read from the telemetry log
(``tx_nrllm_telemetry``), not from the usage table, so it also covers
runs that produced no billable usage.

Two things it deliberately does not show:

*   **Runs nobody served.** A chain that was tried and exhausted names no
    serving configuration — it is a failure, not a rescue, and appears in
    the provider health scores instead.
*   **Runs recorded before this feature existed.** Rows written by an
    older version carry no serving configuration and are left out rather
    than guessed at.

A configuration appearing here repeatedly is the signal to look at: its
calls are being answered by a sibling, which may use a different provider,
model and price than the one you selected.

..  _administration-analytics-health:

Provider health and circuits
============================

A table lists every provider that is either configured and active or was
called recently, with its **health score**, the **number of samples** the
score is based on, the **window** those samples were taken over, and the
state of its **circuit breaker**.

Health and circuit state are both keyed by **adapter type**, not by
provider record — two provider records on the same adapter share one score
and one circuit, because it is the provider that is unhealthy, not the
record.

..  list-table::
    :header-rows: 1
    :widths: 25 75

    * - Column
      - Meaning
    * - Score
      - A single 0.00–1.00 number combining success rate and mean
        latency, success rate weighted four times as heavily (see
        :ref:`adr-063`). Higher is healthier.
    * - Samples in window
      - How many runs the score was computed from. Read it before the
        score: 0.90 over two calls and 0.90 over two thousand are
        different statements.
    * - Success rate
      - Share of runs the provider served **itself**. A run a fallback
        rescued counts as a failure of the requested provider.
    * - Avg latency
      - Mean end-to-end time of the self-served runs.
    * - Circuit
      - ``closed`` (normal), ``open`` (failing fast for the cooldown) or
        ``half-open`` (cooldown elapsed, one probe due), plus the current
        consecutive-failure streak.

Unlike the rest of this module the table ignores the date range selected
above. Scores come from a rolling telemetry window (15 minutes by
default, named on the page) and circuit state is live cache state — neither
can be re-cut to a 90-day report period.

..  important::
    **A score only changes something when you switched it on.**
    :guilabel:`Health-Aware Fallback Reorder` (``health.reorderFallback``
    in the extension configuration) is **off by default**. While it is
    off, the scores are diagnostic only: the fallback chain keeps the
    order you configured. The page states which of the two positions the
    switch is in, above the table. The circuit breaker
    (``circuitBreaker.enabled``) is on by default, and the page says so
    when it is not.

A provider with no telemetry in the window shows **no data** — not a score
of zero. It was not called; that is not the same as failing.

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

..  _administration-analytics-list-columns:

Usage columns in the list views
===============================

The Providers, Models, Configurations, and Tasks list views each carry
three extra columns — :guilabel:`Cost (30d)`, :guilabel:`Requests (30d)`
and :guilabel:`Tokens (30d)` — summarising the last 30 days of usage for
that row, so you can spot the heavy hitters without leaving the list.

..  figure:: /Images/backend-models-usage.png
    :alt: Models list with Cost / Requests / Tokens (30d) columns showing
        per-model usage and estimated cost
    :class: with-border with-shadow
    :zoom: lightbox

    The Models list with the 30-day usage columns. Models with no usage
    in the window show blank cells; free local models show ``~$0.00``.

Two attribution notes:

*   The Providers column aggregates by **adapter type** (the value stored
    on each usage row), not by individual provider record — two providers
    that share an adapter therefore show the same figures.
*   The Tasks column relies on per-task tracking: each task execution
    records its ``task_uid`` so usage rolls up to the task that triggered
    it. Calls made outside a task (direct API/service use) are not
    attributed to any task row.

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
