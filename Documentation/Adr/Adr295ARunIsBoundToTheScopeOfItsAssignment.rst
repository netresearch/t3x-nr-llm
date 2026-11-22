.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-295:

====================================================
ADR-295: A run is bound to the scope of its assignment
====================================================

:Status: Accepted
:Date: 2026-11-14
:Amended: 2026-11-15 by :ref:`ADR-296 <adr-296>` (the A2A page metadata skill
   is the first starter that sets a scope)
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

**Translations core synchronises** (amending this decision, 2026-11-18). Core's
data-map processor (``DataMapProcessor``, run by ``process_datamap()`` after the
``processDatamap_beforeStart`` hooks and before any entry is written) adds an
entry for every translation of a record a run updates. The entry holds the
fields core keeps equal in all languages (``l10n_mode`` ``exclude``, except type
``none``, and ``allowLanguageSynchronization``) and ``l10n_state``, whether the
run changed them or not; in a draft workspace it would also give the
translation a version of its own. Under a scope of the default language only,
the hook refused that entry for its language, so every write to a page with a
translation was an error, although the page's draft was stored. The A2A
acceptance of the page metadata skill on a page with a German translation
showed exactly that: the draft of the page was stored, the call failed with
``language_not_allowed`` on the translation, and the ``writes`` artifact said
that no write was executed.

The hook now keeps the datamap as the run submitted it, in
``processDatamap_beforeStart``, and judges by it:

- An update that names a synchronised field reaches the translations, so the
  languages of all translations of the record, and of the rows translated
  from it (``l10n_source``), have to be in scope, as for a delete or a move
  (``language_not_allowed`` on the record, read through its live row). This
  errs on the side of refusing: a translation whose ``l10n_state`` keeps the
  field custom would not be changed, an update that repeats the stored value
  is judged by the field's name, and a translation row that is deleted or
  belongs to another workspace counts too.
- An entry core added is skipped, without a refusal, where the scope refuses
  it, when the run submitted nothing for that record, the record is a
  translation, the run submitted the record it translates or the one it was
  translated from, no such submitted entry names a synchronised field, the
  added entry holds nothing but synchronised fields and ``l10n_state``, and no
  inline or file field in it lists a child. The run asked for nothing of it.
  The translation is then not written and gets no version; a value of a
  synchronised field that already differed from its source stays as it was.
  Anything else is judged as before. The skip leaves the entry's own write
  out and cannot let a run write anything through it.
- Known limit: for a synchronised inline or file field (``media``,
  ``og_image`` and ``twitter_image`` of a page) core copies or localises the
  children inside its processor, before any entry is judged. An added entry
  that lists children is therefore judged as before, so a write to a page
  with a file in such a field, on the page or on a translation that follows
  it, is still refused under a scope of the default language only.
- The submitted datamap is the one this hook sees in
  ``processDatamap_beforeStart``: a ``processDatamap_beforeStart`` hook of an
  extension registered after nr_llm that adds a synchronised field is not in
  it, and the entries core adds for that field are then left out without a
  refusal: the record keeps the new value and its translations do not.
  Nothing outside the scope is stored that way.

**What stands of a failed call is reported** (same amendment). A call that had
a write refused commits the rest of what it wrote, and a tool that fails after
it wrote commits what it wrote; the model reads both in the call's error. The
A2A ``writes`` artifact read only announced writes, which a failed call has
none of, and said "No write was executed." beside a stored draft. It now also
lists, in a block of its own, the records the ``tool_changes`` step of each
failed call names, worded so that it does not claim they are stored (a call
whose commit failed has its writes listed although they may be gone), and
says so when such a step is not the whole list (a capture that missed writes,
or a list cut at its limit). A call that was rolled back has an empty
``tool_changes`` step and lists nothing. A dependency tool whose commit fails
drops its change list, so its writes are not named there.

