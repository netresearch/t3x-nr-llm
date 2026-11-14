.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-295:

====================================================
ADR-295: A run is bound to the scope of its assignment
====================================================

:Status: Accepted
:Date: 2026-11-14
:Amends: :ref:`ADR-293 <adr-293>` (its "Task scope is not part of this change" and the matching revisit trigger), :ref:`ADR-292 <adr-292>` (the ``tool_changes`` step also lists the writes a scope refused)
:Authors: Netresearch DTT GmbH

Context
=======

The editorial profile (:ref:`ADR-293 <adr-293>`) controls where a run writes:
in a draft workspace, never live. It does not control what the run writes. A
run that was asked to rewrite the teaser of one page may change any record its
acting user may change, and an administrator may change all of them. The
approval card is the only control, and a card lists calls, not the assignment.
An external review recommended the missing half: store the scope of the
assignment in the run and check every write against it, so that an otherwise
authorised agent cannot write outside what it was asked to do.

ADR-293 left it out for want of a source. That reason has changed. The run
already carries one identity that is persisted with it and restored by the
queue, a suspended run and a resumed run: the acting identity
(:ref:`ADR-083 <adr-083>`), which holds the workspace the run was started in
(:ref:`ADR-260 <adr-260>`). A starter that builds an ``AgentRunRequest`` (the
backend chat, the A2A server, a queued task, and nr-mcp-agent, which starts its
runs through nr-llm) already builds that identity.

The ``SubjectRecord`` of a guided process is not that source. It states what a
process is about, is set for the first turn of a process only, and nothing
checks a write against it.

The DataHandler is where TYPO3 writes, and its hooks are the only place that
hears of a write while it happens (:ref:`ADR-292 <adr-292>`). A refusal there has
to cover three things a tool's arguments do not: a page command that takes
subpages along, a hook of the installation that writes another record, and the
child records core generates for an inline relation.

Decision
========

**A scope is a value, and it is fixed when the run starts.**
``AssignmentScope`` holds page roots (a page and a depth: 0 is the page and
the records on it, 1 adds its direct subpages, up to 99), explicit record
references (``table:uid``), and optionally the tables and the languages the run
may write. At most 50 pages, 200 records, 50 tables and 50 languages. A scope
that names no page and no record admits nothing (``AssignmentScope::none()``).
``forPage()`` and ``forRecord()`` build the common ones; a starter that holds a
``SubjectRecord`` derives a scope from it with ``forRecord()``.

**A row is in scope** when it is a scoped record, a translation of one, or the
metadata of a scoped file (``sys_file_metadata`` belongs to its ``sys_file``), or
when it lies on a scoped page: its ``pid`` for any table but ``pages``, and for
``pages`` the page itself, taken through its default-language row so that a
German page overlay is the page. A page is in scope when it is a scoped page
or lies within the depth of a scoped page above it. Rows outside the page tree
(``pid`` 0: redirects, file metadata, backend users) are in scope only by an
explicit reference. The tables and languages of a scope only narrow this; they
add nothing. A page put under a page, or moved there, has to be in scope itself,
one level deeper, so a scope of depth 1 does not admit a page under its last
level. The rules live in one class, ``AssignmentScopeChecker``, which both layers
ask; it reads the rows in the workspace of the run, so a page the run created
in a draft is in the tree.

**The scope travels with the acting identity.** ``AiActorContext`` gets
``assignmentScope`` and ``withAssignmentScope()``. It is persisted with the
run exactly as the workspace is, so a queued, suspended or resumed run has the
scope it started with, and the stored form of an actor without one is
unchanged (no key). Three states stay apart: no key is an actor without a
scope; a readable key is that scope; an unreadable key is the scope that admits
nothing. Reading it as no scope would widen the run to everything. The
signature of ``AiActorContext::backendUser()`` gains one optional trailing
parameter, and ``AgentRunRequest`` is unchanged: a starter sets the scope on
the actor it already passes, and a starter that sets none (nr-mcp-agent today)
behaves as before.

