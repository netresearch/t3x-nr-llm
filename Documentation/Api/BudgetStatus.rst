.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _api-budget-status:

=========================
An editor's budget status
=========================

:php:`UserBudgetStatusService` reads one backend user's own usage today and
this month against the limits of their budget record, the numbers the AI
Tasks module shows (:ref:`ADR-232 <adr-232>`). It reads the same windows and
the same record that :php:`BudgetServiceInterface::check()` compares: today
from 00:00, this month from the first at 00:00, both up to now.

.. code-block:: php

   use Netresearch\NrLlm\Service\Budget\UserBudgetStatusService;
   use Netresearch\NrLlm\Service\Formatting\LocalizedNumberFormatter;

   public function __construct(
       private readonly UserBudgetStatusService $budgetStatus,
   ) {}

   // $beUserUid: the logged-in backend user, never a request value
   $status = $this->budgetStatus->forUser(
       $beUserUid,
       LocalizedNumberFormatter::forBackendUser(),
   );

Obtain the service from the DI container; its constructor is not part of the
promise. Pass the uid of the logged-in backend user. A uid of 0 or below
reads no usage, because ``be_user = 0`` holds the usage of every caller
without a backend user (command line, scheduler).

:php:`UserBudgetStatus`
   :php:`$hasPersonalLimit` is true only when enforcement applies the
   record: it exists, is active and sets at least one limit above 0.
   Otherwise every limit reads 0 and the usage is still filled in.
   :php:`$today` and :php:`$month` are :php:`BudgetWindowStatus` objects.

:php:`BudgetWindowStatus`
   :php:`$requests`, :php:`$tokens` and :php:`$cost`, one
   :php:`BudgetMeter` each.

:php:`BudgetMeter`
   :php:`$used` and :php:`$limit` as numbers; a limit of 0 means no limit on
   this axis (:php:`isLimited()` is false). :php:`getPercent()` is the share
   of the limit used, 0 to 100, rounded down. :php:`getUsedFormatted()` and
   :php:`getLimitFormatted()` format with the formatter passed to
   :php:`forUser()`: cost as USD with two decimals, counts without.

:php:`toArray()` on each of them returns the formatted values, for a JSON
answer: ``hasPersonalLimit``, then ``today`` and ``month``, each with
``requests``, ``tokens`` and ``cost`` as
``{used: string, limit: string, limited: bool, percent: int}``.

:php:`LocalizedNumberFormatter` formats numbers and USD amounts in a locale
through ICU. :php:`forBackendUser()` takes the locale of the current backend
user and falls back to ``en-US`` without one; ``new
LocalizedNumberFormatter('de-DE')`` fixes one. Amounts stay in USD, the
currency budgets and prices are stored in.
