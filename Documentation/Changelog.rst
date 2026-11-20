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

.. _version-0-52-0:

Version 0.52.0 (2026-11-20)
===========================

The scope of an assignment now also holds the listeners of a call's write
events, judges a discard and a publish by what they change, and rolls back a
call whose delete it could not read.

- **Listeners under the scope:** for a run with an assignment scope,
  ``AfterAiRecordWrittenEvent`` and ``AiLabelWriteEvent`` are dispatched
  before the commit with the scope armed; a listener's DataHandler write
  outside the scope rolls the whole call back, unless a
  ``TCEMAIN.clearCacheCmd`` group flush on MySQL or MariaDB committed it
  early (#1438). AI Label's metadata
  on the call's draft stays inside the scope, and a translation no longer
  gets a version from it when the write synchronises nothing.
- **Discard and publish:** each discard and publish core carries out,
  including the drafts discarded along with a record, is judged by its live
  row and its draft before core runs it (#1442).
- **Unreadable rows:** a delete or undelete whose row the scope cannot read
  rolls the call back instead of being committed unjudged (#1434).
- **Breaking for listeners:** see "Upgrading from 0.51" in the
  :file:`CHANGELOG.md`. A listener that has to write outside the scope wraps
  that write in ``WriteOutsideTheAssignmentScope::run()`` (new, ``@api``).

Details in the repository :file:`CHANGELOG.md`.
