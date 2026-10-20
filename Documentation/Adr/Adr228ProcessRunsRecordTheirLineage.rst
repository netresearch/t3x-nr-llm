.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-228:

=====================================================================
ADR-228: A process run records its lineage; the runtime derives pins
=====================================================================

:Status: Accepted
:Date: 2026-10-18
:Amends: :ref:`ADR-214 <adr-214>` (item 10: the shape of the invocation,
    continuation, catalogue and end-of-process API, where the lineage of a
    run is stored, and what this change leaves out)

Context
=======

:ref:`ADR-214 <adr-214>` item 10 names the public API of a guided process in
working names: an invocation on :php:`AgentRunRequest`, a continuation that
names only its predecessor run, a catalogue for the slash-command menu, and a
guarded way to end a run. Item 6 says the runtime derives the pins, the
invocation and the subject record of a continuation from the predecessor, so
that no caller hands in a pin. That needs a place where a run keeps what it
started with.

The run table has no column for it, and this change adds no schema: a
continuation must read what its predecessor stored, and an installation that
has not run a schema update must not lose the process between two turns.

Decision
========

1. **The invocation.** :php:`AgentRunRequest` gains two trailing optional
   arguments: ``invocation`` (:php:`SkillInvocation`: a skill uid and an
   optional :php:`SubjectRecord` of table, uid and language) and
   ``predecessorRunUuid``. Both travel through the queue codec; a stored value
   that cannot be read refuses the run instead of running it as an ordinary
   turn. Neither carries a pin.

2. **The start checks run in the executor**, once the run has a row and an
   acting user — at an interactive start and again at worker pickup —
   through :php:`ProcessRunPreparer`. They are the checks of ADR-214 item 10,
   in this order: the actor may use the configuration
   (:php:`ConfigurationResolver::actorMayUse()`); for a continuation, the
   predecessor exists, the actor is its initiator or an administrator, and a
   predecessor holding a process is terminal; an invocation of another skill
   while a process is held is refused; the skill is attached, enabled and not
   orphaned; its current version is an approved instruction marked a
   process; the actor is not a service account and the configuration does
   not set ``require_second_approver``; the acting user may read the subject
   record. A refusal is a :php:`ProcessInvocationRefusedException` carrying a
   :php:`ProcessInvocationRefusal` case; it settles the run failed, and a
   queued run is dead-lettered, not retried. An executor built without a
   preparer refuses every invocation and continuation.

3. **The subject check is the acting user's**, not the global backend
   user's: the table (:php:`TableReadAccessService::canReadTable()`), the
   language, the record not deleted, and for an editor the page's web mount
   and page-show permission. A record on the root level is for
   administrators only. :php:`BackendUtility::readPageAccess()` is not used
   because it reads the global backend user.

4. **The lineage is an event.** What the run started with is stored as an
   event of kind ``lineage`` in the run's event stream: the configuration uid,
   the process pin, the subject record and whether the process was released.
   It holds uids and a digest only, so it is stored at every privacy level,
   and it is purged with the run. The latest lineage event counts. A
   continuation that cannot store its lineage is refused, and one whose
   predecessor's lineage cannot be read is refused rather than continued
   without the process.

5. **The runtime sets the pin.** The executor puts the prepared pin on the
   run's :php:`RunAugmentation` and replaces any pin a caller put there. A
   run whose caller sent no augmentation gets one that only carries the
   process (``bakesSystemPrompt: false``), so its prompt is assembled as if
   there were none.

6. **The pin is the source, not the record.** The loop composes the process
   from its approval snapshot and joins the snapshot's declared tools to the
   run's allow-list (:php:`SkillToolAllowList::withDeclaration()`), at start,
   on a queued run's stored list, and on resume. A continued or resumed tour
   therefore keeps the text and tools it was approved with after its record
   was edited. :php:`AllowedToolsResolver::resolveForRun()`'s
   ``$invokedSkills`` is not used for the pin: it reads the live record, and
   an edited record would leave the tour with no tools. The pin check decides
   whether the tour may go on at all; a pin it refuses stops the turn, and
   the turn records its lineage without the process so the next one goes on
   without it.

7. **Ending a process.** :php:`AgentRuntimeInterface::endProcess()` is
   called by the run's initiator only. A waiting run is cancelled through the
   same guarded transition as ``cancelIfWaiting()``; a queued or running run
   is left alone and the caller asks again; a terminal run keeps its status.
   The run then records its lineage as released, which a continuation does
   not carry on. Applied writes stay. Anyone else, and an unknown run, learn
   nothing.

8. **The catalogue** (:php:`ProcessSkillCatalogue::forActor()`, AJAX route
   ``nrllm_process_skills``) lists the skills a :php:`SkillInvocation` would
   start: attached, enabled, not orphaned, admitted by the trust floor, and
   whose current version is an approved instruction marked a process. A
   service account, an actor who may not use the configuration, and a
   configuration that sets ``require_second_approver`` get an empty list. Its
   names and descriptions come from the approval snapshot. The route answers
   404 for an unknown or deactivated configuration.

9. **Highlight targets.** The subject record is on the tool context, and the
   builtin ``highlight_record`` accepts only the subject record itself or,
   for a page, a non-deleted content element on it in the subject's language
   or in all languages, if the acting user may read ``tt_content``. The run
   records a ``highlight`` step with the table and uid. The builtin ships
   disabled.

Consequences
============

- Only the process pin, the subject record and the configuration are stored
  in the lineage. Pins of other skills are composed again each turn from
  their attachment; a loaded skill (ADR-214 item 3) has no lineage until the
  load mode exists.
- The catalogue lists processes only, without a load mode or a flag for
  untrusted descriptions: every listed skill is an approved instruction.
- A disabled pinned process stops a continuation, as it stops a resume:
  :php:`SkillPinCheck` treats a disabled record like an orphaned one. ADR-214
  item 10 waives ``enabled`` for a continuation. Keeping the stricter rule
  means a synced process whose upstream changed (the sync disables it) ends
  the tour; changing it belongs in the pin check, for resume and
  continuation alike.
- ``endProcess()`` admits the initiator only, where ``cancelIfWaiting()``
  also admits an administrator: ending a person's guided process is that
  person's decision.
- A queued run is checked at worker pickup, not at enqueue; a refusal there
  dead-letters the run.
- A run's status does not yet expose whether it holds a process, and the
  admission headroom and the progress builtin of ADR-214 are not part of
  this change.
- :php:`ToolLoopServiceInterface::runLoop()` still accepts an augmentation
  with a process pin from any caller. The loop composes only an unrevoked
  process approval for it, but the actor and subject checks of item 2 run in
  the executor; a caller that drives the loop directly is trusted like any
  other caller of the loop.
