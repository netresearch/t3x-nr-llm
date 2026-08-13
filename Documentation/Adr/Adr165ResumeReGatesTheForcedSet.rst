.. _adr-165:

============================================================
ADR-165: A resumed run re-gates its forced sources
============================================================

:Status: Accepted
:Date: 2026-08-13
:Amends: :ref:`ADR-164 <adr-164>` (its "does not cover a resumed run" gap) and
    :ref:`ADR-084 <adr-084>` (a further field on the suspended state)
:Authors: Netresearch DTT GmbH

Context
=======

:ref:`ADR-164 <adr-164>` makes a run's forced snippets and skills bind against
the ADR-144 trust ceiling, and named the one place it did not reach:
:php:`ToolLoopService::resume()` and :php:`resumeWithInput()` re-enter the loop
with assembly skipped and no augmentation, because
:php:`SuspendedRunState` did not carry one. Issue `#761`.

The bound was narrow and worth restating, because it decides how much machinery
this justifies. The forced text enters the transcript at assembly time, where
ADR-164 gates it, so a resume injects nothing new. What a resume could miss is a
ceiling that changed *while* the run was suspended: the configuration re-pointed
at a less trusted provider or given a fallback that reaches one, or
``dataClassEnforcement`` switched from ``observe`` to enforcing. Both of those
already re-gate the configuration's *own* sources, because
:php:`assertContextPermitted()` runs on every pipeline call against the live
configuration. Only the forced half was frozen out.

Decision
========

**The suspended state carries the forced set as uids.**
:php:`SuspendedRunState` gains :php:`$forcedSnippetUids` and
:php:`$forcedSkillUids`, written at both suspend sites and read on both resume
paths. A row persisted before this ADR has neither key and rehydrates with
none — the pre-ADR-165 behaviour, never a refusal to resume. That is the same
back-compat shape :ref:`ADR-136 <adr-136>`'s call previews use, for the same
reason: a running installation has such rows in its database.

**Uids, and re-loaded on resume.** The alternative was to freeze the
*classification* at suspend time and re-compare it against the live zone. That
would also have closed the two risks above, without a repository lookup. Uids
win because the resume then reflects the record as it is now: an operator who
raises a snippet's class while a run is suspended means it, and a frozen copy
would ignore them. It is the same identity-over-snapshot choice
:php:`AgentRunRequestCodec` already makes for a queued run's entities.

**The state, not the queued request.** :php:`AgentRunRequestCodec::rehydrate()`
can already recover a queued run's forced set from ``queuedRequest``, which
looks like it would do the job with no new field. It would not:
``queuedRequest`` is null for a run started synchronously from the playground —
and that is precisely the path that motivated ADR-164. The suspended state is
written by both.

**Re-loaded in the loop, not in the coordinator.** :php:`ToolLoopService` takes
the two repositories as optional collaborators and rebuilds the augmentation
itself. :php:`ResumeCoordinator` has two call sites into the loop and would have
had to thread it through both; the loop has one place where a resumed run
re-enters. Optional, like every other collaborator on that constructor: a
construction without them keeps the pre-ADR-165 behaviour rather than failing.

.. _adr-165-not:

What this does not do
=====================

**It does not resurrect a deleted source.** A uid that no longer resolves —
the snippet was deleted while the run was suspended — contributes nothing to the
classification. The transcript still carries its text, so for that one source
the answer degrades to the pre-ADR-165 one. Refusing the resume instead would
strand a run over a record an operator deliberately removed, and would make
deleting a snippet a way to break running work.

**It does not re-gate anything else about a resume.** The transcript, the
allow-list and the options are restored exactly as :ref:`ADR-084 <adr-084>`
established. Only the forced set is added to what the ceiling can see.

Consequences
============

A run suspended for approval or typed input, whose configuration is re-pointed
at a less trusted provider before the approver answers, is now refused on resume
rather than sending. The refusal names the forced source, as
:ref:`ADR-164 <adr-164>` requires.

An installation that has classified nothing is unaffected, and a run that forced
nothing hands over null exactly as before — the gate keeps its
configuration-only path rather than folding an empty list.
