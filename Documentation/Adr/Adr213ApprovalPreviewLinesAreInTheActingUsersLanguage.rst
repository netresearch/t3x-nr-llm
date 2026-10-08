.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-213:

====================================================================
ADR-213: Approval preview lines are in the acting user's language
====================================================================

:Status: Accepted
:Date: 2026-10-06
:Authors: Netresearch DTT GmbH

Context
=======

A run that needs a human approval suspends, and every tool that implements
:php:`ToolPreviewInterface` describes the pending call in lines of text
(:ref:`ADR-136 <adr-136>`). The lines are produced at the moment of suspension,
persisted in the suspended state, shown on the approval card after a per-viewer
authorisation, and produced a second time when the run resumes, to compare them
with what the approver was shown (:ref:`ADR-184 <adr-184>`).

All of them are English literals. For a German editor that makes the card a mix:
the buttons and the labels around it are German, the lines in the middle read
``New page under page [2] "Open":``, ``navigation title:`` and
``first among the subpages``. The editorial guidelines for the assistant
(rules 14 to 18) ask for approval texts in the editor's language, in editor
vocabulary, in a fixed order, without internal field names.

Two constraints follow from the design above and decide where the language
can come from:

- The lines are compared byte for byte at resume. Their language must be the
  same at suspend and at resume, on the synchronous path and in the queue
  worker, or every approval of a localised tool bounces as stale.
- The run owner and the person who approves can be different people
  (:ref:`ADR-130 <adr-130>`, :ref:`ADR-133 <adr-133>`). One set of lines is
  persisted per pending call.

Decision
========

**The language of a preview line is the language of the run's acting backend
user,** the ``lang`` column of the user the run executes as, read from the
:php:`ToolExecutionContext` (:ref:`ADR-083 <adr-083>`). Never the ambient
``$GLOBALS['LANG']``, which belongs to whoever runs the call, and never the
viewer's language.

- A user without a ``lang`` value gets English, which is what TYPO3 itself
  does for such a user. A language without a catalogue of its own gets the
  English source text.
- **When the viewer's language differs from the acting user's,** the card
  shows the lines in the acting user's language, complete and in one language.
  It does not mix, and it does not retranslate. In the usual case, an editor
  who starts the run in the chat and approves it there, the two are the same
  person.
- **When the acting user changes their language between suspend and resume,**
  the re-computed lines differ from the persisted ones and the approval bounces
  once, with the current lines shown again (:ref:`ADR-184 <adr-184>`). That is
  the existing staleness path doing its job; no special case.

**The texts live in the extension's catalogue**, ``locallang.xlf`` and
``de.locallang.xlf``, under ``approvalPreview.*``. A closed enum,
:php:`ApprovalPreviewLabel`, names every entry, and
:php:`ApprovalPreviewTranslator` resolves one for a given user. Tools do not
build a sentence from English words: they pick labels and pass the values.
Quotation marks, the word for "empty" and every sentence are catalogue entries,
so a language decides its own.

**A unit test walks the enum** and fails when a label has no English or no
German text, when the two texts take different placeholders, when the German
text is the English one, when a text names an internal field or tool, or when
the catalogue holds an ``approvalPreview.*`` entry that no label names.

**The order of the lines follows rule 16 of the guidelines:** what changes,
where, the current state, the new state, the consequences. The last line is
**"Technical details"**: UIDs and table names, for support (rules 10 and 26).
It is not an optional extra: it keeps the approval bound to the exact records
the call names, because the lines above it name pages by title and two pages
can share one (:ref:`ADR-184 <adr-184>`). Where a field's value is longer than
the card shows, the line shows the section that changes and the technical line
carries the length and a short hash of both whole values, which binds the
approval to the whole value. ``fetch_external_url`` names no record and has no
technical line; its lines show the address, host and query string verbatim.

