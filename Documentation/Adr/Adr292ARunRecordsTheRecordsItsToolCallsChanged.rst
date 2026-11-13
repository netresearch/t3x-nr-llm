.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-292:

=======================================================================
ADR-292: A run records the records its tool calls changed
=======================================================================

:Status: Accepted
:Date: 2026-11-12
:Amends: :ref:`ADR-182 <adr-182>` (its two revisit triggers, a second reader and a call that writes more than one record), :ref:`ADR-198 <adr-198>` (the copy and translation read-back limits, for what a reader can learn of them), :ref:`ADR-206 <adr-206>` (the answer of a call that failed after it wrote)
:Authors: Netresearch DTT GmbH

Context
=======

A write tool names one record (:ref:`ADR-182 <adr-182>`), and the run keeps
that one identity as a ``tool_write`` step. Core writes more than the record a
tool names. A copy of a page copies its content, a translation of a page or
element writes overlays, and a hook of the installation can write any record
it likes (:ref:`ADR-198 <adr-198>`, amendment of 2026-11-06). The copy and
translation tools read back the main record only and say so; what core wrote
along appears in no field a consumer can read.

Two of the three triggers ADR-182 gave for revisiting its shape have come true
in effect. A second reader exists: the observed outcome joins ``sys_history``
against the ``tool_write`` steps (:ref:`ADR-185 <adr-185>`), and nr-mcp-agent
lists the writes of a run segment for the next prompt. And a call does write
more than one record, through core.

Two gaps follow. A tool that fails after it wrote returns an error, which is
fail-closed and carries no target (:ref:`ADR-182 <adr-182>`,
:ref:`ADR-206 <adr-206>`): the records it wrote before the failure appear
nowhere, and the model retries a write that half happened. And the approval
card states the workspace and language of a write only as prose, which a
consumer has to parse.

TYPO3 13.4 and 14.3 dispatch no PSR-14 event for a DataHandler record
operation. The DataHandler's hooks are the only way to hear of a write while
it happens.

Decision
========

**A run step lists what the DataHandler runs of one call wrote.**
``ToolLoopService`` arms ``ToolChangeCollector`` around
``ToolInterface::execute()`` and takes it afterwards, on every way out of the
call. Two global DataHandler hooks feed it, ``processDatamap_afterDatabaseOperations``
and ``processCmdmap_postProcess``; they run for the nested DataHandlers core
creates for a copy or a translation, and do nothing while no call is armed.
When the call ends, the records the hooks saw are joined with the
``sys_history`` rows of the same DataHandler chain, which share one correlation
scope; the history adds what no hook names, such as the content and subpages a
page delete takes along. ``sys_history`` has no index on the correlation id, so
the rows are read only from the uid the table had when the first DataHandler
run of the call began, taken by a hook before that run writes: a range of the
primary key as long as the call's own writes. A call whose DataHandler runs
never reached that hook reads no history. ``history_data`` is never read. A step of the new kind
``tool_changes`` (event kind ``TOOL_CHANGES``) follows the tool step and the
write step of the call. A call that wrote nothing records no step.

**An entry is an identity and stored facts.** Table, uid, action (created,
updated, moved, deleted, restored; a record created and then updated is
created), the workspace of the row, the live uid it is a version of, its
language, whether it is the record the tool named (``writeTarget``), its
disable flag and the time and group restrictions the row itself carries. They
are read from the row, not from the tool's text, and the workspace is the one
the row is stored in, not the one the writer works in: a user in a draft
workspace writes a table without versioning live. They are separate fields: a
row that is not hidden can still be a draft, and nothing states that it is
public. Frontend visibility is not checked and has no field. The list holds at
most 50 entries, keeps the tool's own target among them, and counts the rest in
``changesOmitted``; a history chain of more than 5000 rows is cut off and counted
as at least one more. No title and no
field value is stored, so the run-step privacy filter has nothing to strip at
any level, and the step is persisted as it is.

