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
   predecessor exists and the actor is its initiator or an administrator
   (one refusal for both, so it does not tell whether another user's run
   exists), its lineage can be read, and a predecessor holding a process is
   terminal; an invocation of another skill while a process is held is
   refused; the skill is attached, enabled, not orphaned and admitted by the
   trust floor the catalogue applies; its current version is an approved
   instruction marked a process; the actor is not a service account and the
   configuration does not set ``require_second_approver``; the acting user
   may read the subject record; a derived pin still holds (a pin that no
   longer does is refused as ``pin_withdrawn``, after the run recorded its
   lineage without it).

   The checks read the actor's admin flag and groups from the acting
   backend user as it is now, not from the actor the request froze: a
   queued request is checked at pickup, and a user demoted or removed from a
   group in between no longer has what the request claimed. An acting user
   that is missing, or is not the actor, grants neither. A refusal is a :php:`ProcessInvocationRefusedException` carrying a
   :php:`ProcessInvocationRefusal` case. It settles the run ``failed`` with
   its class and the termination reason ``policy_denied`` — a continued
   process on a configuration that turned four-eyes on since with
   :php:`ProcessRunNeedsSecondApproverException` and ``approval_denied``, as
   on resume. A queued run is checked at pickup, not at enqueue; its refusal
   takes the same settle, outside the queue's recovery, so the initiator
   finds a failed run with a reason rather than a retry or a dead letter. An
   executor built without a preparer refuses every invocation and
   continuation.

3. **The subject check is the acting user's**, not the global backend
   user's: the table (:php:`TableReadAccessService::canReadTable()`), the
   language the user may edit, the record not deleted and live (the acting
   user a run resolves works in no workspace), and for an editor the page's web mount and
   page-show permission (one check: :php:`calcPerms()` answers nothing
   outside the mounts in TYPO3 13.4 and 14.3). The record's own language
   counts too: a translation row is the subject only in its own language; a
   row in the default language or in all languages may be worked on in any
   language the user may edit. A record on the root level is for
   administrators only. :php:`BackendUtility::readPageAccess()` is not used
   because it reads the global backend user. A resumed run carries the
   subject only while the same check still passes.

4. **The lineage is an event.** What the run started with is stored as an
   event of kind ``lineage`` in the run's event stream: the configuration uid,
   the process pin, the subject record, the process skill's identifier, the
   run that started the process (its origin), and whether the process was
   released.
   It holds uids and a digest only, so it is stored at every privacy level,
   and it is purged with the run. The latest lineage event counts. A
   continuation that cannot store its lineage is refused, and one whose
   predecessor's lineage cannot be read is refused rather than continued
   without the process.

5. **The runtime sets the pin.** The executor puts the prepared pin on the
   run's :php:`RunAugmentation` and replaces any pin a caller put there. A
   run whose caller sent no augmentation gets one that only carries the
   process (``bakesSystemPrompt: false``), so its prompt is assembled as if
   there were none. The augmentation's process members are ``@internal``:
   :php:`ToolLoopServiceInterface::runLoop()` still accepts them from any
   caller, and the public entry points — :php:`AgentRunRequest` through the
   runtime, interactive or queued — never carry a pin they did not derive.

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
   called by the run's initiator or an administrator, as
   ``cancelIfWaiting()`` is, so an administrator can end a process that is
   stuck; not by a service account or the holder of the approve grant. A
   waiting run is cancelled through the
   same guarded transition as ``cancelIfWaiting()``; a queued or running run
   is left alone and the caller asks again; a terminal run keeps its status.
   The run that started the process (its origin) records its lineage as
   released first, then the named run. A turn holds a process only while its
   origin still does, so from then on no turn of the conversation — earlier,
   later or continued in parallel — hands the process on, and the status of
   every such turn says it holds none. The origin is released only when the
   actor may end that run as well; otherwise the named run alone lets go of
   the process. A turn that already waits for a decision when the process is
   ended does not resume: its resume is refused as ``process_ended``. A turn
   that is already running stops before its next provider call or tool call,
   and is settled failed as ``process_ended``; until then it may finish the
   call it is in, and a tool that ran keeps its whole audit record, its
   write step included. A segment that lost its run writes nothing: at a
   step boundary the check runs after the lease guard, and before a tool
   call the check runs before the write fence and the stop is settled under
   the owner guard. The check fails closed: a run row or lineage it cannot
   read stops the turn. A turn that sends the
   invocation again after the end, or was queued with it before, starts the
   process anew as its own origin, through every start check. A
   release that fails leaves the named run holding, and a retry completes
   it. An origin that no longer exists ends the process for every turn; one
   the store cannot read refuses, as an unreadable lineage does. Applied
   writes stay. Anyone else, and an unknown run, learn nothing.

   The run status (:php:`AgentRuntimeInterface::status()`) says whether the
   run holds a process (``holdsProcess``) and names the process skill's
   identifier (``processSkill``), so a chat restores its tour header after a
   reload. It is read from the lineage, which records the identifier at
   start; an unreadable lineage leaves ``holdsProcess`` null. The flag is
   information for the client; the runtime decides nothing from it.

