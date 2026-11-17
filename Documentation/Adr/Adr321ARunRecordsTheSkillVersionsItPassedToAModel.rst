.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-321:

======================================================================
ADR-321: A run records the skill versions it passed to a model
======================================================================

:Status: Accepted
:Date: 2026-11-17
:Authors: Netresearch DTT GmbH

Context
=======

When two runs of one configuration answer differently, the cause can be a
changed skill, a changed model or a changed input. The model side is
recorded per call (:ref:`ADR-153 <adr-153>`, :ref:`ADR-174 <adr-174>`). The
skill side was not.

A skill version is identified by its digest (:ref:`ADR-214 <adr-214>` item 1),
and the versions a run holds as instructions are its pins (item 6). The pins
were stored only in the resumable state of a waiting run, which the terminal
settle clears (:php:`AgentRunRepository::finishRun()`); a run that never
paused stored none. Only the process skill was recorded for good, as the
run's lineage (:ref:`ADR-228 <adr-228>`), and that record is written before
the run executes, so it proves the bound state, not that a model received
the text. Context skills, the delimited block, had no pin at all. A load was
recorded as a tool step whose arguments and result are content, so the
default ``metadata`` privacy level kept only the tool's name.

Decision
========

1. **A record per send.** Before each model call, the loop records the skill
   versions that call carries and no earlier call of the segment carried, as
   a step of kind ``skill_usage`` (:php:`RunStep::KIND_SKILLS`) in the round
   of that call, ahead of its request step. A version whose segment ends,
   pauses or fails before such a call is not recorded as passed. ``passed``
   means handed to the call: a call the manager refuses before the provider
   (an input guardrail, the budget, the trust-zone gate) keeps the entry, and
   the missing model-answer step after it shows that no answer came. So does
   a run cancelled in the moment between the entry and the request step. The
   check whether another turn ended the run's process (:ref:`ADR-228
   <adr-228>`) does not run on the usage step, so it stops a turn at the
   request step that follows instead. An entry counts as passed to a call
   when a request step of the same round follows it.
2. **What enters.** A version enters a segment's ledger
   (:php:`SkillUsageLedger`) when the composition put it into the prompt —
   instruction, process, or a context skill of a block that was placed —
   when a load appended it, or when a resumed segment holds its pin. A skill
   that was only attached, only offered in the load catalogue, or dropped by
   the budget or the integrity check never enters.
3. **Loads.** A successful load is recorded as ``loaded`` in the round of the
   load, before the tool step that answers it. The next model call records it
   as ``passed``.
4. **What an entry holds.** Skill uid, source uid, version digest, the
   skill's identifier, role (``instruction``, ``process``, ``context``),
   inclusion (``composed``, ``loaded``, ``carried``), state (``loaded``,
   ``passed``), and the approval that held when the entry was recorded: its
   uid, or ``none`` for a context skill, or ``unknown``. The version is fixed
   where it entered the run, and so is the role of a composed version; the
   approval is read when the entry is recorded
   (:php:`SkillPinCheck::heldApproval()`), and a loaded or carried version
   takes its role from that approval (a process or an instruction;
   ``instruction`` when none is found).
5. **No text.** No name, description or body is part of an entry. The
   identifier is the record's identifier, never the approved name a process
   section falls back to; it is text an administrator or a skill source
   chose, of the same kind the lineage already stores (:ref:`ADR-228
   <adr-228>`), not a code. The
   privacy filter strips content by key, so the step reaches the event stream
   unchanged at every level; that is safe only because nothing in it is
   content, and the tests assert the entry's key set.
6. **Through the trace.** The record is a :php:`RunTrace` step
   (:php:`RunTrace::recordSkillUsage()`, an additive ``@api`` method), not a
   runtime record, because the loop holds the trace and not the run handle.
   It is therefore persisted by the same path as every other step, kept after
   the run ends and purged with the run.

Consequences
============

●● For every persisted run, the event stream names which skill versions
reached which model call, including context skills and loads, at the
default privacy level.

● A changed skill shows as a changed digest between two runs, independent of
the model and the input.

● The resumed segment of a paused run names what it carries, with the
approval that held at the resume; a revocation still stops the resume first.

◐ The run timeline shows each entry as identity, digest prefix and codes.

◑ An entry states the first call that carried a version. A context section
the context window later pruned is not marked as gone.

◑ A context skill of a resumed segment is not recorded again, because the
resumable state holds only instruction pins; its entry earlier in the run
stands.

◑ A run that is not persisted (no run handle, the lean test wiring) records
nothing, like its other steps.

◑ The record says nothing about whether the version was tested, and the
run's answer may still differ with identical versions, model and input
(:ref:`ADR-271 <adr-271>`).
