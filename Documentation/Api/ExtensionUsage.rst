.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _api-extension-usage:

==========================
An extension's own usage
==========================

:php:`ExtensionUsageReaderInterface` reads what one calling extension spent
and how often each of its operations ran and failed (:ref:`ADR-248 <adr-248>`).
The extension is the name it passes to ``withCallerSource()``.

.. code-block:: php

   use Netresearch\NrLlm\Service\Usage\ExtensionUsageReaderInterface;

   final readonly class MyUsageReader
   {
       public function __construct(
           private ExtensionUsageReaderInterface $usage,
       ) {}

       public function lastThirtyDays(?int $onlyThisUser): array
       {
           $to   = new \DateTimeImmutable();
           $from = $to->modify('-30 days');

           return [
               'totals'     => $this->usage->totals('my_extension', $from, $to, $onlyThisUser),
               'operations' => $this->usage->operations('my_extension', $from, $to, $onlyThisUser),
               'coverage'   => $this->usage->coverage(),
           ];
       }
   }

:php:`totals()`
   :php:`ExtensionUsageTotals` with :php:`$estimatedCost` (US dollars, the
   recorded estimate), :php:`$requests` and :php:`$tokens`, from the daily
   usage aggregate. These are the figures the Analytics module shows per
   extension.

:php:`operations()`
   One :php:`OperationUsage` per operation name, most calls first:
   :php:`$operation`, :php:`$calls`, :php:`$failedCalls` and
   :php:`$lastCallAt` (Unix time). Read from the per-call telemetry.

:php:`coverage()`
   :php:`TelemetryCoverage`: :php:`$enabled` (the ``telemetry.enabled``
   setting), :php:`$retentionDays` (after which the purge command deletes
   rows) and :php:`$oldestCallAt`, the oldest stored call or ``null``.
   Operation counts for a period that starts before it are incomplete.

Pass a backend user uid to narrow every figure to that user. Without one the
figures cover the whole installation; show them only to someone allowed to
see them.