**Who sets it.** The starter proposes it, through the actor. A configuration
decides how much to trust that. ``tx_nrllm_configuration.require_assignment_scope``
(off by default) refuses every write of a run that has no scope. And
``assignment_scope_max_depth`` (0 to 99, default 99, no cap) caps how far below a
scoped page the run may write, whatever depth the starter asked for. The scope
a call is held to is computed for that call, as the run's scope capped by the
configuration as it is now (``EffectiveAssignmentScope``), and is never stored:
a setting switched on, or a cap lowered, while the run waits applies to the
call that resumes. A run with a scope is held to it also where the
configuration does not require one, because a starter that narrows its own run
is a use of the feature. "Requires a scope" means every write is refused, not
that the run cannot start: a read needs no scope, and refusing the start would
make a plain question impossible.

Nothing in the output of the model reaches the scope. The rule takes it from
the run, and reads the arguments of a call only as the claim to check.

**Layer one: the tool says what it will touch.**
``RequireAssignmentScopeRule`` (identifier ``assignment_scope``) is the second
built-in invocation rule beside ADR-293's, and like it asked before an approval
card (``PreApprovalInvocationRuleInterface``) and again before the call runs, so
the editor is never asked to approve a call that is refused. A tool that can
state its targets from its arguments implements ``ScopedWriteInterface`` and
returns a ``ScopedWrite``: the existing rows it acts on, the pages it puts new
rows on, whether it reaches the subpages of a page, and the language it writes.
All 23 built-in write tools do. An anchor argument ("insert after this
record") is a record of the claim as well as a page, because the tool puts the new row where the anchor is.
The rule refuses with a reason code:

- ``outside_scope``, ``table_not_allowed``, ``language_not_allowed``,
  ``subtree_outside_scope``: a part of the claim lies outside.
- ``scope_required``: the configuration requires a scope and the run has none.
- ``scope_unresolvable``: a write tool that does not implement the interface,
  or whose arguments name nothing it could act on, or a record that does not
  exist. A tool that needs approval without declaring an effect is a write, as
  for ADR-293; a tool that declares itself a read passes.
- ``scope_remote_write``: a tool that changes things outside TYPO3 (an MCP server,
  a remote agent). A scope cannot be enforced on it, so it is refused while one
  applies, as ADR-293 refuses it in every workspace.

The explanation the model reads names the scope and the first thing that lies
outside it, in the language of the acting user, and says nothing was changed.
The decision carries the refusals as data.

**Layer two: the DataHandler may not write outside it.**
While the loop executes a call with a scope, ``AssignmentScopeGuard`` is armed,
and a global DataHandler hook (``AssignmentScopeHook``) holds every run to the
scope, the runs of nested DataHandlers and of hooks of the installation
included:

- A datamap entry is vetoed in ``processDatamap_preProcessFieldArray``, which
  core documents as able to skip the record and which sees the child records the
  data-map processor generates for inline relations. An existing record must be
  in scope; an inline or file field that lists an existing record (a child it
  would re-parent, written by a query of the relation handler that no datamap
  entry announces) needs that record in scope, and new children are datamap
  entries of their own; the language of a new row is its given language or, when
  omitted, 0; a new translation is judged on the record it translates, because a
  page overlay lies under the parent of its source and a scope of one record has
  no page, and a new ``sys_file_reference`` on the record it is attached to; a new record's ``pid`` is resolved (a page uid, the negative uid of
  the record it goes after, or the placeholder of a record created earlier in
  the same run) and must be in scope; a ``l10n_parent`` or ``l18n_parent`` must
  point at a record in scope; the language of the row must be admitted.
- A command is removed in ``processCmdmap_beforeStart``, before any command
  runs. ``move`` and ``copy`` need the record and the destination in scope,
  ``localize`` and ``copyToLanguage`` the source and the target language,
  ``inlineLocalizeSynchronize`` the source, ``delete``, ``undelete``, ``discard`` and ``version`` the record. A
  ``delete`` of a page needs every page below it in scope as well, because core
  takes subpages along without naming them, and so does a ``copy`` of a page
  made with copy levels above 0 (the user's ``copyLevels``, or ``copyTree`` before
  TYPO3 14). A ``move`` of a page is judged on its subpages too: they move along,
  so the deepest of them has to lie in scope at the new position (the page's new
  parent, or that of the page it goes after, plus the height of its subtree plus
  one). A command this ADR does not know is refused.
