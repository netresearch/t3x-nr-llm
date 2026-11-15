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

.. _version-0-48-0:

Version 0.48.0 (2026-11-15)
===========================

A2A streams, in both roles (spec 051 stage 5a, ADR-283). Run the TYPO3
database schema update after updating.

- **Server:** ``SendStreamingMessage`` and ``SubscribeToTask`` answer with
  Server-Sent Events read from the task's event journal: the task first, then
  only later stored changes. An answer leaves whole and only once the run has
  finished. A stream closes at a terminal state or ``INPUT_REQUIRED``, ends
  after ``a2a.server.streamMaxSeconds`` and never cancels its task.
- **Settings:** ``a2a.server.streaming`` (on by default) switches the card's
  ``streaming`` and both methods; streams have their own capacity per task,
  per client and per installation. A stream holds a PHP-FPM worker while it is
  open, and the proxies in front of it must pass ``text/event-stream`` on as it
  is written; nginx with FastCGI buffering on is tested.
- **Client:** ``A2aClient::sendStreamingMessage()``,
  ``A2aClient::subscribeToTask()`` and ``nrllm:a2a:send --stream`` read the
  stream of an agent whose card declares it. A delegating run keeps polling.

Details in the repository :file:`CHANGELOG.md`.
