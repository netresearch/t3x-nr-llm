.. _adr-150:

==============================================================================
ADR-150: A submitter may only feed a tool they could run, on the turn they saw
==============================================================================

:Status: Accepted
:Date: 2026-08-11

Context
=======

:php:`ResumeCoordinator::submitInput()` authorised the submitter with the
:php:`BackendUserGrant::AGENT_APPROVE` grant and nothing else. It never checked
them against the tool whose input they were supplying, while
:php:`ToolLoopService::resumeWithInput()` executes the pending calls under the
RUN OWNER's identity (:ref:`ADR-083 <adr-083>`). A non-admin holding the grant
could therefore satisfy an admin-only tool that then ran on the owner's
authority — the same confused deputy the approval path closed in
:ref:`ADR-133 <adr-133>` (#622).

It also had no turn binding. The approval path pins a decision to the turn the
operator reviewed (:ref:`ADR-132 <adr-132>`); the input path had no equivalent,
so a submission could not be shown to belong to the form it was written for.
Worse, the state that EXECUTED was the pre-claim copy: a lost race let another
submitter resume the run and suspend it again on a different turn, and the
values were then fed into that one.

Both gaps were latent, not shipped: no builtin implements
:php:`RequiresInputInterface`, and a functional tripwire
(:php:`InputPauseCoverageTest`, #680) pins the empty list. #690 is the open half
of #649, and this record answers the two questions that tripwire poses.

Why ADR-133's gate could not simply be copied
=============================================

**Because its selection rule would select nothing here.** ADR-133 checks the
pending calls that DECLARE a write, because an unattended write is what must not
happen. An input-requiring tool declares no effect at all: the input and
approval markers are mutually exclusive at registration
(:ref:`ADR-105 <adr-105>`) and it is the write EFFECT that implies approval
(:ref:`ADR-134 <adr-134>`), never input. A write filter on this path is a gate
that never fires.

The danger on this path is also not the same one. It is not that something
changes state unattended — it is that a user who may not run a tool supplies the
ARGUMENTS it runs with, under someone else's identity, and those values flow
back into the model context as untrusted content.

Decision
========

**The submitter passes the same gate the execution would, on EVERY pending
call.** :php:`ResumeCoordinator::submitInput()` resolves the SUBMITTER's live
backend user and asks :php:`ToolCallPolicy::decide()` about every call of the
pending turn — not only the writing ones — plus the state's declared
:php:`inputToolName`. The declared tool is appended only when the pending calls
do not already name it — normally the turn's own call covers it, so no tool is
asked about twice; the append exists so that a degenerate state whose pending
calls do not name it cannot become an ungated submit. A denial refuses the
submission with
:php:`SubmitterNotPermittedException`.

**One gate implementation, two selection rules.** The walk that asks the policy
is shared with :php:`approverRefusal()`; only the predicate that picks the calls
differs (``writesOnly`` true for the approval path, false here), and the two
paths raise their own exception so the message and the surface's wording match
what the user was doing. There is no second notion of "may run this tool".

**Execution identity is unchanged.** ADR-083 stands: the turn still runs as the
run owner. This is a second, independent condition on the SUBMISSION.

**A service account may not supply input at all**, and neither may a human whose
uid no longer resolves to an enabled backend user — the same fail-closed
reasoning as ADR-133, and here it is not even partial: there is no read-only
half of the input path to keep working, because an input pause always exists to
run one specific tool.

**A submission must name the turn its form was rendered from.**
:php:`InputSubmission` gained an optional-in-signature, mandatory-at-runtime
:php:`$turnDigest`. Null and mismatching are the same fact — the turn is not
known — and both are refused with :php:`StaleInputTurnException`.

**The input digest lives in :php:`PendingTurnDigest`, not beside it.** ADR-132's
"one definition" is the point of that class, so the input binding is a second
method on it, :php:`forInputState()`, rather than a parallel implementation. It
covers the pending calls PLUS the target tool and the declared input schema.
:php:`forState()` is byte-identical to before, so every approval card already
rendered stays valid.

The two extra fields are what an input pause is decided on. The tool name is
what :php:`resumeWithInput()` dispatches the values onto. The schema is what the
operator's form — and the pre-claim validation — were built from, so a matching
digest also proves the submitted values were validated against the schema the
run is still suspended on.

**Neither digest covers the run uuid**, although #690 lists it. It is not a
field of the turn, and omitting it changes nothing that can be exploited: a
digest is only ever compared against the state of the run named in the same
call, so a digest borrowed from another run can only match when that run's turn
is byte-identical — same tool, same arguments, same schema — which is not an
escalation. Folding the uuid in would make the digest differ in kind from the
approval one for no gain.

**Both gates judge the state loaded AFTER the claim, and that state is what
executes.** Before this record :php:`submitInput()` executed the pre-claim copy;
it now re-reads and re-decodes the freshly claimed row exactly as
:php:`approve()` does, and a state that cannot be decoded there settles the run
rather than leaving it RUNNING.

**Schema validation stays pre-claim.** That is ADR-105's deliberate divergence
and it is what makes a bad submission resubmittable with nothing consumed. Gate
1 is what carries its verdict forward onto the post-claim state, because the
input digest covers the schema.

**A refusal releases the run.** :php:`release()` now restores the pause the
state describes — ``WAITING_FOR_INPUT`` when it names a target tool,
``WAITING_FOR_APPROVAL`` otherwise — read off the state rather than passed in,
so no call site can restore the wrong form. Nothing executed, nothing settled,
and somebody who does hold the permission can still submit.

**Both surfaces carry the digest.** The inbox's input form gets a hidden
``turnDigest`` field exactly as the approval form has; the playground's
``awaiting_input`` payload (batch and streamed) emits it and
``submitInputAction`` reads it back, mapping :php:`StaleInputTurnException` to a
409 that re-signals ``awaiting_input`` and :php:`SubmitterNotPermittedException`
to a 403.

What this deliberately is not
=============================

- **Not an audit gate.** :php:`approve()` refuses an unrecorded decision only
  for a turn that DECLARES a write; an input-requiring turn declares none, so
  the INPUT event stays best-effort like every other event write. Adding a
  fail-closed audit gate here would strand harmless runs on a store hiccup.
- **Not a new grant.** As in ADR-130/133: the tool gate and the backend user's
  own admin flag already carry the answer.
- **Not a change to who may reach the run.** :php:`mayActOnRun()` is untouched.
  A grant holder still reaches every suspended run; they are simply refused on
  the tools they could not run themselves.
- **Not a per-field verdict.** The gate judges the turn, not individual
  submitted values. What the values contain is a content question that structural
  schema validation does not answer, and the ADR-105 admin gate on the playground
  surface remains its mitigation.
- **Not a production input tool.** The catalogue is unchanged and
  :php:`InputPauseCoverageTest` still pins an empty list. Its assertion is
  unchanged; only its rationale is, because the entry it guards is now a product
  decision rather than a latent hole. The gate is exercised by a TEST FIXTURE
  tool (:php:`FakeInputTool`, which gained a ``requiresAdmin`` flag).

Consequences
============

- :php:`InputSubmission` gained a third constructor parameter. Optional in the
  signature for source compatibility; a null is refused at runtime, so every
  caller must supply it. Both in-tree surfaces do.
- Two new request-validation exceptions join the :php:`AgentRuntimeException`
  family: :php:`StaleInputTurnException` and
  :php:`SubmitterNotPermittedException`.
- One new internal value object, :php:`PendingCallRefusal`, carries the shared
  gate's verdict back to whichever path asked.
- One new label, ``runs.error.submitterNotPermitted`` (EN + DE).
- :php:`WaitingRunView::$turnDigest` is now populated for input cards too. A
  test that asserted the opposite is reversed, and says so.
- The ``null`` tool policy arm still switches the gate off, unchanged and for
  the same reason as ADR-133: it is unreachable from the container.