- A refusal is recorded on the guard and written to the DataHandler's error log.
  The tool does not decide what the model reads: a tool may report success or
  swallow an error. ``ToolLoopService`` takes the guard on every way out of the
  call, makes a call that had a write refused an error result that lists what
  the scope stopped, and records it. A tool that threw keeps its own error and
  gets the same sentence.

**The input checks fail closed.** Both layers read the input of a write, and a
third of the defects found in review were values the parser had skipped. A value
of an entry that the hook cannot read into a definite target is therefore
refused, never skipped: an inline or file item must be a placeholder of a new
record, a canonical uid (no sign, no leading zero), or ``table_uid`` split at the
last underscore with a table that is in the TCA; a parent pointer or a language
that is present in the entry and not a canonical integer (an empty value is 0) is
refused, and the stored value stands in only for a key the entry does not carry.
A child row is judged on the record it belongs to for every table that is the
child of an inline or file column, found from the ``foreign_field`` and
``foreign_table_field`` of the TCA (``ChildRelations``), not only for file
references.

**The effect check does not read input at all, and its answer is a rollback.**
Every call of a write tool runs inside one database transaction
(``AssignmentScopeTransaction``): ``beginTransaction()`` on the default
connection, or a savepoint when a transaction is already open (the outer one is
then left to its owner). Core's DataHandler opens no transaction of its own
around a datamap or cmdmap run, so it joins the open one: ``DataHandler::process_datamap()``
and ``process_cmdmap()`` contain no ``beginTransaction``, ``commit`` or
``rollBack`` (read in typo3/cms-core 14.3 and 13.4, ``Classes/DataHandling/DataHandler.php``),
and ``ConnectionPool::getConnectionForTable()`` returns the one shared default
connection unless ``DB/TableMapping`` maps a table elsewhere. A mapping to another
connection makes one transaction insufficient, so the call is not run
(``cannot be enforced on this database setup``).
After the call (``AssignmentScopeEffect::violations()``) the loop takes every
record the DataHandler reported as written, moved, copied, localized or deleted
(the change collector's records joined with the DataHandler's own history,
without the limit of the list a run step keeps), reads each from the database as
it now is (final pid, language, translation parent, parent pointer), and judges
it by the rules of the checker. A write that a parser let through is found here,
because the check looks at what was written and not at what was asked. The
verdict is taken before the commit. Any violation rolls the whole call back: rows,
``sys_history``, ``sys_refindex``, ``sys_log`` and workspace versions of the call
are gone, in the live and in a draft workspace alike. A version an editor made
before the call is not part of the transaction and is untouched. The call is an
error that says it was rolled back. Rolling back needs no workspace, so a
configuration that requires a scope no longer requires a draft workspace.
Rows the call's own usage and audit writes made go with the rollback too.

**Cache flushes wait for the commit.** A flush of a cache group truncates
database tables, which MySQL and MariaDB commit on their own: inside the
call's transaction it would end the transaction behind DBAL's back, and a later
``ROLLBACK`` would succeed without undoing anything. The built-in clear-cache
tool therefore hands its group flush to ``AssignmentScopeTransaction::defer()``:
the loop runs it after the commit and drops it when the call is rolled back. So
does the DataHandler a tool starts (``ToolDataHandler::clear_cacheCmd()``), which
matters because core flushes a group by itself: the page TSconfig option
``TCEMAIN.clearCacheCmd`` (``pages``, ``all``) makes the outermost DataHandler
run ``clear_cacheCmd()`` at the end of ``process_datamap()`` and
``process_cmdmap()`` through ``processClearCacheQueue()`` (TYPO3 14.3.11
``DataHandler.php`` lines 1025-1027, 3449-3451, 9067-9095, 9241-9244 and
9288-9312; 13.4.39 lines 1002-1004, 3454-3456, 8894, 9068-9070 and 9115-9132).
Only the outermost instance runs it (``isOuterMostInstance()``, 14.3.11 line
9663, 13.4.39 line 9499), and in a tool that is the tool's own ``ToolDataHandler``.
A flush by tag (``flushCachesInGroupByTags()``) deletes rows and runs in place.
A DataHandler that is not ours and is the outermost one of its run (a
third-party tool that does not use ``ToolDataHandler``) is not covered by the
deferral, only by the guard below. Every call also takes a savepoint at
the start, even the outermost one, because ``ROLLBACK TO SAVEPOINT`` fails
(MariaDB error 1305) when an implicit commit has taken the savepoint with it;
that failure is how a rollback that did not happen is seen. The loop then reports
the call as **not** rolled back (``effectNote``), logs at error level and never
claims a rollback. The ``TRUNCATE`` of a flush that did not go through ``defer()``
(a hook or listener of the installation) is covered by this second guard only.

