.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-248:

=============================================================
ADR-248: An extension reads its own usage through public API
=============================================================

:Status: Accepted
:Date: 2026-11-03
:Authors: Netresearch DTT GmbH

Context
=======

A consuming extension names itself on every call with
``withCallerSource($extensionKey, $operation)``
(:ref:`ADR-177 <adr-177>`, :ref:`ADR-178 <adr-178>`). nr_llm stores the name in
two places: the daily cost aggregate ``tx_nrllm_service_usage``
(``source_extension``) and the per-call telemetry ``tx_nrllm_telemetry``
(``source_extension``, ``source_operation``, ``success``). The Analytics module
shows cost per extension to administrators.

The cowriter and the AI chat each get an overview module that has to say what
the extension cost and how often each of its actions ran and failed. The
services that read those tables, :php:`UsageAnalyticsService` and the telemetry
repository, are internal (:ref:`ADR-127 <adr-127>`). Reading the tables from the
consumer would tie two other extensions to a schema that is not covered by
semantic versioning.

Decision
========

:php:`ExtensionUsageReaderInterface` is public API (``@api``). It answers for
one source extension and a period, optionally narrowed to one backend user:

- :php:`totals()` — estimated cost, requests and tokens from the daily
  aggregate, the figures the Analytics module shows per extension.
- :php:`operations()` — calls and failed calls per operation, with the time of
  the newest call, from the telemetry.
- :php:`coverage()` — whether telemetry is switched on, its retention in days
  and the oldest stored call, so a caller can say from when the per-operation
  counts are complete.

It is a new interface rather than new methods on
:php:`UsageTrackerServiceInterface`: adding a method to a public interface
breaks every class that implements it.

Consequences
============

- The reader returns installation-wide figures when no user is given. Whether
  the viewer may see them is the caller's decision: the overviews show them to
  administrators and give editors their own figures only.
- Per-operation counts end where telemetry ends. A period that starts before
  :php:`TelemetryCoverage::$oldestCallAt` is incomplete, and a caller says so
  rather than presenting the sum as the whole.
- No breakdown by user name: the per-user table stays in the administrator-only
  Analytics module.