**Field names are editor words** (rule 18). Where a tool writes a fixed set of
fields, each has its own catalogue entry in the guidelines' terms ("Meta
Description"). Where the set is open — the columns of a content element or of a
record in an extension table, a content type, the items of a select field — the
line uses the TCA label in the acting user's language, resolved through core's
``sL()``, which reads ``LLL:`` references and, from TYPO3 14 on, translation
domain references alike. Such a label is English where the installation has no
language pack for the user's language. A reference that core cannot resolve
counts as no label, and the line falls back to the column, table or value name
rather than showing the reference. The column name sits in the technical line.
A select field's value reads as its item label; whether it changes is decided
on the stored values, and where two different values share one label both
values follow in brackets, so a change never reads as unchanged.

**The card's own lines follow the same rule.** A preview that failed, came
back empty or was cut to twenty lines gets a line from the loop, not from the
tool, and that line is in the acting user's language too. A failed preview
names the exception class only in a technical details line under the
sentence; the message never reaches the card, and the whole exception goes to
the log. How these lines meet ADR-184's comparison:

- A failed or empty preview at suspend is marked ``failed`` and carried, never
  compared, so its wording decides nothing.
- A preview that fails, is empty or whose tool no longer previews at resume
  differs from the successful preview it replaces whatever it says, so the
  call stales as before.
- The overflow marker of a cut preview is compared, and both sides are worded
  for the same acting user.
- The line that withholds a preview from a viewer without permission on the
  record is rendered for that viewer, in the VIEWER's language, and is never
  persisted or compared.

**Runs suspended before the upgrade.** Their persisted lines are the English
ones of the release they were suspended under. On resume the tool's lines are
recomputed in the acting user's language, differ, and the approval bounces
once with the current lines shown again — the staleness path of ADR-184, no
special case. Nothing is written on the first approval of such a run; the
second approval, against the new lines, executes. A failed preview persisted
before the upgrade keeps its English line until the run suspends again.

**What stays English.** A refusal line is the string the tool's ``execute()``
hands the model as well; it is shared on purpose, and a refusal at preview time
tells the approver the call would fail, not what it would do. The tool results
that go back to the model stay English too; they are not approval text.

**Scope of this decision.** It applies to every built-in tool that implements
:php:`ToolPreviewInterface`. It was first applied to ``create_page_draft``,
``move_page`` and ``delete_record``, then to the fifteen others: the
field-update, file, move, copy, publish, draft creation and translation tools
and ``fetch_external_url``. A run without an acting user,
which only a read tool's preview can meet, gets the English source text.

**No new reads.** The consequence lines show what the tools already read for
their plan: translations, subpages, the number of records stored on a page,
the number of references, recoverability. A line the tool cannot know is not
shown. In particular ``delete_record`` does not say anything about redirects:
it does not look at them, and whether core creates one on a delete is not
something this decision verifies.

Considered alternatives
=======================

**English only** (the status quo). Rejected: it contradicts rule 17 and mixes
languages on every German card.

**The viewer's language at render time.** The right answer to the second
constraint, and the larger change: the persisted state would have to hold
label identifiers and values instead of text, the comparator would compare
those, and both card renderers would translate at display time. The loop,
the state encoding and the chat panels all change. Not rejected as a goal;
rejected as part of this step, because it is a format change of persisted
state and the acting user is the viewer in the common case. If approvals by
someone other than the run owner turn out to be common, this is the next step.

**The ambient backend language** (``$GLOBALS['LANG']``). Rejected: it is the
language of the process that happens to run the call, and it differs between
the request that suspends and the worker that resumes (:ref:`ADR-083 <adr-083>`).

Consequences
============

● A German editor reads a German approval card for these three tools: the
heading names the editorial action, the lines carry no field name, and the
consequences of a delete are listed line by line.

● The English text is the catalogue's source text, so an installation in any
other language sees the same English lines as before in structure, and a
translation file for that language works without code.

◐ The lines of these three tools changed in wording and order. A caller that
matched on the old English strings, rather than showing the lines, has to
change; none in this repository did except the tools' own tests.

◐ A viewer whose language differs from the run owner's reads the owner's
language (see above).

◐ A label taken from the TCA (a content element's or an extension record's
column, a content type) is English on an installation without a language pack
for the acting user's language, as it is in the backend form.

✕ A refusal shown in a preview is still English.
