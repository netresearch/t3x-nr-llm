.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-231:

==================================================
ADR-231: A monthly billing report with a markup
==================================================

:Status: Accepted
:Date: 2026-10-28
:Authors: Netresearch DTT GmbH

.. _adr-231-context:

Context
=======

An agency that runs the AI features for a customer bills them monthly. The
Analytics module (:ref:`administration-analytics`) shows usage over a
rolling range, not a calendar month, and has no export. The billing needs
four figures per month: the estimated cost with the agency's markup, the
editors who used the features, the editors who were allowed to, and the
results split by kind. A split per site or tenant is also wanted; usage rows
carry no site today, and recording one needs every calling extension to pass
page context. That is a separate decision.

.. _adr-231-decision:

Decision
========

A second page of the Analytics module, ``billing``, shows one calendar
month; ``exportBilling`` downloads the same month as CSV. Both reuse the
queries of the Analytics page through ``UsageAnalyticsServiceInterface``
and refuse a non-administrator themselves, on top of the module's
``access => admin``.

- **The month** is the calendar month in the server timezone, from the
  first second to the last second of its last day. ``request_date`` is the
  local midnight of the day a request ran, and the queries compare
  inclusively, so the first second of the next month would pull that day in.
  The default is the previous complete month; the running month and the
  twelve before it are offered.
- **The markup** is the extension setting ``billing.markupPercent``, a whole
  percentage from 0 to 1000, default 0. It is added once to the month total,
  in micro-dollars, rounded half up; stored costs never change. A value
  outside the range is clamped and a value that is not a whole number reads
  0, and the page says so.
- **Active editors** are the backend users with at least one request in
  the month, whatever their account state is today; the state is shown.
  Requests without a backend user (command line, scheduler) are a separate
  row, not an editor; so is the usage of core's command-line user
  ``_cli_``, under which console commands record usage since TYPO3 14.3.8.
  The page shows cost and total rounded to cents and the markup as their
  difference, so the displayed lines add up; the CSV keeps 6 decimals.
- **Entitled editors** are the users who can log in now, hold the
  ``tasks_use`` grant (:ref:`ADR-130 <adr-130>`) through a group or a
  subgroup, and have the ``nrllm_aitasks`` module (:ref:`ADR-131 <adr-131>`)
  through the user record or a group. Groups are resolved by core's
  ``GroupResolver::findAllUsersInGroups()`` for both the grant and the
  module, with its restrictions. Administrators are not counted,
  their number is stated. Users and groups keep no history, so this is
  always today's state, labelled with today's date.
- **Results by kind** group requests, tokens and cost by service type,
  calling extension (:ref:`ADR-178 <adr-178>`) and task.
- **The CSV** is one table with a ``section`` column, so a spreadsheet
  imports it in one step: RFC 4180, comma, CRLF, UTF-8 without a byte order
  mark, dot decimals, amounts with 6 decimals in USD named in the column
  header. Text cells that would start a formula get a leading ``'``.

.. _adr-231-consequences:

Consequences
============

- A site dimension is one more column in the "by kind" grouping and one
  more CSV column, once usage rows carry a site.
- "Entitled" covers nr-llm's own permission only. An extension that calls
  the AI services with its own checks is not covered, so an active editor
  need not be entitled.
- The figures are the estimates of :ref:`ADR-029 <adr-029>`, not provider
  invoices.

.. _adr-231-alternatives:

Alternatives considered
=======================

- **Markup per row in the export.** Rounding per row makes the rows not add
  up to the total; the markup is a property of the invoice, so it sits on
  the total row only.
- **Separate CSV files per section.** A billing spreadsheet would need
  several imports; one table with a section column needs one.
- **Entitlement by the grant alone.** A user with the grant but without the
  module has no page to run a task from, so counting them would overstate
  what the customer pays for.