**What the listeners of a write store is reported** (amending this decision,
2026-11-18, #1432). The page metadata skill on the demo stored the page's
draft and its ``writes`` artifact named only the page, but the client's
workspace also held a version of the page's German translation, with no
changed value. The skill's own write does not create it: the entry core adds
for the translation is left out as described above, and a functional test
without other extensions confirms it. AI Label, installed on the demo, stores
its metadata on the draft after the call, with a DataHandler run of its own
(``RecordAiLabelWrite`` on ``AiLabelWriteEvent``). That run is a plain run of
core, and core gives every translation the user may edit a version there: a
plain DataHandler write of the page's description by the same user in the same
workspace, with nothing armed, does the same on TYPO3 14.3.12 and 13.4.40.
AI Label's write names the page's version, and only 14.3 adds the
translations' entry to such a write (13.4.40 does not), so on 13.4 the
translation gets no version. Because core itself versions the translation,
nr_llm does not suppress it; it reports it.

- ``ToolLoopService`` arms the change collector around the dispatch of the
  write events (``AfterAiRecordWrittenEvent``, ``AiLabelWriteEvent``) and
  merges what their listeners' DataHandler runs wrote into the call's
  ``tool_changes`` set (``ChangeSets::merged()``: a record both name is kept
  once, with the stronger action; a capture that missed writes makes the set
  ``not_captured``).
- The ``writes`` artifact lists the records a completed call's
  ``tool_changes`` step names beside its announced writes, in a block of its
  own ("Further records the run's completed tool calls wrote"), and says so
  when such a list was cut at its limit.
- A move in a draft workspace is listed as the version it wrote, in that
  workspace, as a delete there already was; the live row it names in the
  command was listed as moved in workspace 0 although live was untouched.
  The effect check above reads the same entries and now judges that version,
  which the history already named, without the unmoved live row. A move core
  makes live from a draft workspace (a table without versioning, a workspace
  that allows live editing) has no version; it is still listed and judged as
  the live row.
- The dispatch ran after the call's transaction was committed and outside
  the scope guard, so a listener could write outside the scope; it was
  reported, not prevented. The amendment below puts it under the scope.

**The listeners of a write run under the scope** (amending this decision,
2026-11-20, `#1438 <https://github.com/netresearch/t3x-nr-llm/issues/1438>`__;
owner decision of 2026-11-19). A listener of the call's write events stores
its write on behalf of the call, so it is held to the call's scope. For a call
with a scope, ``ToolLoopService`` dispatches ``AfterAiRecordWrittenEvent`` and
``AiLabelWriteEvent`` after the effect check has cleared the call's own
writes, while the guard is still armed and before the transaction is ended:

- A listener's DataHandler run meets the hook and its records meet the effect
  check, read in a collector frame of their own and joined to the call's set
  afterwards. A write the hook refuses, or the effect check finds outside the
  scope, rolls the whole call back, the call's own write included; the model
  reads that the call's write was in scope and which record a listener wrote
  outside it, by which rule. A call that already wrote outside the scope, or
  had a write refused, dispatches nothing, as an error result did not before.
- A same-draft write stays possible: AI Label's metadata on the page's draft
  is a write of a record the call wrote. The entry TYPO3 14 adds for a
  translation to such a write is left out as for the call's own write (see
  "Translations core synchronises"), so under a scope of the default language
  the translation now gets no version where #1432 measured one.
- An integrator who needs a listener to write elsewhere wraps that write in
  ``WriteOutsideTheAssignmentScope::run($reason, $write)`` (``@api``). During
  the dispatch it suspends the scope for that write only, keeps its records
  out of the effect check, lists them with the call's changes and logs the
  reason as a warning. Anywhere else, the tool's own execution included, it
  runs the write as it is. Nothing in nr_llm calls it.
- A call without a scope dispatches as before, after its transaction.
- Known limit: like the call's own writes, only what a listener writes through
  the DataHandler is judged. A listener that writes with a query or through
  Extbase persistence writes inside the call's transaction unjudged, and the
  commit keeps it.