What a rollback cannot take back, because it is not a database row of that
connection: side effects of PSR-14 listeners and DataHandler hooks that call out
(mail, HTTP, queue messages), files written through FAL, and the transaction held
open during a tool's callout. A FAL file operation (upload, delete, move, rename
of a file) is refused up front under a scope (``ScopedWrite::$writesFiles``,
reason ``scope_files_write``), because it cannot be rolled back; a tool that
changes only ``sys_file_metadata`` or ``sys_file_reference`` rows is a database
write and is covered. The check holds for what the DataHandler reports. It does
not see a row the DataHandler never reported and writes that bypass it: the
parent-side re-parent of an existing inline child
(``RelationHandler::writeForeignField``) is held by layer one and two only, and a
hard-deleted row is gone and unreadable. A check that cannot read what was
written (a database error while reading it) counts as a violation, so the call is
rolled back and the transaction is ended on every way out of the call.

Known limits of the transaction, none of them a write outside the scope that the
checks let through:

- A write tool that declares no effect and needs no approval gets no transaction;
  a violation by such a call is reported as not rolled back.
- A ``DB/TableMapping`` to a connection other than the default one makes the call
  not run: one transaction cannot cover two connections. This is fail-closed and
  an availability loss.
- On PostgreSQL a database error inside the call aborts the transaction, so the
  note of what was written before the failure may name rows that are gone.
  **Unverified**: only SQLite and MariaDB were run.
- A call inside a transaction of its own caller gets a savepoint. Its cache flushes
  wait for the outermost *scoped* call. When that call itself runs inside a
  foreign transaction, they run at its savepoint release, not at the foreign
  commit, and on MySQL and MariaDB the ``TRUNCATE`` of a group flush then ends
  the foreign transaction early.
- A guard or collector frame is taken on every way out of the call, so no scope
  stays armed in a long-lived worker. A dependency-pending exception from a call
  that wrote outside its scope is not honoured: the call is rolled back and the
  error returned instead of suspending the run.
- A non-dependency tool that throws the dependency-pending exception has its
  in-scope writes committed, and the model reads only "failed" without the list
  of what stands. No realistic tool does this.
- After an implicit commit, a rollback that fails also drops the flushes the call
  asked for, so the cache stays stale until the next flush.
- A hook failure of the call (``withHookFailedAfterWrite``) can be reported next
  to "all changes rolled back".
- A move pointer written in another workspace than the run's is judged by its live
  position, because the topology overlays rows only for the run's workspace.
