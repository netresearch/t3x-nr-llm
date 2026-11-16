.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-302:

============================================================================
ADR-302: The final answer of an editorial run is checked before it is stored
============================================================================

:Status: Accepted
:Date: 2026-11-15
:Amends: :ref:`ADR-288 <adr-288>` (the pipeline judge leaves the answer of such a run to the end check)
:Authors: Netresearch DTT GmbH

Context
=======

An A2A client asks for a text it uses outside TYPO3: a social post, a reply
to a comment. :ref:`ADR-300 <adr-300>` added a service that checks such a text
against the rules of a configuration at every length. Nothing called it. The
pipeline checks only what a writing tool would store in a run that offers
tools, so the answer of a run that only read is not checked at all, and the
judge skips a text under 30 words.

Two more needs follow from external editorial work. An editor changes a
draft outside nr-llm and wants the changed text checked, not a new one
written. And the check must not run under the run-row lock that completes an
A2A task (:ref:`ADR-282 <adr-282>`): a judge call there would hold the lock for
the length of a model call.

Decision
========

**An end-of-run check.** The run executor asks the services tagged
``nr_llm.run_output_check`` whether a run's answer is checked. The A2A
implementation answers yes when the task's profile names a handler that
declares itself end-checked. After the loop, before the transaction that
completes the run, the executor hands the final answer to that check. It runs
outside every transaction and renews the run's lease first. On the three
paths a run takes (a start, a queued run, a resume) the decision is made once,
in the same place.

**What is checked.** The text the content artifact will carry: the answer as
the current answer level keeps it, shaped by the handler. A level that keeps
no answer delivers nothing, and nothing is judged. The check is the service of
:ref:`ADR-300 <adr-300>` with the run's configuration, the run's user and the
run's uuid as correlation id.

**Its result.** A run event ``output_check`` keeps the report without any
passage of the text: status, reasons, digests, and the rules and terms found
broken. A term found and the digest of a short text still say what the text
held, so below ``redacted`` (the run's event level, when the event is written
and when the level tightens later) the event keeps status and reasons only. A
run event ``rule_state`` keeps the rule-state digest at the start of the run.
A blocking report (a broken term, a rule broken under a judge in block mode, a
check that could not run) stops the run through the guardrail arm, as a
policy stop with a reason that names lists and rules, never a term or a passage. The task
ends ``FAILED``.

**One judge call per text.** While the loop of such a run runs, the pipeline
judge leaves the run's answer to the end check. Only the answer: the
arguments of a writing tool are judged in the call that proposes them, as
before. The flag lives in a container-shared service the executor sets; no
request field and nothing a client sends switches it.

**Two handlers.**

- ``nr_llm.editorial_text`` writes the text from the client's message, with
  the read-only tools the profile allows, and is end-checked. It offers no
  writing tool and no tool that asks the client a question (``ask_choice``
  and the like): the question of a pause reaches the client before the final
  answer exists, so it would carry model text past the check, and a write
  would stay behind a text the check stops.
- ``nr_llm.editorial_check`` checks the text the client sends. The run takes
  the submitted text as its answer: no generator call, no tool call, the text
  byte for byte. A comment or a draft that asks for a tool gets none.

``nr_llm.text`` is unchanged.

Consequences
============

- A short answer, and the answer of a run that only read, are checked when the
  profile chooses an editorial handler. Such a run cannot ask the client a
  question in this version; a missing fact is a refusal or a gap the text
  names.
- A run checked at its start stays checked: a segment whose profile no longer
  names an end-checked handler fails. The decision is made when a worker first
  takes the run, not when the task is accepted: an administrator who changes a
  profile's handler while a task waits in the queue changes how that task
  runs. A result whose check could not be stored
  is not delivered either.
- A runtime built by hand without the tagged checks (``AgentRuntime`` falls
  back to an executor without them, as it does for the start and resume
  guards) does not check; the container always wires them. A profile with ``nr_llm.text`` behaves
  as before.
- A changed draft can be checked without being rewritten; the judge is the
  only model call.
- The judge call happens before the completing transaction. The completion
  itself makes no provider call.
- The client of a blocked task sees ``The task failed.``; the reason reaches it
  with the report artifact of ADR-301.
- A judge in block mode that cannot answer stops every text of that profile.
  That is the point of block mode.
- Under the answer level ``redacted`` the check-only handler judges the
  redacted text, because that is what is delivered, not the text the client
  sent.
- Known limits, accepted after review (each needs an administrator's edit or a
  lost lease inside one worker's window between the check and the completion):

  - An answer level loosened between the check and the completion delivers a
    text the check read at a stricter level, or not at all at ``metadata``. Its
    report says ``not_checked`` (the digests differ), so it is never passed,
    but it is delivered.
  - A run whose settle was lost after a blocking check and that a second
    attempt stops by another guardrail before its own check reports the stop
    with the record of the first attempt. Its state is ``needs_human_review``.

Revisit when
============

- A handler needs structured input (channel, site, language per profile). That
  needs a profile field and changes ``ext_tables.sql``.
- A paused run must keep the rule texts it started with.

Alternatives considered
=======================

- **A flag on ``AgentRunRequest``.** Rejected: the request is ``@api`` with a
  frozen constructor, and a field the request carries could be set by whoever
  builds the request. The server derives the decision from the task's profile.
- **Check inside the A2A completion.** Rejected: the completion holds the
  run-row lock, and a judge call there holds it for a model call.
- **Run the check in the TCK-style request path.** Rejected: a judge call
  would hold the HTTP request.