- Known limit: the allowance holds for any code that runs during the
  dispatch and wraps its write in it; it is code
  of the installation, which can write past the scope by other means too. A
  write allowed in a call nested in another call is kept out of the outer
  call's check as well. A DataHandler run started inside the allowance that
  shares the correlation id of the listener's run (core's own sub-instances)
  is read from the history with that run and judged: the call is rolled back.
- Known limit: on PostgreSQL a listener statement that fails aborts the
  call's transaction. The effect check then cannot read what was written,
  and the call is rolled back and reported as not judgeable, although the
  listener failed rather than wrote outside the scope.
- Known limit: a listener's DataHandler that is the outermost of its run (AI
  Label's is) runs a ``TCEMAIN.clearCacheCmd`` group flush itself, inside the
  call's transaction; on MySQL and MariaDB its ``TRUNCATE`` commits the call
  early, which the marker reports as a call that could not be rolled back.
  No ``clearCacheCmd`` is set by default. Its cache flush by tag also runs
  before the commit, so a frontend request between the two can cache the
  page as it was until the next flush.

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

**A commit by the database is observed, not guessed** (NEXT-240, amending this
decision). An installation's DataHandler hook can end the transaction in the
middle of a call. nr_mcp_agent's ``DashboardCountInvalidation`` flushes a cache
on ``Typo3DatabaseBackend`` after every page write, and its ``TRUNCATE``
commits on MySQL and MariaDB. The call's writes were then stored, and every
later statement committed on its own. But the failed savepoint release was
reported as "NOT committed as such", so the A2A task's ``writes`` artifact said
that no write was executed. Every call now writes a marker row into
``sys_registry`` (namespace ``tx_nrllm_scope``) inside its transaction, right
after the savepoint. When the savepoint is gone at the commit, the marker is
deleted after the call's own transaction is ended (for a call inside its
caller's transaction, see the known limits), and the number of rows that
delete affected decides the outcome. One row means that the transaction was committed: the call
is reported as it ran, its write is recorded, its deferred flushes run, and a
warning is logged that it could not have been rolled back. No row means that the
transaction was rolled back or the connection was lost. Then the call stays
"NOT committed as such". On the normal way out, the marker is deleted inside
the transaction and never becomes visible.

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

**A row the effect check cannot read is not a row that is gone** (amending
this decision, 2026-11-18,
`#1434 <https://github.com/netresearch/t3x-nr-llm/issues/1434>`__). The hook
of a command hears of the command, not of its outcome, so the check takes a
delete or undelete only where the stored row or the history bears it out: a
command for a uid that no row bears is no change. A read of the row that failed
was taken the same way. A delete or undelete outside the scope whose row could
not be read, and whose history was gone, was left out, and the check cleared
the call without judging it. A read that fails, of the row of a delete or
undelete or of the version a draft workspace holds of a row, now marks the
entries as incomplete, as a history read that fails does, and the call is
rolled back as not judgeable. A move whose row cannot be read stays an entry
and is judged as before, which fails closed. A row that was read and is not
there is still no change. The version is looked up only for a table that keeps
versions: a table without versioning has no version columns, so the query would
always fail, and a move core makes live on such a table from a draft workspace
would always be rolled back. A delete in a draft workspace that leaves no
version is judged by its live row: core deletes the live row of a table
without versioning (from a workspace that allows live editing), and it
discards a move, new or delete draft of a versioned row instead of writing a
delete version (``DataHandler::deleteAction()`` in TYPO3 13.4.40 and 14.3.12).
Both were taken as a delete that did not happen and left out. The call's
history is read from the uid ``sys_history`` had before its first write; a
read of that uid that failed was taken as a call without history, so the
history was not read and the check did not say so. It now marks the entries
as incomplete, as a history read that fails does.
``versioningWS_alwaysAllowLiveEdit`` does not change this: core decides a
delete by ``versioningWS`` alone, also on 13.4, where the flag makes it write
the live row of a versioned table for an edit.