**The list never says complete.** The coverage is ``datahandler``: this is what
DataHandler runs reported. A write that bypasses the DataHandler, a query of a
hook or a file written through FAL, is invisible, and the absence of a record,
or of the whole step, says nothing about it. The coverage is ``not_captured``
when the capture failed, or when the tool stated a write target that no
DataHandler run reported; whatever was seen is still listed. A reader must
read the record itself in that case.

**A call that failed keeps its list.** The result stays an error with no target
(:ref:`ADR-182 <adr-182>`, :ref:`ADR-206 <adr-206>`). The step is recorded
beside the failed tool step, and the error the model reads names what the call
changed before it failed, ten identities at most, so it checks them before it
tries again.

**``ToolResult`` does not change.** The list is a property of the call, taken
where the call executes, and not of the result. The loop rebuilds results
(:ref:`ADR-182 <adr-182>`), and a field added to ``ToolResult`` is a field the
rebuild can lose.

**The approval card states workspace and language as values.**
``PendingCallView`` carries ``workspaceId``, ``workspaceTitle`` and
``language``. The workspace is the one the run writes into as its initiator
pinned it (:ref:`ADR-260 <adr-260>`), 0 for live. The title is read for the card
and is withheld from a viewer who may not work in that workspace; the id is
not. The language comes from the new ``PendingLanguageInterface``, which only
a tool that takes a target language implements, here ``create_translation_draft``;
a card without one states none, which is not the default language. The prose
lines stay as they are.

**Reading it.** ``AgentRuntimeInterface::events()`` returns the new event kind
beside the others; a consumer that filters by kind ignores it until it asks.
``ObservedChangeSet::tryFromEventPayload()`` decodes the payload without the
caller parsing arrays, skips an entry it cannot read and reads an unknown
coverage as ``not_captured``.

Consequences
============

- ● A consumer can list what a run changed, with the dependent records of a
  copy or a translation, without parsing prose.
- ● A call that fails after it wrote no longer hides what it wrote.
- ● Workspace and language reach the approval card as values.
- ◐ The ADR-182 triggers are answered by a second structure beside the first;
  the observed outcome keeps joining the ``tool_write`` steps.
- ◑ Two more global DataHandler hooks run on every record write of the
  installation, and return at once outside a tool call.
- ◑ A call that writes reads one row per listed record, one query for the
  watermark and one history query per DataHandler chain over a range of the
  primary key. A call that runs no DataHandler costs nothing.
- ◑ An update that changed no column is listed as updated: the DataHandler ran
  it, and the list states that and not what the row now holds.
- ◑ The list and the note the model reads name table and uid of records the
  acting user may not be able to read, and the run's events are readable by
  whoever may read the run. A consumer shows a record only to a viewer who may
  read it; nr-mcp-agent decides that where it builds its view.
- ◑ The list is as old as the history it joins: where ``sys_history`` is off
  or pruned, the hooks' records stand alone, a delete of a row that no longer
  exists is not listed (a command for a uid that never existed leaves the same
  trace), and the inline children of a
  delete are missing.
- ✕ A tool that names a target and writes through a query is shown as
  ``not_captured`` even when its write is correct. Such a tool reports no
  more than it did before.

Net Score: +2

Alternatives considered
=======================

**A list on ``ToolResult``.** Rejected: the rebuild trap of
:ref:`ADR-182 <adr-182>`, and the error result would have to carry a target
that its fail-closed rule forbids.

**Only ``sys_history``.** Rejected: it is empty where logging is off, and it
cannot tell the tool's own record from the others. The hooks alone miss what
core takes along without a command of its own. The union is what the run can
state.

**A coverage of ``complete`` where history and hooks agree.** Rejected: both
see only DataHandler writes, so their agreement says nothing about the writes
neither sees.

**Frontend visibility as a field.** Rejected: no tool computes it, and a field
nothing fills reads as an answer.
