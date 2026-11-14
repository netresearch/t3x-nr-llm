.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _assignment-scope:

=================================
Binding a run to its assignment
=================================

A run can be bound to the pages and records it was asked to work on. Every
write is then checked against that scope, and a write outside it is refused
before it happens, whoever the acting user is. An administrator's run is held
to its scope like anybody else's.

The scope is the second control of the editorial profile
(:ref:`editorial-profile`). The draft workspace decides where a run writes; the
scope decides what it may write. The decision is in :ref:`adr-295`.

What a scope holds
==================

- **Pages.** A page and a depth. Depth 0 is the page and the records on it,
  depth 1 adds its direct subpages with their records, and so on up to 99.
- **Records.** Single records by table and uid. A record in scope brings its
  translations with it.
- **Tables** (optional). The tables the run may write. Without the list every
  table is allowed.
- **Languages** (optional). The languages the run may write. Without the list
  every language is allowed.

The lists only narrow what the pages and records admit. A page that is created
under a scoped page has to be in scope itself, so a scope of depth 1 does not
admit a page under its last level.

Rows that lie outside the page tree have no page. Redirects, backend users and
file metadata are in scope only by an explicit reference: a scope names a file
(``sys_file:<uid>``) to allow its metadata to be changed, and it can never admit
a new redirect.

Who sets the scope
==================

Whatever starts the run. A starter that builds an acting identity for the run
binds it to a scope before the run starts:

.. code-block:: php

   $actor = AiActorContext::fromBackendUser($user)
       ->withAssignmentScope(AssignmentScope::forPage($pageUid, depth: 1));

The scope is stored with the run, as the workspace is, so a queued, suspended
or resumed run keeps the scope it started with. Nothing the assistant sends can
widen it: a tool argument that names a page or a depth is only the thing to
check against the scope. A starter that sets no scope behaves as before, which
is what the backend chat and the A2A server do today.

What a configuration can require
================================

Two settings on the :guilabel:`Access` tab of a configuration, next to the
draft workspace setting. Neither changes a configuration that has not been
edited.

:guilabel:`Require the scope of an assignment for changes`
   A run of this configuration that has no scope cannot change anything. Every
   write is refused with the reason ``scope_required``. Reads are not affected,
   so the run starts and can answer questions. Switch it on for starters that
   pass a scope; a starter that passes none, the A2A server included, then has
   a configuration that reads only.

:guilabel:`Deepest level below a scoped page`
   A cap, from 0 to 99 (default 99, no cap), on how far below a scoped page the
   run may write, whatever depth its starter asked for. 0 limits a run to the
   page itself and its records. The cap is read for every call, so lowering it
   applies to a run that is waiting for approval.

A write that exceeds the scope is rolled back in the live and in a draft workspace
alike: each call of a write tool runs in one database transaction. What the
rollback cannot take back is listed in the ADR (side effects of hooks that call
out, files); a FAL file operation is refused under a scope. Cache group flushes
of the call wait until it is committed and are dropped when it is rolled back.

What is refused
===============

The scope is checked three times. The first check reads what the tool says it will
touch, before the approval card is shown, so the editor is not asked to approve
a call that is refused. The second check reads what the DataHandler is asked to
write while the call runs, and also catches a write the tool did not state: a
hook of the installation that writes another record, or the subpages a page
delete takes along. Both read input, and refuse a value they cannot read. The
third check reads no input: after the call it looks at the records the
DataHandler reported as written, in the database, and judges each by the same
rules. A violation rolls the whole call back, rows and history included.

.. list-table::
   :header-rows: 1
   :widths: 25 75

   * - Write
     - Refused when
   * - Change a record
     - The record is not in scope, or its table or language is not allowed. An
       inline or file field that lists an existing record outside the scope is
       refused, because listing it moves it to this record.
   * - Create a record
     - Its language is not allowed (a record without a language is in the default
       language), or the page it is created on is not in scope (for a record created after
       another one, the page of that record), or it is a page and would lie
       deeper than the scope reaches.
   * - Move
     - The record or the destination is not in scope. A moved page takes its
       subpages along, so the deepest of them has to be in scope at the new
       position.
   * - Copy
     - The record or the destination is not in scope. A copy of a page that
       takes its subpages along also needs every page below it in scope. The
       built-in copy tool copies the page and its content only.
   * - Delete
     - The record is not in scope. A page that is deleted takes its subpages
       along, so every page below it has to be in scope.
   * - Translate
     - The record is not in scope, or the language of the translation is not
       allowed. A record that is created as a translation of a record outside
       the scope is refused the same way.
   * - Publish, attach a file
     - The record is not in scope.
   * - A tool outside TYPO3
     - Always, while a scope applies (reason ``scope_remote_write``): an MCP
       server or a remote agent cannot be held to a scope.
   * - A write tool that does not state its targets
     - Always, while a scope applies (reason ``scope_unresolvable``). All
       built-in write tools state them.

Clearing a cache is allowed under any scope: a cache is not a record.

What the assistant and the editor see
=====================================

The assistant reads a result that starts with ``Error:`` and says that the
assignment is limited to the named pages and records, which record lay outside
it, and that nothing was changed. The reasons are ``outside_scope``,
``table_not_allowed``, ``language_not_allowed``, ``subtree_outside_scope``,
``scope_required``, ``scope_unresolvable`` and ``scope_remote_write``.

A refusal is also on the run. The ``tool_changes`` step of the call lists the
refused writes (operation, table, uid, page and reason), also for a call that
was refused before it ran and so changed nothing. A call refused before it ran
is in the governance log as ``invocation_denied`` with the rule
``assignment_scope``. A consumer of the run reads the list with
``ObservedChangeSet::tryFromEventPayload()``.

What it does not do
===================

- It does not cover a write that does not go through the DataHandler: a query, a
  file written through FAL, a call to another system. For such a write the first
  check is the only one, and a tool that writes this way and states no targets is
  refused.
- It does not carry over to an A2A client or a task record: neither has a scope
  yet.
- The table list does not reach the content of other tables on a page that is
  deleted: it goes with the page.
- It does not stop a tool from changing a cache, and it does not check reads.

The settings are listed with the other fields of a configuration in :ref:`configuration-llm`.
