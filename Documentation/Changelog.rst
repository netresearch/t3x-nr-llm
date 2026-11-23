.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _changelog:

=========
Changelog
=========

This page summarizes the latest release. The development branch also includes
unreleased changes: inspect the `pending changelog fragments
<https://github.com/netresearch/t3x-nr-llm/tree/main/changelog.d>`__ before
assuming a behavior is available in a tagged package.

The `complete changelog
<https://github.com/netresearch/t3x-nr-llm/blob/main/CHANGELOG.md>`__ is the
canonical release ledger. The manual's older highlights are selective and
may omit intermediate versions.

During 0.x, a minor release may introduce breaking changes; patch updates
remain within the minor series. Review migration notes before upgrading.

.. toctree::
   :maxdepth: 1

   Changelog/Index

.. _version-0-53-0:

Version 0.53.0 (2026-11-23)
===========================

nr-llm reports its AI work as OpenTelemetry spans and metrics to an
installation that registers an OpenTelemetry SDK.

- **Spans:** one per model attempt (streams included), per fallback chain,
  per tool execution (``load_skill`` included) and per agent run segment,
  named after the GenAI semantic conventions v1.41.0 (ADR-291). A tool span
  ends on the call's final result, after the assignment scope has judged
  what it wrote.
- **Metrics:** ``gen_ai.client.operation.duration``,
  ``gen_ai.client.token.usage`` and
  ``gen_ai.client.operation.time_to_first_chunk``.
- **Privacy:** prompts, answers, tool arguments and results, exception
  messages and URLs are never recorded.
- **Upgrading:** nr-llm now requires ``open-telemetry/api``. Without a
  registered SDK nothing is emitted; the setting ``telemetry.openTelemetry``
  switches the instrumentation off. See :ref:`ADR-291 <adr-291>` and
  :ref:`administration-opentelemetry`.

Details in the repository :file:`CHANGELOG.md`.
