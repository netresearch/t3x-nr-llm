.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

..  _administration-analytics:

===============
Usage analytics
===============

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

Open :guilabel:`AI > Operation > Analytics`. The submodule sits next
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

These are totals for the chosen range, not all-time figures. From one
million on, the request and token counts are shortened in the backend
language ("17,5 Mio.", "17.5M"); the full number is shown on hover and
read out by screen readers.

..  _administration-analytics-trend:

Cost and requests trend
=======================

A line chart plots daily estimated cost and daily request count across
the range. Days with no usage are filled in as zero so the line is
continuous rather than skipping gaps. The points of the chart add up to
the cost and request tiles above it: every row of a day counts towards
that day, also when one day holds rows under several ``request_date``
values (for example local midnight next to a seed written at the
database server's midnight).

..  _administration-analytics-breakdowns:

Breakdown charts
================

Four bar charts split the window's usage along different axes:

- **By provider** — cost and requests per ``service_provider``
  (OpenAI, Anthropic, Ollama, …).
- **By model** — cost and requests per model. This dimension is new:
  it relies on the ``model_uid`` / ``model_id`` columns added to the
  usage table, so it only reflects usage recorded after that change.
- **By service** — cost and requests per service type (chat, vision,
  translation, speech, image).
- **By extension** — cost and requests per calling extension, see
  :ref:`administration-analytics-per-extension`.

..  _administration-analytics-per-extension:

Per-extension table
===================

A table lists cost, requests and tokens per **calling extension**, ordered
by cost. It answers which piece of software spent what: a consumer names
itself with ``AbstractOptions::withCallerSource()`` (:ref:`ADR-177
<adr-177>`), and that name is stored on the usage row alongside the money
(:ref:`ADR-178 <adr-178>`).

Where the entries come from:

- The compatibility layer `nr-llm-compat
  <https://github.com/netresearch/t3x-nr-llm-compat>`__ tags every call it
  reroutes, so an intercepted third-party extension (``ai_filemetadata``,
  ``texter``, ``ns_t3ai``, …) shows up under its own extension key.
- Any other consumer that annotates its calls appears the same way.
- Everything else — wizard tasks, scheduler runs, playground calls and any
  consumer that does not annotate — is listed as **Unattributed**. That is
  the normal state, not an error.

..  note::
    The name is what the caller claims. Attribution is an inventory of the
    installation, not an access control: a caller can name itself anything,
    and nothing verifies it. Rows written before the column existed are
    unattributed — no migration invents an origin for them.

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
``be_user = 0``) are grouped under a **system** row. Since TYPO3 14.3.8 a
console command that authenticates, the scheduler included, records its
usage under core's command-line user ``_cli_`` instead, and this table
lists that user by name.

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

At most the 200 newest rescues of the period are listed. The limit counts
rescues, not failed attempts, so a long outage — which writes one row per
request — cannot push the rescues out of the list.

A configuration appearing here repeatedly is the signal to look at: its
calls are being answered by a sibling, which may use a different provider,
model and price than the one you selected.

..  _administration-analytics-cost-note:

A note on cost
==============

All cost figures are **estimated**. They are computed from the model
pricing you configured (cents per 1M tokens, applied to the recorded
prompt/completion token split), not billed back from the provider.
Treat them as a planning and trend signal, not as an invoice. Costs are
captured at call time, so they reflect the pricing in effect when each
request ran. See :ref:`adr-029` for the design rationale.

Specialized services record their own units: images, characters or audio
duration. Supported OpenAI image/speech/transcription estimates use configured
model pricing or the bundled catalogue as applicable. An unknown/unmapped
service cost can be stored as ``0``; this is not evidence of a free call or
a complete provider invoice. See :ref:`adr-032`.

Configuration-bound streaming records usage through the streaming dispatcher,
which also writes telemetry when enabled. The dispatcher estimates tokens
from prompt and completion size and prices them using the configured model.
These are local estimates, not the provider's final token counts. See
:ref:`developer-streaming` for the streaming contract.

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
    Numbers follow the backend user's language: a German backend shows
    ``~0,00 $`` and ``5.138.706``. Amounts are always USD.

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

    ddev seed-demo

It generates roughly 90 days of realistic historic usage across
providers, models, services, and users so the trend line, breakdown
charts, and per-user table all have content. This command is for local
DDEV environments only — do not run it against production data.
For a calendar-month report, export and markup, open
:ref:`administration-analytics-billing`. Health and circuit state use their
own rolling window; see :ref:`administration-analytics-health`.


.. toctree::
   :maxdepth: 1

   Billing
   ProviderHealth
