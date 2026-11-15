.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-297:

======================================================================
ADR-297: A page metadata write meets its request before its approval
======================================================================

:Status: Accepted
:Date: 2026-11-15
:Amends: :ref:`ADR-296 <adr-296>` (the requested fields are now held by a
   rule, not only named to the model)
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
- a successful ``get_page_content`` earlier in the run;
- the row unchanged since the task was accepted: its ``tstamp`` in the
  client's workspace is not later than the task's creation.

A version row counts as the requested row only when it belongs to the live
workspace or to the client's workspace.

The call's uid is resolved as the scope resolves it. The requested row and
its version in the client's workspace are checked as above; the scope admits
both. Any other row of the requested page in the requested language, such as
a second translation, is refused (``not_the_requested_row``), as is a
requested row that no longer exists. A row of another page or language is
left to the scope, which refuses it with its own reason (``outside_scope``,
``language_not_allowed``); otherwise the first refusing rule would hide that
reason.

**The values live in code, not in the prompt and not in a configuration.**
They are part of the contract version (``…v1``): a change of a limit is a
change of the skill, reviewed like code.

Consequences
============

- ●● A field the client did not ask for, an overlong value and a write
  without a read are refused before any human sees the call, and the refusal
  names the rule in the governance log.
- ● A row changed by an editor after the task was accepted is not written
  over by a proposal built on the older state; the client sends the task
  again.
- ◑ "Read before write" means a successful ``get_page_content`` of any page
  earlier in the run, not a read of this row with this state: the invocation
  history records neither arguments nor results. Binding a check to the exact
  proposal and data state needs the history to carry them, which is a
  separate decision.
- ◑ "Unchanged" is second-granular and compares with the acceptance time,
  not with the time of the read; a second write of the same run to the same
  row is refused as changed. A record's ``tstamp`` is the ``EXEC_TIME`` of
  the request that wrote it, its start: a writer that started before the task
  was accepted and saved after it is not seen. Only changes in the live
  workspace and the client's workspace are compared.
- ◑ The request is read from the first history entry. A task keeps at most
  100 history entries and then drops the oldest, so a full history is not
  read as the request and every further write of that task is refused. A
  configuration whose privacy level stores no message content refuses every
  write of the skill for the same reason.
- ◑ The limits are fixed per contract version; an installation that wants
  others needs a new version.
- ✕ One more database read per ``update_page_metadata`` call of every run
  (the task lookup); a run without an A2A task stops there.

Net Score: +1

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
