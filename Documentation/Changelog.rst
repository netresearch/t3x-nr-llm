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

.. _version-0-47-0:

Version 0.47.0 (2026-11-14)
===========================

A run can be bound to the pages and records it was asked to work on, and every
write that goes through the DataHandler is checked against that scope, whoever
the acting user is (ADR-295). Run the TYPO3 database schema update after
updating.

- **Scope of an assignment:** a starter sets the scope on the acting identity
  with ``AiActorContext::withAssignmentScope()``: page subtrees, single records
  and optionally the tables and languages the run may write. Nothing the model
  sends widens it.
- **Three checks:** a write tool states what it touches before the approval
  card, a DataHandler hook refuses a write outside the scope while the call
  runs, and after the call the loop reads back what was written. A write found
  by the third check rolls the whole call back in one database transaction, in
  the live and in a draft workspace.
- **Settings:** a configuration can require a scope
  (``require_assignment_scope``) and cap its depth
  (``assignment_scope_max_depth``). Both are off by default.
- **Limits:** a rollback takes back database rows, not files or what a hook
  calls out to; a write that bypasses the DataHandler has the first check only.
  The full list is in ADR-295.

Details in the repository :file:`CHANGELOG.md`.
