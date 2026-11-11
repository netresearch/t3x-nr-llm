.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-287:

=========================================================
ADR-287: An approved write that failed decides nothing
=========================================================

:Status: Accepted
:Date: 2026-11-11
:Amends: :ref:`ADR-236 <adr-236>` (an approved card holds its record only when its write took)
:Authors: Netresearch DTT GmbH

Context
=======

:ref:`ADR-236 <adr-236>` keeps a record out of a repeated batch once its run
ended in a decision: approved, rejected, or declined by the model. The store
tells these apart by status and reason, and an approved run and a declined one
both end ``completed`` with the reason ``completed``.

A run whose approved write fails ends the same way. The tool returns its error
to the model, the model answers, and the run completes
(:ref:`ADR-092 <adr-092>`, :ref:`ADR-191 <adr-191>`). On the demo on
2026-11-06 the batch user lacked the right to work in the live workspace; the
approved alternative text of file 2 was refused, the field stayed empty, and
the batch counted the record as decided. No later schedule offered it again,
and the Agent Runs module answers such an approval with "Approval recorded;
the run continued".

Decision
========

**A call an approval released and that failed is no decision about the
record.** The run's event stream says which calls an approval released: those
between an ``approval`` event with ``approved`` true and the next model round.
One of them that failed, as the run timeline shows a failure — ``toolIsError``
true and an outcome other than ``cancelled`` — means the person said yes to a
write that did not happen. The batch tries such a record again, like one whose
run failed. :php:`ApprovedCallFailure` reads the stream; the batch reads it
for the completed runs of its prefix only.

A denial releases nothing, so a turned-down card holds its record as before. A
call that failed before any approval and that the model corrected is not
counted, nor is a cancelled one. An approved write of an empty text took, so an
image approved as decorative stays decided. Only the last decision of a run
counts: a later card of the same run that was denied, or approved with a call
that took, decides the record although an earlier approved call failed.

**The approver hears it.** The Agent Runs module reads the same question of the
steps the approval returned and answers with a warning, "Approval recorded, but
the approved tool call failed", instead of "the run continued".

**The status stays.** The run ends ``completed``, as ADR-092 and the failed-call
marker of the runs list decided; dashboards and the queue read the status, and
the marker beside it already says that a call failed.

Consequences
============

✓ A record whose approved write failed comes back on the next schedule with a
new card, once the cause is fixed. If it is not fixed, the card fails again,
and each new card still needs a person's approval.

✓ The batch, the approval message and nr-mcp-agent's "nothing was saved" note
read one rule from the same events.

✗ The batch reads the event stream of every completed run under its prefix,
in queries of 500 runs each. A run purged from the store takes its decision with
it, as before.

✗ An approval that released several calls counts as failed when one of them
failed, although another may have written. For a batch run, which proposes one
record, that cannot happen; the record's field is checked for emptiness first
in any case.
