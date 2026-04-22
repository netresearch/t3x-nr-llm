..  include:: /Includes.rst.txt

..  _administration-user-budgets:

============================
Per-user AI budgets
============================

The :sql:`tx_nrllm_user_budget` table caps per-backend-user AI spend
independently of the per-configuration daily limits on
:sql:`tx_nrllm_configuration`. A user request must clear BOTH layers:
any limit on the preset they chose AND any limit on their personal
budget record.

..  _administration-user-budgets-what:

What a budget caps
==================

Each row in :sql:`tx_nrllm_user_budget` binds to exactly one
``be_user`` and defines six independent ceilings. ``0`` on any axis
means "unlimited on this axis".

..  list-table::
    :header-rows: 1
    :widths: 30 15 55

    * - Field
      - Unit
      - Reset cadence
    * - Max Requests/Day
      - count
      - Every day at 00:00 server-local time.
    * - Max Tokens/Day
      - count
      - Every day at 00:00 server-local time.
    * - Max Cost/Day ($)
      - USD
      - Every day at 00:00 server-local time.
    * - Max Requests/Month
      - count
      - First of the month, 00:00 server-local time.
    * - Max Tokens/Month
      - count
      - First of the month, 00:00 server-local time.
    * - Max Cost/Month ($)
      - USD
      - First of the month, 00:00 server-local time.

Usage is aggregated on demand from :sql:`tx_nrllm_service_usage` — the
same table the UsageTracker already writes to per request — so there
is no second write per request and no way for a separate counter to
drift away from the source of truth.

..  _administration-user-budgets-create:

Creating a budget
=================

Budgets are plain records on the root page tree (``pid = 0``,
``rootLevel = -1``). Admins create or edit them via the TYPO3 List
module:

1.  Open :guilabel:`Web > List` on any page.
2.  Click :guilabel:`Create new record` at page UID 0 (the root).
3.  Choose :guilabel:`LLM User Budget`.
4.  Pick the backend user, set the ceilings, toggle
    :guilabel:`Enforce this budget` on.
5.  Save.

..  note::
    Only one budget row per backend user. The :code:`be_user` column
    is unique. Re-editing the existing row is the correct way to
    tighten or relax limits.

..  _administration-user-budgets-how-checks-work:

How the check runs
==================

Before dispatching a request the consuming extension calls
:php:`\Netresearch\NrLlm\Service\BudgetService::check()`. The service:

1.  Returns *allowed* when the user has no budget record, when
    :guilabel:`Enforce this budget` is off, or when every ceiling
    is ``0``.
2.  Aggregates today's usage and this month's usage in a single
    database roundtrip.
3.  Evaluates the daily window first; the monthly window only if the
    daily window passes.
4.  Adds ``+1`` request and ``+plannedCost`` to the usage figures
    before comparing, so a user at exactly the limit is still
    allowed one more call.

The returned :php:`BudgetCheckResult` names which bucket was tripped
(``exceededLimit`` as a stable machine key, plus a human-friendly
``reason`` string suitable for log output or caller-side wrapping).

..  important::
    The check is **best-effort**, not a transactionally-safe gate.
    Two concurrent requests for the same user can both pass
    :php:`check()` before either updates
    :sql:`tx_nrllm_service_usage`, temporarily allowing a one-request
    overshoot. Full serialisation would hot-path every AI request.
    If strict enforcement matters, layer a per-user lock on top.

..  _administration-user-budgets-vs-config:

Budgets vs. configuration limits
================================

Both layers persist but cap different things:

..  list-table::
    :header-rows: 1
    :widths: 25 35 40

    * - Axis
      - Configuration daily limits
      - Per-user budgets
    * - Bound to
      - a preset (:sql:`tx_nrllm_configuration`)
      - a backend user (:sql:`tx_nrllm_user_budget`)
    * - Question answered
      - "Can ANY editor keep using this preset today?"
      - "Can THIS editor keep spending this month?"
    * - Windows
      - daily
      - daily AND monthly
    * - Dimensions
      - requests, tokens, cost
      - requests, tokens, cost
    * - Both must pass
      - yes
      - yes

See :ref:`adr-025` for the full design rationale, including the
alternatives (counter table, group-level budgets, auto-throttling)
we considered and why they were rejected.
