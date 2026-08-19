.. include:: /Includes.rst.txt

.. _adr-179:

==============================================================================
ADR-179: A forced source that is dropped is recorded on the run
==============================================================================

:Status: Accepted
:Date: 2026-08-19
:Amends: :ref:`ADR-175 <adr-175>` (which made the two source kinds agree on
    WHICH sources enter a run, and left the silence when one does not)
:Authors: Netresearch DTT GmbH

Context
=======

A run queued with a forced snippet or skill resolves that set again when it is
dequeued. :ref:`ADR-175 <adr-175>` settled that both kinds resolve enabled-only
there, because dequeuing is composition and a source an operator switched off
must not enter a prompt being assembled now.

What it did not settle is what the operator is told. Today: nothing. The source
is dropped, the run proceeds without it, and the only way to notice is to
compare the queued request against the transcript. The run does not do what the
person who queued it asked for, and nothing says why.

The silence is now uniform rather than lopsided — before ADR-175 a snippet
disappeared and a skill survived — which is what makes it a question with one
answer instead of two.

Decision
========

**Recorded on the run, not only in a log.** A log line answers "did it happen",
which serves support. The question an operator actually has is "why did this
run behave differently from the one I queued", and that is asked at the run.
The record therefore travels on :php:`RunAugmentation` from the rehydration
that drops it, and is written through :php:`RunTrace` — the same channel
:ref:`ADR-151 <adr-151>` uses for context accounting, so it lands in the step
list ``Backend/AgentRun/Show.html`` already renders. No new surface is invented
for it.

**The run is not refused.** Refusing a queued run whose forced source vanished
is defensible in principle — nothing has been sent, so refusing costs only a
message. It is rejected because of who pays: switching off a snippet is
routine operator maintenance, and making it fail other people's queued work
turns a safe action into one nobody dares take. A run that proceeds without a
source and says so is recoverable; a refused run is somebody else's incident.

**"Deleted" and "switched off" read differently.** Both resolve to nothing
today and a single "dropped" would flatten them. They are different operator
actions with different remedies — a deactivated record can be switched back on,
a deleted one cannot — and a reader who cannot tell them apart has to go
looking. The record names which of the two applied, per uid.

What this does not do
=====================

**It does not change which sources enter a run.** :ref:`ADR-175 <adr-175>`
decides that and is untouched. This decides only what is said about the
difference.

**It does not touch the resume path.** :ref:`ADR-166 <adr-166>` and
:ref:`ADR-175 <adr-175>` keep a deactivated source resolving on a resume, on
purpose — its text is already in the transcript. Nothing is dropped there, so
there is nothing to report, and adding a report would imply otherwise.

**It does not notify anyone.** The record is readable at the run; it does not
push. Whether a dropped source deserves a notification is the same unanswered
question as every other pending decision in this extension, and answering it
here for one case would settle it by accident.

**It does not record a source that was never requested.** The comparison is
against the uids the run was queued with. A caller that sends an unknown uid
gets it reported as unresolved like any other, because from the run's side
those are the same event.

Consequences
============

An operator reading a run sees, next to the sources that were injected, the
ones that were asked for and did not arrive, and which of the two things
happened to each.

:php:`RunAugmentation` gains a field. It is ``@internal``, so no frozen surface
moves.

The playground's synchronous send composes the same way and gains the same
record. The queued path is where the gap between request and start is wide
enough to matter, but a source can be switched off during a playground round
too, and one path reporting while the other stays silent would be the
asymmetry ADR-175 just removed.

Revisit when
============

- Notifications arrive. This record deliberately does not push, and that
  becomes a choice worth re-taking rather than an omission.
- A third reason a forced source fails to resolve appears — a permission, a
  workspace, a language overlay. The two-way split above would then be
  hiding a case rather than distinguishing one.
