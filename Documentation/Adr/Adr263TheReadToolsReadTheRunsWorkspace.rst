.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-263:

================================================
ADR-263: The read tools read the run's workspace
================================================

:Status: Accepted
:Date: 2026-11-05
:Amends: :ref:`ADR-260 <adr-260>` (its consequence "the other read tools still read live only")
:Authors: Netresearch DTT GmbH

Context
=======

:ref:`ADR-260 <adr-260>` let a run started in a draft workspace write into
it, and made ``get_page_content`` read the page as that workspace shows it.
Every other tool that reads a workspace-aware table (``ctrl.versioningWS``)
still read live rows only, so the model searched, listed and paged through
text the editor no longer saw: ``read_records``, ``search_records``,
``get_pagetree``, the file tools on ``sys_file_metadata`` and
``sys_file_reference``, and ``highlight_record``.

Three tools did not keep the rule of commit 61ab69eb3 (another workspace's
drafts are never read) at all:

- ``get_record_history`` read ``sys_history`` by the uid the model named. A
  version row's uid, or the uid of a record new in another workspace,
  returned that workspace's changes, values included. Core's history module
  filters the entries by workspace (``RecordHistory::findEventsForRecord()``,
  TYPO3 14.3.10 lines 465-476) and resolves the live uid to the version in
  the user's workspace (``resolveElement()``, lines 395-406).
- ``get_tsconfig`` accepted any page uid, so a version row's uid fed that
  version's TSconfig into the rootline.
- ``read_fal_asset_meta`` was live only, and so is core's
  ``MetaDataRepository::findByFileUid()`` in the backend; the workspace
  overlay of file metadata happens only for a frontend request
  (``FileMetadataOverlayAspect``, 14.3.10 line 45, the same in 13.4.37).
  ``sys_file_metadata`` is versioned all the same, and the backend edit form
  and the workspace preview show the draft.

The overlay ``get_page_content`` uses — fetch, then
``BackendUtility::workspaceOL()`` each row in PHP — works for one page with a
bounded number of elements. It does not work for a search or a paged list:
a SQL ``LIKE`` over live rows cannot find a term that only a version row
holds, and a ``LIMIT``/``OFFSET`` applied before the overlay counts rows the
workspace deleted. Core's ``WorkspaceRestriction`` with a workspace leaves
the version rows out (it keeps live rows, records new in the workspace and
move pointers) and expects the caller to overlay after the query.

Decision
========

**One row per record, chosen in SQL.** Since TYPO3 11 a workspace version
is a complete row: a change, a move (the version carries the new ``pid``)
and a deletion (``t3ver_state`` 2) are each one row whose ``t3ver_oid`` names
the live record, and a record new in the workspace is one row with
``t3ver_oid`` 0. The rows a user in workspace W sees are therefore a row
predicate (``ReadsTheRunsWorkspaceTrait::shownInWorkspace()``):

- a live row (``t3ver_wsid`` 0, ``t3ver_oid`` 0) that has no version in W,
  checked by a ``NOT EXISTS`` subquery that carries only the deleted
  restriction — a version hidden in W still hides the live row — and
- a row of W itself, version or new record, that is not a deletion.

Every filter, ``LIKE``, enable-field restriction, count, limit and offset is
then applied to what the user sees, in the database. A term only the
version holds finds the record; a term the workspace replaced, hid or
deleted does not; rows of another workspace never match.

**Live uids.** A row is reported under ``t3ver_oid`` where that is set, its
own uid otherwise — the uid the writers address. A ``uid`` filter matches the
record or its version, never a version row by its own uid. A paged query
orders by that live uid.

**Whose workspace.** The acting user's, and only where the run's actor
recorded the same one (as ``get_page_content`` and the writers of
:ref:`ADR-260 <adr-260>` decide it). A run without a recorded workspace, and
every run in live, keeps the previous query unchanged.

**The tools.** ``read_records``, ``search_records``, ``get_pagetree``,
``read_fal_asset_meta``, ``search_fal_files``, ``get_fal_references`` and
``highlight_record`` read the rows the predicate selects. ``get_tsconfig``
accepts a page only where it is a live page, or one the run's workspace
shows. ``get_record_history`` refuses, for every user, a uid that names a
version row or a record of another workspace; on a workspace-aware table it
reads the entries core's history module shows (``workspace`` 0 or the run's
draft workspace), and in a draft workspace adds the entries of the record's
version there.

**Not in scope.** ``get_page_content`` keeps its overlay (it reads one page
and has its own tests). ``site_rag_query`` answers from the published site,
as its indexed_search and ke_search backends can only index the live
frontend. ``resolve_url`` resolves a URL through the site router, which
routes live pages. The tools on ``sys_file``, ``sys_redirect`` and the other
tables without ``versioningWS`` read no versions. ``RecordTextReader`` and
``BatchScopeReader`` serve the field fill and the editor-action batches, not
the chat's read tools.

Consequences
============

✓ In a draft workspace the model lists, searches and pages through the
records the editor sees there, under the uids the writers take.

✓ A version row's uid and another workspace's draft are refused by
``get_record_history`` and ``get_tsconfig``, also for admins and also in
live.

✗ ``get_record_history`` in live now leaves out entries ``sys_history``
stores with a draft workspace, as core's history module does.

✗ ``get_tsconfig`` resolves the TSconfig through the shared
``PageTsConfigReader``, which overlays the rootline with the acting user's
workspace. For a run without a recorded workspace whose user now works in a
draft one, the page check reads live, but the rootline is that user's draft
workspace's.

✗ The ``NOT EXISTS`` subquery runs per candidate row; it uses the
``t3ver_oid`` index core defines on every workspace-aware table.
