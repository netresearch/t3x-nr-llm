.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-328:

=============================================================================================
ADR-328: A refused draft is answered without a card, and a model can ask for the choices
=============================================================================================

:Status: Accepted
:Date: 2026-11-25
:Amends: :ref:`ADR-262 <adr-262>` (a call its tool refuses before the approval
    is settled and never executed, instead of going to the tool's
    ``execute()``), :ref:`ADR-326 <adr-326>` (the names of a computed select
    are listed on request)
:Authors: Netresearch DTT GmbH

.. _adr-328-context:

Context
=======

:ref:`ADR-326 <adr-326>` lets ``create_content_element_draft`` fill a select
whose items a processor computes, such as the icons of bootstrap_package's icon
group: about 600 names. The tool description marks such a column with ``~`` and
does not list the names. An unknown name is answered with the nearest ones.

Measured on a live demo with a real model on 2026-11-25:

- With a plain prompt the model spent rounds 1 to 5 on ``get_page_content`` and
  four ``search_code`` calls, never called the draft tool, and hit the round
  limit. The search arguments are redacted in the trace, so why it searched is
  not known.
- Told to guess names, each wrong name suspended the run on an approval card
  that showed only "Refused: …". A person had to approve that card before the
  model read the refusal. One element took four such cards.

:ref:`ADR-262 <adr-262>` has the interface for the second case. A tool that
refuses a call whatever the approver decides says so before the approval
(:php:`RefusesBeforeApprovalInterface`), and the run does not suspend. The
draft tool used it for tables TYPO3 does not version, not for the refusal of
its plan.

That interface let the loop execute the refused call and relied on
``execute()`` repeating the refusal. The first refusals rested on
configuration. The refusal of a plan rests on database rows and on third-party
processors. Between the approval scan and the execution an earlier call of the
turn, or another process, can change what it rests on. ``execute()`` would then
write, and nobody would have approved it.

.. _adr-328-decision:

Decision
========

**The draft's plan answers before the approval.**
``CreateContentElementDraftTool::refusalBeforeApproval()`` answers, after the
refusal for a table without versioning, with the refusal of ``plan()`` for the
acting user. ``execute()`` asks the same two first, returns them unchanged and
writes the plan it has just computed; the card shows the same text. The answer
is null, and the call waits for approval, where it is not known: without an
acting user, in a process without the environment a write needs, and in a run
that does not record its draft workspace, whose card carries that refusal
(:ref:`ADR-260 <adr-260>`). The refusal for a missing backend environment is
not part of the answer: it describes the process, and the scan and the
execution can run in different ones.

**A call that skipped the approval because its tool refused it is never
executed.** The loop marks it when it scans the turn and settles it as it
settles the calls an invocation rule refuses (:ref:`ADR-293 <adr-293>`): the
call passes the gates any call passes, so a denial is recorded as before; then
the tool is asked again. If it still refuses, its refusal is the result. If it
no longer refuses, the result is ``requires approval that was not given``, the
history entry is ``denied``, and a ``write_unapproved`` governance event with
the reason ``refusalLifted`` is recorded. ``execute()`` is called in neither
case. This holds at every place a call can run without a card:

- in the turn itself,
- beside the pending call of a guided process,
- on the resume after a question was answered.

**On the resume of an approved turn a refusal the card showed stays.** The
rules of :ref:`ADR-266 <adr-266>` are unchanged: a refusal that no longer holds
when the cards are compared pauses the run again, and a call refused at that
moment stays refused whatever an earlier call of the turn changes. The stored
preview now says that the tool refused the call (``refusedByTool``), so a tool
that stops refusing after the comparison does not get its call executed either.

**A refused call leaves the records it left before**: its trace step, the
``execute_tool`` span, the error outcome in the invocation history and no
governance event. The refusal stands in for the execution at the place where
the tool would have been called.

**The question is asked in the environment of the write.** In a worker the
loop sets the backend user and the language service around a write
(:php:`WorkerWriteEnvironment`, :ref:`ADR-281 <adr-281>`). The refusal is asked
there too. TYPO3's command line provides both, but for a user that belongs to
nobody (:php:`CommandApplication`, TYPO3 14.3.12).

**The names are in the acting user's language whoever asks.** A processor's
labels are the names, and it may read them from the ambient language service.
:php:`ProcessedSelectItems` sets the language service of the acting user, as it
sets the user, while the processor runs. The card, the refusal, the write and
the list then name the same choices in a backend request and in a worker.

**A model can ask for the choices.** The read-only tool
``list_content_element_choices`` takes a content type, its child-record column
and a column, and lists the names the draft takes for it. ``set`` names other
selects of the record the choices depend on; without it they are listed per
choice of that select. ``search`` takes up to ten searches separated by a comma
(``sun, bike, phone``) and keeps the names that contain every word of one of
them; a search without a hit is answered with its nearest names beside the hits
of the others. One call looks for several icons, because a run has few rounds.
Type, records, column and choices are asked through the traits and
:php:`ProcessedSelectItems` the draft uses, as the acting user. A cut list names
how many of how many are shown. The answer differs for no choices, an unknown
name, a missing permission and a processor that failed. The tool is in the
``content`` group, enabled by default and usable by non-administrators. Its
data class is editorial content, like the draft tool's: the names are values of
a content element's form. The ``structure`` group is internal configuration,
which a strict trust zone withholds. The draft tool's description and its
refusal of an unknown name mention it.

.. _adr-328-consequences:

Consequences
============

● A wrong icon name no longer stops the run for a person. The model reads the
refusal in the next round.

● A model can look up a name instead of guessing it or searching the code.

● A call that skipped the approval cannot write, whatever its tool's
``execute()`` does. The contract that ``execute()`` asks the refusal first stays.
Approved calls reach it.

✕ A wrong guess now costs a round of the run's round limit, where the card
waited for a person without one.

✕ A refusal reaches the model without a person having seen it. Nothing is
written, and the run's timeline shows the refused call.

✕ The plan runs during the scan, so the processors of computed selects run
before any approval. They ran for the card before.

✕ The plan is computed more often. A refused call computes it at least twice, in
the scan and when it is settled. A single approved draft computes it six times,
where it was three: in the scan, for the card and for the card's mark; on the
resume for the comparison, for the refusal and for the write. A card that is
shown again adds one for its mark.

✕ A call whose refusal an earlier call of the same turn lifts is answered with
the missing approval and has to be made again.

✕ A run that was suspended before this change has no ``refusedByTool`` mark in
its stored cards. For it the rules of :ref:`ADR-266 <adr-266>` apply alone.

✕ ``list_content_element_choices`` names no page. ``TCEFORM`` rules, those of the
default page TSconfig included, and a processor that reads the page are applied
by the draft only. The list can hold names the form of a page does not show and
the draft refuses. The draft then answers with the nearest names.

✕ The tool answers for computed selects of child records only. The values of a
FlexForm's and a plain select are in the draft tool's description or its
refusal.

✕ The tool is in the ``content`` group and the draft tool in ``editing``. A
configuration that allows ``editing`` alone does not offer it. The descriptions
say "where it is offered".

✕ A name that holds a comma is found by a part of it without the comma only.

✕ Whether a model calls the new tool in place of searching the code is not
measured.