- A failed final commit or savepoint release is reported as an error ("NOT
  committed as such"), because whether the changes were saved is then unknown;
  after an implicit commit they are saved. A savepoint release that fails after a
  successful nested rollback is reported as not rolled back, although it was.
- A cache flush that fails after the commit is logged as a warning; the tool's
  result has already said it flushed.
- The anchor of a move ("after record X") is judged like a moved record: its own
  language and, for a page, its subtree must lie in the scope. A move after an
  anchor with a translation or a deep subtree outside the scope is refused
  although the anchor does not change. This errs on the side of refusing.
- Translations a workspace holds are read for every workspace, so a live
  operation on a record whose only translation exists in a draft workspace is
  judged against that translation. This errs on the side of refusing too.
- A child table that two inline columns of one parent reference with different
  ``foreign_field`` values is read through the first pointer only
  (``ChildRelations``). A write that sets only the second pointer is judged on the
  owner the first one names. No built-in table is built that way.
- ``AssignmentScopeTransaction`` is internal: a second ``commit()`` or
  ``rollBack()`` on one instance is not guarded, and the loop never makes one.
- ``sys_language_uid = -1`` (all languages) is read as a language only where a
  language field carries it, and is refused only when the scope restricts
  languages.

**A refusal is a tool error and a trace event.** The model reads a result that
starts ``Error:`` and names the refused writes and the scope. The run records
the refusals on the ``tool_changes`` step of ADR-292, as a ``refused`` list of
operation, table, uid, pid and reason, also for a call refused by layer one,
which changed nothing: the step is recorded when there is something refused, not
only when something changed. ``ObservedChangeSet::tryFromEventPayload()`` reads
the list; a consumer that does not know the key ignores it, and a payload
without it is as before. The governance log gets ``invocation_denied`` for layer
one as for every rule.

**What catches what.** Every DataHandler operation, and the layer that holds it. The effect check, after the call, holds every row of the table that the DataHandler reports, whatever the input looked like:

.. list-table::
   :header-rows: 1
   :widths: 30 35 35

   * - Operation
     - Layer one (the claim)
     - Layer two (the hook)
   * - ``move``
     - record, destination, anchor, subtree height of a page, translations
     - record, destination (a negative target is its anchor's page; an array
       target is read as core reads it), subtree height, translations
   * - ``copy``
     - record, destination, anchor
     - record, destination, subtree with copy levels above 0; every row core
       copies is a datamap entry of its own
   * - ``localize``, ``copyToLanguage``
     - source and target language
     - source, target language; the new rows are datamap entries
   * - ``inlineLocalizeSynchronize``
     - not offered by a built-in tool
     - source; the rows it creates or deletes are judged one by one
   * - ``delete``
     - record, subtree of a page, translations
     - record, subtree of a page, translations
   * - ``undelete``, ``discard``, ``version``
     - ``publish`` claims the record
     - record (``discard`` also translations); cascades are an open limit
   * - New row (datamap)
     - destination page, anchor, language
     - ``pid`` resolved, language, table; a translation or a file reference is
       judged on its origin or host and must lie where that record lies
   * - Update row (datamap)
     - the record
     - the record, its language, ``l10n_parent``, the host of a file reference,
       the existing children an inline or file field lists (also as
       ``table_uid``)
   * - ``pid`` or ``t3ver_*`` in an update entry
     - not needed
     - ignored by core, so nothing is written
   * - Inline or file child of a new record
     - not needed
     - a datamap entry of its own, checked on its ``pid``
   * - Group, select, MM, flex
     - not needed
     - open limit above

**What each layer catches.** A second layer counts only for what the first
misses, and the first one misses writes it was never told about. These cases
are tested, each against the real DataHandler and the real tool loop:

- A call whose claim is in scope and whose write is not, here a tool that
  names one element and writes another. Layer one lets it through and the
  editor approves it; layer two refuses the write. The same call with only the
  DataHandler hook taken away writes, which is the control.
- A hook of the installation that writes another record through a DataHandler
  of its own while the call writes an element in scope. No claim names that
  write. Same control.
- A page delete that takes subpages beyond the depth of the scope along: the
  tool names one page, and core takes the pages below it. Layer one asks for the
  subtree too (the built-in delete tool states it), so both layers refuse this;
  layer two is what holds when a third-party tool does not state it.

A write that does not go through the DataHandler (a query, a file written
through FAL, an HTTP call) has layer one only: the hook and the effect check hear
only of the DataHandler. A tool that writes that way and
states no claim is refused outright. The DataHandler hook does not see it, and
no check does.

Consequences
============

- ● A run can be limited to the page or record set it was asked to work on, and
  an administrator's run is limited the same, because the test is the scope and
  never the user.
- ● The scope reaches every start of a run, because it rides on the acting
  identity that every start already passes, and survives the queue, a
  suspension and a resume.
- ● A parser gap in the input checks is caught after the call by the effect check,
  and the call is rolled back.
- ● A refused write tells the model what to do and tells the editor where it is
  recorded. Layer one refuses before the card.
- ● nr-mcp-agent and every other starter keep working unchanged; the feature is
  off until a starter sets a scope or a configuration requires one.
- ◑ A rollback undoes database rows only; cache flushes, hook and listener
  side effects and file operations stay (see above), and a FAL file operation is
  refused under a scope.
- ◑ A configuration that requires a scope refuses every write of a starter that
  sets none, A2A included: no A2A client carries a scope yet. Such a
  configuration is for starters that pass one.
- ◑ A third-party write tool has to implement ``ScopedWriteInterface`` to work
  under a scope; until then it is refused, and so is every MCP tool.
- ◑ File metadata, redirects and every other row outside the page tree can be
  written only through an explicit reference, and a new redirect never. A
  scope for an editorial page assignment therefore refuses the redirect and
  file-metadata tools unless it names the file.
- ◑ The table and language lists of a scope do not reach what core deletes along
  with a record: the content of other tables on a page that is deleted goes with
  it, and so do the translations of a default-language record. A record that may
  be deleted is judged as that record.
- ◑ A scope's table list has to name the child tables too: the file-metadata tools
  claim a ``sys_file`` and write ``sys_file_metadata``, the attach tools write
  ``sys_file_reference``. A list that omits them refuses the write.
- ◑ A copy that takes subpages along is judged on the source subtree and the
  destination, not on where the copies of the subpages end up: a copy placed
  deeper than the depth allows for its subpages is not caught. The built-in
  copy tool copies the page and its content only (it pins ``copyLevels`` to 0),
  so it is judged on the page.
- ◑ Group, select and MM relations store a reference on the record itself; the
  record they name is not checked. The opposite side of a bidirectional MM
  (``sys_category.items``) is a row written for a record that may lie outside
  the scope.
- ◑ Inline and file fields inside a flex form are relations like any other and
  are not checked; the hook reads the ``columns`` of the TCA, not the overrides
  of a record type.
- ◑ Core's cascades are judged on the record named, not on what core takes
  along: the children of an inline relation that move, are deleted or are
  restored with their parent (checked only where a child's ``pid`` differs from
  its parent's, which no built-in tool produces), what ``discard`` removes along
  with a record, and the translations of a record in a workspace delete. A
  delete or move that takes translations along is refused when the scope lists
  languages and a translation lies in another one.
- ◑ The ``pid`` and language of a new row are judged as the DataHandler is
  given them. A new row without a language is judged as language 0; core assigns
  the first language the acting user may use, which differs for a user without
  the default language.
- ◑ A refused write that core then follows with its own consequences (a slug
  change that creates redirects or renames subpages outside the scope, an update
  of a default-language record that updates translations in other languages)
  makes the call an error although the part that was in scope landed.
- ◑ A hook that defers a write to after the call (a queue, a shutdown function)
  runs outside the guard.
- ✕ A write outside the DataHandler is held by layer one only.
- ✕ ``clear_cache`` is allowed under any scope. A cache is not a record, and no
  content changes, but it clears caches for more than the scope.

Net Score: -1.5

Revisit when
============

- an A2A client or a task record carries a scope, so that a configuration that
  requires one can be used by them;
- a built-in tool writes into a FAL folder: a folder is a record of ``sys_file_storage`` and
  needs its own membership rule;
- a starter wants the scope to follow the subject record of a guided process,
  which this ADR leaves to the starter.

Alternatives considered
=======================

**Keep the scope on ``SubjectRecord``.** Rejected: it is set for the first turn
of a process only, it holds one record and a language, and the chat does not set
it at all. A starter can derive a scope from it.

**A parameter of ``AgentRunRequest``.** Rejected: the request is rebuilt in the
places that already rebuild the actor, and the scope would have to be copied
through the queue codec, the suspended state and the resume by hand. The actor
is already stored and restored by all of them.

**The tool layer alone.** Rejected: it trusts what a tool says and cannot see
what a hook, a nested DataHandler or core's own cascade writes.

**The DataHandler layer alone.** Rejected: it refuses after the editor approved
the card, it knows nothing of a tool that does not use the DataHandler, and the
refusal would arrive as a tool failure with no explanation.

**Throw from the hook.** Rejected: a tool that catches ``Throwable`` swallows it,
and the loop reports the generic ``tool failed``. Skipping the record and
recording the refusal on the guard leaves the decision to the loop.

**Refuse the start of a run without a scope.** Rejected: a question that
changes nothing would be refused with the writes.
