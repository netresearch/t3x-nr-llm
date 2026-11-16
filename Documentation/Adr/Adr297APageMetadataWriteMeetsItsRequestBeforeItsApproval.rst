.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-297:

======================================================================
ADR-297: A page metadata write meets its request before its approval
======================================================================

:Status: Accepted (amended: the read before the write and the unchanged row
   are replaced by a write bound to a passed check of exactly its proposal)
:Date: 2026-11-15
:Amends: :ref:`ADR-296 <adr-296>` (the requested fields are now held by a
   rule, not only named to the model)
:Amended: 2026-11-16 by :ref:`ADR-298 <adr-298>` (the invocation history
   binds a check to its proposal): the rule ``page_metadata_read_first`` and
   the ``tstamp`` comparison of ``page_metadata_contract`` are replaced by
   the rule ``page_metadata_binding`` and the check tool
   ``check_page_metadata`` (spec 056)
:Authors: Netresearch DTT GmbH

Context
=======

The page metadata skill (:ref:`ADR-296 <adr-296>`) binds its run to the row
the request names: the scope of :ref:`ADR-295 <adr-295>` admits the page in
one language and nothing else. What the run writes into that row was an
instruction to the model. ``update_page_metadata`` accepts any of its twelve
fields, any value up to 2000 characters, and does not know whether the model
looked at the page first. A review of the skill showed the case: content on
the page tells the model to change the navigation title as well, and the
call reaches the approval card with a field the client never asked for.

An approval card shows the call, and a human can refuse it. Conditions that
can be decided from the request and the row should not depend on that human:
they are the same for every call, and a remote client relies on them.

Tool invocation rules (:ref:`ADR-216 <adr-216>`) are also asked before a
call's approval when they implement ``PreApprovalInvocationRuleInterface``
(:ref:`ADR-293 <adr-293>`); a denial
goes back to the model as a refusal and into the governance log as
``invocation_denied`` with the rule's identifier. Rules apply to every run of
the installation, so a rule for one skill has to find out itself whether a
call is its business.

Decision
========

**A rule** ``page_metadata_contract`` **holds every call of**
``update_page_metadata`` **in a run of the skill to the request.** A run is
the skill's when the A2A task bound to it belongs to a profile with the
handler ``nr_llm.page_metadata.v1``, read from the profile row whether or
not the profile is hidden or deleted since; a task whose profile row is gone
refuses the call. The rule reads the request from the first entry of the
task's history with the parser the handler used; a request it cannot read
refuses the call. For a call aimed at the requested row it requires:

- only fields the request names, at least one;
- each value text with at least one visible character, within a fixed length
  per field (title, subtitle, navigation title, keywords, X card type 255;
  abstract 1000; description, Open Graph and X descriptions 300; SEO, Open
  Graph and X titles 100 characters);
- the row unchanged since the task was accepted: its ``tstamp`` in the
  client's workspace is not later than the task's creation.

The rule answers from the call, the task and the database, not from the
invocation history, so it is asked before the approval card (ADR-293).

**A second rule** ``page_metadata_read_first`` **requires a successful**
``get_page_content`` **earlier in the run.** That answer depends on the
history, which before the card does not yet hold the calls of the same turn,
so this rule is asked only when the call runs, after its approval. A read
and a write the model asks for in one turn, in that order, are shown
together, and the write runs after the read; in the other order the write is
refused after its approval. A run whose history is incomplete (a requeued run, a
state suspended without one) may have lost an earlier read: unless it reads
again, its writes of this skill are refused (``history_incomplete``), and
every other call is left alone.

The call must name the live uid of the requested row; ``update_page_metadata``
takes nothing else, so a workspace version uid would be approved and then
refused by the tool. A row the scope refuses (another page, another
language, a uid without a row) is left to the scope, which refuses it with
its own reason (``outside_scope``, ``language_not_allowed``,
``unresolvable``); otherwise the first refusing rule would hide that
reason. Every other row is refused (``not_the_requested_row``): a second
translation, a row the scope admits by another path, and a requested row
that no longer exists. The decision which rows to leave to the scope is the
scope's own check, so the two cannot drift apart.

**The values live in code, not in the prompt and not in a configuration.**
They are part of the contract version (``…v1``): a change of a limit is a
change of the skill, reviewed like code.

Consequences
============

- ●● A field the client did not ask for and an overlong value are refused
  before any human sees the call, and the refusal names the rule in the
  governance log.
- ◑ A write without a read reaches the approval card and is refused after
  the editor approved it; nothing is written, and the model reads the
  reason. Since ADR-298 this holds for a write without a passed check.
- ● A row changed by an editor after the task was accepted is not written
  over by a proposal built on the older state; the client sends the task
  again. Amended by ADR-298: the comparison is with the state the check
  observed, not with the acceptance time, and the model checks again.
- ◑ "Read before write" means a ``get_page_content`` call with the outcome
  ``ok``, which includes its answer that a page was not found or not
  permitted; it is a call of any page
  earlier in the run, not a read of this row with this state: the invocation
  history records neither arguments nor results. Binding a check to the exact
  proposal and data state needs the history to carry them, which is a
  separate decision. That decision is ADR-298: the history carries keyed
  digests of a call's arguments and of the state it observed, and the rule
  ``page_metadata_binding`` replaces ``page_metadata_read_first``.
- ◑ "Unchanged" is second-granular and compares with the acceptance time,
  not with the time of the read; a second write of the same run to the same
  row is refused as changed. A record's ``tstamp`` is the ``EXEC_TIME`` of
  the request that wrote it, its start: a writer that started before the task
  was accepted and saved after it is not seen. Only changes in the live
  workspace and the client's workspace are compared. Expired with ADR-298:
  no clock is compared any more; the checked fields are.
- ◑ The request is read from the first history entry. A task keeps at most
  100 history entries and then drops the oldest, so a full history is not
  read as the request and every further write of that task is refused. A
  configuration whose privacy level stores no message content refuses every
  write of the skill for the same reason.
- ◑ The limits are fixed per contract version; an installation that wants
  others needs a new version.
- ◑ The rule reads the profile's handler when the call is decided. An
  administrator who changes the handler of a profile while a call waits for
  its approval switches the contract off for that task.
- ✕ Database reads per ``update_page_metadata`` call of every run: the task
  lookup in both rules, which is where a run without an A2A task stops; for
  the skill's runs the profile, the rows and the scope's own lookups. Since
  ADR-298 the binding rule also repeats the contract's lookups and reads the
  checked fields of the row once more.

Net Score: 0

Alternatives considered
=======================

**Hold the fields in the scope of the assignment.** Rejected for now: it adds
a column axis to an ``@api`` value, the DataHandler hook and the first-layer
claims of every write tool, for one skill whose one write tool states its
fields in its arguments.

**Put the limits in the LLM configuration.** Rejected: a configuration is
shared by skills and editors; the limits belong to the contract the client
sees.

**Check in the handler after the run.** Rejected: the write would already have
been approved and executed.
