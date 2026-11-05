.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-265:

====================================================================
ADR-265: Background work writes into the workspace it was started in
====================================================================

:Status: Accepted
:Date: 2026-11-05
:Amends: :ref:`ADR-237 <adr-237>` (live only: a save in a draft workspace queued nothing), :ref:`ADR-236 <adr-236>` (a batch read its scope in live only)
:Authors: Netresearch DTT GmbH

Context
=======

:ref:`ADR-260 <adr-260>` records the workspace a run was started in on its
actor, and the resolver sets the acting user up there. The work that runs in
the background did not use that, measured on 2026-11-05 against TYPO3 14.3:

- **The field fill** (:ref:`ADR-237 <adr-237>`) queued nothing for a save in a
  draft workspace, and its writer refused a user in a draft. Its write already
  went through the DataHandler (:php:`ToolDataHandler`), so nothing wrote raw.
- **The editor-action batches** (:ref:`ADR-236 <adr-236>`, the module and
  ``nrllm:editor-action:batch``) recorded the editor's workspace on each run
  (the module's actor is frozen from the request's user), but read their scope
  live: the pages of a tree, which records still had an empty field, and the
  page text a run on a page writes from. A page new in the draft was not in
  the batch, a text the draft had written or emptied was not seen.
- **The scheduled batch** set its user up in the workspace
  ``be_users.workspace_id`` named when the command ran, and recorded that
  workspace. A schedule would therefore write into whichever draft its user
  last worked in, once the writers write into a workspace.

Core hands the DataHandler hooks the uid of the **version** when a save in a
workspace changes a live record (``DataHandler::process_datamap()``, the
``$id`` set from ``autoVersionIdMap`` or ``getWorkspaceVersionOfRecord()``
before ``processDatamap_afterDatabaseOperations``, TYPO3 14.3.10 and 13.4.37),
and the uid of the row itself for a record new in the workspace.

Decision
========

**The field fill runs in the workspace of the save.** The planner reads the
record as the saving user's workspace shows it, under its live uid — the uid
the hook names may be the version's — and queues the run for the live uid.
Its actor records the workspace. The page text the model reads is the
workspace's: each content element overlaid with its version there, elements
new or moved there included, those deleted there left out, never another
workspace's draft (the row predicate of :ref:`ADR-263 <adr-263>`). A field
the workspace already holds a value in is not queued.

**The fill writes into that workspace, or nowhere.** The writer resolves the
user in the workspace the actor recorded (:ref:`ADR-260 <adr-260>`) and writes
through the DataHandler as that user: core stores a version, or changes a
record new in the workspace in place. It reads the field as the workspace
shows it before the write and after it. The provenance mark goes on the
version (:ref:`ADR-239 <adr-239>`). Live is not touched, wherever the editor
works when the worker runs. A run that recorded no workspace writes in live
only, as before. A table that keeps no versions (``ctrl.versioningWS``) is
neither planned nor written in a draft workspace: core would write it live
where the workspace permits live editing.

**A batch reads its scope in its workspace.** The module's batch takes the
workspace its actor recorded, where that is the user's (as the read tools of
:ref:`ADR-263 <adr-263>` decide it). Its tree is the tree that workspace
shows — pages new or moved there included, pages deleted there left out —
with page access asked of the live page, as core's page tree asks it. A
record counts as empty when its version there is empty, and the page text a
run on a page writes from is the workspace's. Each run's actor records the
workspace, so the write at approval lands there, whoever approves and wherever
the owner works by then; whether a writer writes into a workspace at all is
the writer's own decision (:ref:`ADR-260 <adr-260>`).

**A schedule runs in live.** ``nrllm:editor-action:batch`` sets its user up in
live and records live, whatever ``be_users.workspace_id`` names. A user who
may not work in live cannot be named.

Consequences
============

✓ An editor in a draft workspace gets the empty fields of a save filled there;
the fill waits in the workspace module, live stays as it was.

✓ A batch started in a draft proposes for what the draft shows, and its
approved writes stay in the draft.

✗ A run is kept by the live uid of its record (``fill:`` and ``batch:``
origins). A fill open in live holds the same field in every workspace until
it ends, and the reverse. A batch record a person decided in one workspace is
not offered again in another.

✗ The fill reads its text when it is queued, as before; a draft rewritten
while the run waits is not read.

✗ A fill of a file's metadata is no case: ``sys_`` tables are not opted in
(:ref:`ADR-237 <adr-237>`).
