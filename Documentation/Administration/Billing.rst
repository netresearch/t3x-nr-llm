.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

==========================
Monthly billing and export
==========================

..  _administration-analytics-billing:

Monthly billing report
======================

:guilabel:`Monthly billing report`, a button below the range switcher,
opens a page for one calendar month (:ref:`ADR-231 <adr-231>`). It shows
the previous complete month by default; the running month and the twelve
months before it can be selected. A month runs from its first to its last
second in the server timezone, and the page names both. The running month
is marked as not complete.

The page has four parts:

Cost
    Requests, tokens, the estimated cost of the month, the markup in
    percent, the markup amount and the total including the markup.

Active editors
    Every backend user with at least one request in the month, with cost,
    requests and tokens. A user counts as active whatever their account
    state is today; the column :guilabel:`Account state today` shows when
    the user is an administrator, disabled or deleted. Requests made without
    a backend user (command line, scheduler) are listed in a separate row
    and are not counted as an editor, so the rows add up to the month total.
    That row includes the usage of core's command-line user ``_cli_``: since
    TYPO3 14.3.8, console commands such as the scheduler record their usage
    under it.

Entitled to AI Tasks
    Backend users who could use the AI Tasks module, **as of today**: users,
    groups and permissions keep no history, so this list shows today's
    state for every month, and the page says so with the date. A user is
    counted when all of these hold:

    *   the account can log in now: not disabled, not deleted, within its
        start and end time;
    *   the permission :guilabel:`Execute AI tasks` (see
        :ref:`administration-permissions`) is granted through one of the
        user's backend groups or a subgroup of one; hidden and deleted
        groups grant nothing;
    *   the module :guilabel:`AI Tasks` is allowed on the user record or on
        one of those groups.

    Administrators are not counted: they can use every AI feature without
    the permission. The page states how many administrators can log in now,
    leaving out core's command-line user ``_cli_``, which TYPO3 creates as an
    administrator with a random password.
    Extensions that call the AI services with their own permission checks,
    such as an editor assistant or a chat, grant access their own way; users
    whose access comes only from such an extension are not counted here, so
    an active editor is not necessarily entitled to AI Tasks. Do not bill per
    entitled editor without deciding which of these accesses counts.

Results by kind
    Requests, tokens and estimated cost by service type, calling extension
    (see :ref:`administration-analytics-per-extension`) and task.

..  _administration-analytics-billing-markup:

Markup
------

..  confval:: billing.markupPercent
    :name: nrllm-billing-markuppercent
    :type: int
    :default: 0

    A whole percentage from 0 to 1000 the billing report adds to the
    estimated cost of the month. Set it under
    :guilabel:`Admin Tools > Settings > Extension Configuration > nr_llm`.

The markup is added once, to the month total. Stored costs do not change.
The report calculates in micro-dollars, the 6 decimals the cost is stored
with: the markup is rounded half up to one micro-dollar, and cost plus
markup equals the total exactly. The CSV keeps all 6 decimals. The page
shows cents: cost and total are each rounded half up, and the markup line
is the rounded total minus the rounded cost, so the three lines add up.
A value outside 0 to 1000 is clamped and a value that is
not a whole number reads 0; the page then says that the stored value was
not used as it is.

..  _administration-analytics-billing-export:

CSV export
----------

:guilabel:`Download CSV` downloads the selected month as
:file:`nr-llm-billing-YYYY-MM.csv`. Like the page, it is available to
administrators only.

The file is one table, so a spreadsheet imports it in one step. The first
column, ``section``, says what a row is; columns that do not apply to a
section stay empty:

..  list-table::
    :header-rows: 1
    :widths: 30 70

    *   -   ``section``
        -   Row
    *   -   ``total``
        -   Requests, tokens, ``estimated_cost_usd``, ``markup_percent``,
            ``markup_usd`` and ``total_usd`` of the month.
    *   -   ``active_editor``
        -   One per active editor: ``be_user_uid``, ``username``,
            ``real_name``, ``account_state`` (``admin``, ``disabled``,
            ``deleted``, space-separated; empty for an ordinary account)
            and the usage.
    *   -   ``without_backend_user``
        -   The usage recorded without a backend user or under the
            command-line user ``_cli_``, if any.
    *   -   ``entitled_editor``
        -   One per entitled editor, with ``state_as_of``: the date of
            today's state.
    *   -   ``by_kind``
        -   One per combination of ``service_type``, ``source_extension``
            and ``task_uid`` / ``task``, with the usage.

The format: comma-separated, fields in double quotes where needed, CRLF line
ends, UTF-8 without a byte order mark. Numbers have a dot as decimal
separator and no thousands separator; amounts are USD with 6 decimals, and
the column names say the currency. A text cell that starts with ``=``,
``+``, ``-``, ``@``, a tab or a carriage return gets a leading ``'`` so a
spreadsheet does not run it as a formula.
