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

.. _version-0-52-1:

Version 0.52.1 (2026-11-22)
===========================

The scope of an assignment judges a move where the record lay before it, and
the lists a run reports about its writes say when they may be incomplete.

- **Moves under the scope:** a move is judged at the record's position before
  core moves it; a move from outside the scope into it, or of a record that
  cannot be read, rolls the call back (#1443).
- **Change list:** an entry whose row could not be read marks the list as
  possibly incomplete (``changesOmitted``) instead of reading as complete
  (#1441).
- **A2A writes artifact:** a refused block cut at 50 entries says it may be
  incomplete, and writes whose rollback could not be confirmed are no longer
  called rolled back (#1448). ``ObservedChangeSet`` gains the optional
  ``refusedOmitted`` and ``rollbackUnconfirmed`` (additive, ``@api``).
- **PHP 8.6:** listed as supported and tested in CI (#1444).

Details in the repository :file:`CHANGELOG.md`.
