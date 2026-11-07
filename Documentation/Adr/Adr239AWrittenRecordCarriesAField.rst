.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-239:

=============================================
ADR-239: A record an AI wrote carries a field
=============================================

:Status: Accepted
:Date: 2026-11-01
:Amends: :ref:`ADR-187 <adr-187>` (a listener ships, a second call site
    dispatches the event, and a move, a publish and a copy report their own
    kind); :ref:`ADR-209 <adr-209>`
:Authors: Netresearch DTT GmbH

Context
=======

:ref:`ADR-187 <adr-187>` announces an AI write and stops there: "No listener
ships in this extension, and none is planned." Nothing has listened since. An
installation that wants to show an Article 50 notice would have to write the
same listener and find a place for the answer, and a template has no way to ask.

The reasoning of ADR-187 still holds for what a site *says*. Whether a notice
appears, in which words and where is the site's business. It does not hold for
the fact itself. The extension knows the fact, and a fact a template cannot read
has no reader.

The event also has a gap. ADR-187 dispatches from
:php:`ToolLoopService::invoke()`, on the argument that every editorial write
passes there. The fill run of :ref:`ADR-237 <adr-237>` came later and writes
through :php:`FieldFillWriter`, so its writes are announced nowhere.

Decision
========

**One listener ships: it records the fact and says nothing.**
:php:`MarkAiWrittenRecord` sets ``tx_nrllm_ai_written`` on the record of an
:php:`AfterAiRecordWrittenEvent`. It renders nothing. The rest of ADR-187
stands: the event carries no payload, and other listeners remain free to do what
the site wants.

**A field on** ``pages`` **and** ``tt_content``, **not a side table.** This is
the first time the extension adds a column to a foreign table. A side table
would keep that rule and would need a query or a ViewHelper in every template,
plus a place in the form for an editor to see it. A field is available as
``{data.tx_nrllm_ai_written}`` in every content element template and as
``{page.pageRecord.tx_nrllm_ai_written}`` in a ``PAGEVIEW`` template with no code,
and it is visible in the form. These two tables are where a visitor can
meet an AI-written text; a write to any other table is left alone, and a site
that needs more has the event.

**The field is the record's state, not a history.** An editor who rewrites the
text decides whether the notice still applies, and clears the field. Every
editor of the record may: an excluded field would be out of reach of the person
who took the text over, and core drops an excluded field on a copy by a user
without the grant, so AI text would lose its mark. A copy keeps the mark, and
so does a translation core copies from a marked record. A run cannot clear it:
the field is a system column of the writing tools. The next AI write sets it
again. Nothing clears it on its own: a
heuristic that decides how much editing makes a text human would be a legal
statement this extension cannot make.

**The listener writes past the DataHandler.** A DataHandler run would add a
history entry and a ``tstamp`` nobody made, and it would start the hooks of
:ref:`ADR-237 <adr-237>` on a save that changes nothing a person did. The run
log already says who did what.

**A call that writes no AI text sets nothing either.** A move, a publish and a
copy place a record; what it says is not an AI's text, and a person's page must
not start to carry a notice because an agent moved it. The same holds for an
update that changed only settings, a column that is no ``input`` or ``text``,
and for a translation draft that holds the source text because the translator
failed. The event says so with a new case, :php:`WriteKind::TEXT_UNCHANGED`,
which these tools report instead of ``UPDATED`` or ``CREATED``. A deletion sets
nothing.

**The fill run announces its write.** :php:`FieldFillWriter` dispatches the event
after a write it read back, with the run's uuid. This is the second call site
ADR-187 said would not exist; the alternative was a listener that misses every
field fill. A failing listener is logged there as well.

Consequences
============

- ● A template can ask one field whether an AI wrote this record.
- ◐ The column is available to users who can edit the record, without a
  separate exclude-field grant. Installation-specific TSconfig or form
  customizations can still change its display.
- ◑ Until the schema update ran, new pages and content elements cannot be
  saved, as with any column added to these tables.
- ◑ A foreign table carries a column of this extension. It is removed with the
  extension by the schema analyser, like any other.
- ◑ A listener that treated a move or a copy as ``UPDATED`` or ``CREATED`` now
  gets :php:`WriteKind::TEXT_UNCHANGED`.
- ◑ Records written before this change carry no mark. Nothing can tell them
  apart any more.
- ◑ A content element an AI wrote on a page does not mark the page.
- ◑ nr-landingpage writes its own records and does not pass through the event.
  It renders its label from its own generation data.
- ✕ A site that wants no such column has it anyway. Unused, it is one tinyint.

Net Score: +0.5

Alternatives considered
=======================

**A side table with a ViewHelper.** Rejected above.

**Rendering a notice here.** Rejected for the reason in ADR-187.

**Clearing the field on a manual edit.** Rejected above.
