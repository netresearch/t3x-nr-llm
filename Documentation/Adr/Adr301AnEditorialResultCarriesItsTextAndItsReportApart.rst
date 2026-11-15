.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-301:

==================================================================
ADR-301: An editorial result carries its text and its report apart
==================================================================

:Status: Accepted
:Date: 2026-11-15
:Amends: :ref:`ADR-282 <adr-282>` (a completed or a stopped editorial task carries a report artifact)
:Authors: Netresearch DTT GmbH

Context
=======

The end-of-run check (:ref:`ADR-302 <adr-302>`) decides whether an editorial
text is delivered, and keeps its result as a run event. The client sees
neither: it receives the text, or ``The task failed.``. An editor needs both
the text to paste and the facts about it, and must not get one mixed into the
other: a client that joins all text parts of a task, or copies the first
artifact, must get the text and nothing else.

Three more facts must reach the client. A text the editor changes later is
another text; a report about the first version says nothing about it. The
rules can change while a run waits. And a completed task means the run is
done, not that the text may be published.

Decision
========

A task whose profile names an end-checked handler gets a second artifact,
``report``, after the content artifact. The content artifact keeps exactly one
text part with the delivered text. The report has exactly one data part, so a
client that joins text parts takes nothing from it. It holds:

- ``check``: the status (``passed``, ``violation``, ``not_checked``,
  ``failed``), whether it blocked, the reasons, and the findings: kind, list or
  snippet, term or rule line, and the passage of a broken rule;
- ``checkedTextDigest`` and ``deliveredTextDigest``: the SHA-256 of the text the
  check judged and of the text in the content artifact. When they differ, the
  status is ``not_checked``; a text never inherits the result of another one;
- ``ruleState``: the rule-state digest at the start of the run and at the
  check, and whether it changed;
- ``editorialState``: ``draft`` only for a text that passed, is the delivered
  one, and was checked against the rules of the start; ``needs_revision`` for a
  broken rule or term; ``needs_human_review`` for everything else. A text the
  client sent to ``nr_llm.editorial_check`` is a ``draft`` only at the answer
  level ``full``: at ``redacted`` the check read the redacted text, while the
  client publishes its own. It stands apart from the task state;
- the configuration whose text was checked and the configurations that judged
  it, named apart, and the answer level the report was built at.

The report is built inside the transaction that completes the task, under the
run-row lock, from stored records only: the latest ``output_check`` event of
the run, its first ``rule_state`` event, and the content artifact. No model is
called there.

**Passages follow the answer level.** The run event keeps no passage, and
below ``redacted`` (the run's event level) no term and no digest either. The
findings and passages come from the check of the same process, handed over in
memory, and
are kept at the answer level read under the lock: as they are at ``full``,
redacted like the answer at ``redacted``. A report has findings and passages
only for the text it is bound to (the delivered one, or the stopped one), and
only when the level is still the one the check read; otherwise it lists no
finding and says ``findingsWithheld``: a term or a passage found in a text
names what that text held, and a fragment redacted on its own may keep what
the whole text loses. Below that the task keeps no result
at all (:ref:`ADR-282 <adr-282>`), and a stopped task carries no report.

**A stop carries the report.** A task the end-of-run check stopped (a policy
stop whose latest check blocked, or a check that could not run) ends
``FAILED`` with the status text ``The editorial check stopped the text.`` and
the report as its one artifact; there is no content artifact. Below
``redacted`` a stopped task carries no report: even the terms it names would
say what the withheld text contained. An editorial task offers no writing tool,
so its ``writes`` artifact appears only when writes ran all the same (a
profile switched to an editorial handler during an approval); the line "No
write was executed." is left out beside a report, because a text part there
would be joined into the copied text.

Consequences
============

- A client can paste the first artifact as it is, and read the report to
  decide what to do with it. A changed text is checked again with the
  ``nr_llm.editorial_check`` handler.
- The report says ``passed`` only for the bytes it names.
- A completion in another process than the check finds no passages to hand
  over, and reports from the run event: without passages, and without
  findings where the event level scrubbed them. The worker completes the run
  it checked, so this is an edge.
- When the answer level tightens after the task completed, the stored report
  is scrubbed with the task content (:ref:`ADR-282 <adr-282>`): at
  ``redacted`` it is rebuilt as a data part with its editorial state, status,
  ``blocks`` and the known reasons only, lists no finding, keeps no digest and
  says ``findingsWithheld``; below it nothing is kept. Any data part can claim
  the report's kind (a client or a model writes data parts too), so the
  rebuild keeps nothing a part could smuggle through it.
- The report names no sources yet: the search calls of a run are not bound to
  the task's site and language (planned with the retrieval rule).
- Known limits, accepted after review:

  - A completion that does not reach the reporter (a lost lease, a result
    that cannot be kept, a rollback) leaves the handed-over report in the
    worker's memory until the run is checked again. Nothing is stored or
    sent; a long-running worker only holds it longer.
  - Whether a client text may be a ``draft`` is read from the profile at the
    completion, not at the check: a profile switched from the check handler
    to the text handler in between can make a text read at ``redacted`` a
    ``draft``.

Revisit when
============

- A refusal before the run starts must give its reason (after stage 5b).
- Sources must be listed, once retrieval is bound to the profile.

Alternatives considered
=======================

- **Hints inside the text artifact.** Rejected: the text is pasted as it is,
  and a hint would be pasted with it.
- **A second text part in the content artifact.** Rejected: a client that
  joins text parts would mix the report into the text.
- **Passages in the run event.** Rejected: the event would keep text the
  answer level withholds; a passage redacted on its own may keep what the
  whole text loses.