**A discard and a publish are judged before core carries them out** (amending
this decision, 2026-11-20,
`#1442 <https://github.com/netresearch/t3x-nr-llm/issues/1442>`__). Both
change what stands at the live row: a discard puts the record back at its live
position, and a publish puts the draft there. Both layers before the effect
check read the draft the command names, at the draft's position, and the
effect check found nothing to read afterwards: a discard hard-deletes the
draft, and its history row names a uid that no row bears any more, which the
check clears like any hard delete. A draft that moved a record from outside
the scope onto a page inside it could therefore be discarded or published,
and the call committed. The drafts core discards along with a record (its
translations, its inline children, the new pages and records below a new
page) were never judged at all.

The DataHandler hook now judges them while the rows can still be read, and
notes what lies outside the scope on the guard; the effect check reports it
with the rest, and the call is rolled back:

- Every discard core carries out, in ``processCmdmap_discardAction``, which
  core calls at the start of ``DataHandler::discard()`` for the ``discard``
  command (TYPO3 14 only; 13.4 has no such command), ``version`` with
  ``clearWSID`` or ``flush``, a delete that discards a draft, and every
  record discarded along with it (TYPO3 14.3.11 and 13.4.40). Core calls the
  hook before its own access checks, so a discard core then does not make is
  judged too.
- Every publish, in ``processCmdmap_preProcess``, right before it runs, the
  ones the workspaces extension adds for a publish included: ``version`` with
  ``publish`` or ``swap`` (the live row and the draft named by ``swapWith``,
  read as the workspaces extension reads it: ``'12abc'`` is draft 12).
  A ``version`` action neither core nor the workspaces extension knows is
  judged as unreadable, and so is any command whose key is not one integer:
  ``DataHandler::start()`` and the workspaces extension's own
  ``processCmdmap_beforeStart`` refuse such a key, but a hook registered
  after them can replace the public command map, and TYPO3 13.4 hands the key
  to a ``setStage``, which reads a list of uids from it (14.3 casts it to its
  first uid). A ``new`` version and a ``setStage`` write the draft
  only, so they stay judged at the draft's position by the command check of
  the hook, and a ``new`` version that a later hook puts into the map is
  judged there before it runs; core makes a ``new`` version for every first
  edit in a draft, and judging it at the live row rolled back the edit of a
  record on a page the draft had moved into the scope.
- For each such row of a versioned table, the live row is judged at its live
  position and the draft at its position in the workspace; a draft with no
  live row (a new record) is judged where the draft lies, and a publish of it
  also in the live tree, where it then stands: a draft can have moved its
  page out of the scope there. A row that cannot be read is outside the
  scope. A row that does not exist is no change.
- A page the draft moved takes its subpages along: a publish puts them where
  the draft lies, a discard back where the live page is. Every page below it
  has to lie in the scope at both positions. A publish of a page's delete
  draft deletes the pages below it live; those deletes are written to the
  history and judged by the effect check like any delete.
- Core discards the drafts of a record in every workspace when the record is
  deleted live, and each draft core hands over is judged in its own
  workspace, so a live delete is rolled back when another workspace's draft
  of the record lies outside the scope. A child core discards along with
  such a draft by its live uid is judged at the call's workspace only.
- A publish of a draft that moved a record puts the record on the draft's
  page in the live tree, where a draft can have moved that page out of the
  scope: the destination is judged there as for a ``move`` command, also
  under a scope that names the record. A discard of such a draft is judged
  by the record, which is in scope wherever it lies.
- The built-in ``publish_record`` tool clears the hidden flag; it is a
  datamap update and judged as one, not as a workspace publish.
- What a call made inside another call noted is reported to the outer call,
  as its refusals are, and rolls the outer call back too, even when the inner
  call's own rollback already took the write back. This errs on the side of
  rolling back.