8. **The catalogue** (:php:`ProcessSkillCatalogue::forActor()`, AJAX route
   ``nrllm_process_skills``) lists the skills a :php:`SkillInvocation` would
   start: attached, enabled, not orphaned, admitted by the trust floor, and
   whose current version is an approved instruction marked a process. A
   service account, an actor who may not use the configuration, and a
   configuration that sets ``require_second_approver`` get an empty list. Its
   names and descriptions come from the approval snapshot. The route answers
   404 alike for an unknown or deactivated configuration and for one the
   user may not use, so it does not tell which configurations exist.

9. **Admission.** A process this run starts passes the window admission of
   ADR-214 item 7 with the other start sections, before the first provider
   call and measured against the model the send runs on — the start
   admission of the skill streams, which resolves a criteria-mode
   configuration's model. A process the run continues from its predecessor
   is not admitted again: its section is one the conversation already
   holds, and a long tour would otherwise fail once its history outgrew the
   headroom. The exception is a predecessor that never sent a provider call —
   the window refused it, it was a dry run, or it ended before the send: its
   section was never admitted, so its continuation admits the process as a
   start. The runtime marks a new
   start on the augmentation (``admitsProcess``).

10. **Highlight targets.** The subject record is on the tool context, and the
    builtin ``highlight_record`` accepts only the subject record itself or,
    for a page, a live, non-deleted content element on it in the subject's
    language or in all languages, if the
    acting user may read ``tt_content``. The run
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
- **Correction to ADR-214 item 10:** a continuation does not waive
  ``enabled``. A disabled pinned process stops a continuation, as it stops a
  resume: :php:`SkillPinCheck` treats a disabled record like an orphaned one,
  for resume and continuation alike. A synced process whose upstream
  changed (the sync disables it) therefore ends the tour.
- The catalogue has no load mode, and nr-llm has no progress builtin: the
  chat keeps its own progress.
- A caller that drives :php:`ToolLoopServiceInterface::runLoop()` directly
  with a process pin skips the actor and subject checks of item 2; the loop
  composes only an unrevoked process approval for it. Such a caller is
  trusted like any other caller of the loop, and the members are marked
  ``@internal``.
- A store error is retried only where a process starts or continues: the
  reads of its approvals, their source's trust and its subject record. The
  same reads inside the loop's skill composition, which every run shares,
  are not: a queued run that hits one there is dead-lettered as before.
- A resumed turn whose process approval cannot be read goes on without the
  process's declared tools, bounded by the tool list stored when it
  suspended. Its claim is already won, so a stop at that point would be
  final.
- A fault inside a guarded read that a retry cannot fix, such as a type
  error, is retried like a store error until the requeue budget is spent,
  and is then dead-lettered as retries exhausted.