A publish that took place (its draft is gone, or a new record's draft is now
the live row, read before and after the command) and a stage change that took
(the draft carries the stage asked for) now count as updates of the record the
command names in the call's change list, so the effect check also judges the published
row where it lies afterwards. The effect check is asked before the guard of the
call is taken, because it reads what the guard noted.

Known limit: ``discard()`` also removes references to the discarded draft from
other records with queries of its own (``discardCsvReferencesToRecord()``
updates group fields found through ``sys_refindex``, ``discardMmRelations()``
deletes MM rows). No hook hears of them and they write no history, so they are
not judged; they remove a reference to a draft that no longer exists. A
publish likewise re-points the translations of the published record
(``updateL10nOverlayRecordsOnPublish()``) and its MM rows with queries of its
own. A discard that names a live row is judged at the call's workspace; a tool
whose DataHandler runs in another workspace than the call's is refused by the
built-in write path, not by this check.

Known limits of the transaction, none of them a write outside the scope that the
checks let through:

- A write tool that declares no effect and needs no approval gets no transaction;
  a violation by such a call is reported as not rolled back.
- A ``DB/TableMapping`` to a connection other than the default one makes the call
  not run: one transaction cannot cover two connections. This is fail-closed and
  an availability loss.
- On PostgreSQL a database error inside the call aborts the transaction, so the
  note of what was written before the failure may name rows that are gone.
  The scoped-call tests run on PostgreSQL in CI since PostgreSQL support
  (`#1420 <https://github.com/netresearch/t3x-nr-llm/issues/1420>`__); this case, a statement failing inside the call, has no PostgreSQL
  test of its own.
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
- A call inside a transaction of its own caller reads its marker inside that
  transaction. When a hook releases the call's savepoint with an unbalanced
  commit while the caller's transaction stays open, the call is reported as
  written, although the caller's transaction can still roll it back. No
  built-in path runs a tool inside a caller's transaction.
- A failed final ``COMMIT``, and a failed savepoint release whose marker was
  not committed, are reported as an error ("NOT committed as such"), because
  whether the changes were saved is then unknown. The marker is read only when
  the savepoint release fails.
- When the database rolls a call back behind its back (a deadlock, or a hook's
  own ``ROLLBACK``), the effect check cannot read the rows that are gone and
  counts them as outside the scope. The model is then told that the changes
  were NOT rolled back and have to be reviewed, although the rows are gone.
  This errs on the side of checking. The A2A client is told the same: the
  ``writes`` artifact lists those records among the ones to check, does not
  call the refused writes rolled back, and says that the rollback could not be
  confirmed (`#1448 <https://github.com/netresearch/t3x-nr-llm/issues/1448>`__).
  A savepoint release that fails after a
  successful nested rollback is reported as not rolled back, although it was.
- A cache flush that fails after the commit is logged as a warning; the tool's
  result has already said it flushed.
- The effect check reads at most 5000 history rows of one call, and a call that
  reaches that number (a bulk copy of a page tree), whose history cannot be read,
  or one of whose rows cannot be read where the check needs it (see above) is
  rolled back as not judgeable.
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
without it is as before. The list holds at most 50 refusals, like the list of
changes, and ``refusedOmitted`` counts the rest; ``rollbackUnconfirmed`` is
true for a call that wrote outside the scope and whose rollback could not be
confirmed (the database ended the transaction itself, or the rollback failed),
so that what ``refused`` names may stand. Both keys are written only where
they say something, and a payload without them reads as nothing left out and
nothing unconfirmed (2026-11-22, `#1448
<https://github.com/netresearch/t3x-nr-llm/issues/1448>`__). The governance
log gets ``invocation_denied`` for layer one as for every rule.

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
     - record (``discard`` also translations); the effect check judges every
       discard core makes, the drafts it discards along with a record
       included, and every publish before it runs, at the live row and at the
       draft
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
  its parent's, which no built-in tool produces), and the translations of a
  record in a workspace delete. What a discard removes along with a record is
  judged by the effect check. A
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
