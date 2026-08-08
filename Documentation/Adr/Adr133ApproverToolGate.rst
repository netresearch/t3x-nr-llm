.. _adr-133:

=============================================================
ADR-133: An approver may only release a write they could run
=============================================================

:Status: Accepted
:Date: 2026-08-09

Context
=======

A resumed run executes under the RUN OWNER's identity, deliberately
(:ref:`ADR-083 <adr-083>`): the queued work acts for whoever started it, not
for whoever happened to press *Approve*. The approver, however, was never
checked against the tool they were releasing.

:php:`AiActorContext::mayActOnRun()` grants the DECISION on the
:php:`BackendUserGrant::AGENT_APPROVE` grant alone
(:ref:`ADR-130 <adr-130>`) — a non-admin who holds it may decide other users'
suspended runs. Combine the two and a non-admin can release an admin-only write
tool, which then executes with the owner's privileges. That is a confused
deputy: the authority that runs the call is not the authority that authorised
it, and nothing compared the two.

The suspension is worth exactly as much as the check behind it. An approval
gate that any grant holder can satisfy for any tool is a queue, not a gate.

Decision
========

**The approver passes the same gate the execution would.**
:php:`ResumeCoordinator::approve()` resolves the APPROVER's live backend user —
the :php:`ActingBackendUserResolver` of ADR-083, applied to the deciding
identity rather than the executing one — and asks
:php:`ToolCallPolicy::decide()` about every pending call that DECLARES a write
(:php:`ToolEffectResolver`, ADR-111). A denial refuses the release.

**Execution identity is unchanged.** ADR-083 stands: the turn still runs as the
run owner. This adds a second, independent condition on the DECISION; it does
not move the identity the tools authorise against, and a unit test asserts that
the actor reaching the tool loop is still the owner's.

**Read-only calls are not checked.** The gate exists because a write executes
on someone else's authority. A read-only pending call changes nothing, and the
owner's own gate still decides what actually runs when the turn resumes.

**A service account may not release a write-declaring turn.** Its authority is
scopes, not backend permissions: :php:`AiActorContext::hasGrant()` returns false
for it by construction and it carries no backend-user uid. :php:`decide()` with
:php:`$user === null` therefore checks only enabled / configuration group /
trust zone — the admin axis bites solely on :php:`requiresAdmin()`, so a write
tool without that flag would pass a gate that is effectively absent while a
human is checked properly. Refusing is the only variant that stays fail-closed
without inventing a second authorisation axis for service accounts. A service
account may still release a read-only turn, so an automation that clears
harmless pauses keeps working.

**A human whose uid no longer resolves is refused too** — a deleted or disabled
account has no live permission surface to check, and "no user" is not
"permitted".

**Placement: after the turn binding, before the audit write.** The gate is the
second of the three between the claim and the execution
(:ref:`ADR-132 <adr-132>` owns the first and the third):

1. the decision must name the turn it was made on;
2. **the approver must be permitted to run every write it releases**;
3. an approval that authorises a write and could not be recorded does not
   execute.

After (1), because it judges the calls of the turn that was actually reviewed —
and, like everything since ADR-132, it reads the state loaded AFTER the claim,
never the pre-claim copy, which may be the previous turn. Before (3), because a
refused approval must not enter the audit stream as a decision that stood; that
is the same rule gate 1 already follows.

**A refusal releases the run.** The existing :php:`release()` helper hands the
run back to ``WAITING_FOR_APPROVAL``: nothing executed, nothing settled, and
somebody who does hold the permission can still decide the turn. The refusal is
logged with the actor, the tool and the policy's reason, and both surfaces map
:php:`ApproverNotPermittedException` — the module to a flash, the playground to
a 403 that re-signals ``awaiting_approval``.

What this deliberately is not
=============================

- **Not a new grant.** :ref:`ADR-130 <adr-130>` admits a grant only together
  with its consumer, and this needs none: the tool gate and the backend user's
  own admin flag already carry the answer.
- **Not a change to who may decide.** :php:`mayActOnRun()` is untouched. A
  grant holder still reaches every suspended run; they are simply refused on
  the writes they could not run themselves.
- **Not applied to a denial.** The same asymmetry ADR-132 states for the audit
  gate, for the same reason: the gate stops an unauthorised WRITE from
  executing, and a denial executes nothing. Refusing it would only leave the
  write pending and approvable while the operator who wanted it gone is turned
  away.
- **Not a scope for read-only exposure.** A non-admin approver can still
  release a read-only call they could not run themselves, which lets its result
  into the run's transcript. That is a smaller and different problem — the
  approver cannot read another user's run events (``AGENT_READ`` has no grant
  equivalent) — and widening the gate would refuse every read-only pause a
  non-admin approves. Stated here so the limit is a decision, not an oversight.
- **Not a per-call verdict.** One decision still covers the whole turn; the
  gate refuses the turn when any write call in it fails.

Consequences
============

- :php:`ResumeCoordinator` gained three optional collaborators: the tool policy,
  the acting-user resolver and a logger. The policy is the one that switches
  the gate on. A ``null`` policy does NOT refuse everything — without a gate
  there is no verdict to fail closed on, and refusing would make the bare
  positional construction unable to approve anything at all. The arm is
  unreachable from the container, where :php:`ToolCallPolicyInterface` is
  aliased, and :php:`AgentRuntime` hands its own policy down to the coordinator
  it builds.
- One new request-validation exception,
  :php:`ApproverNotPermittedException`, joins the :php:`AgentRuntimeException`
  family, with a message that names the actor, the tool and the policy reason.
- No builtin tool declares a write today (:ref:`ADR-122 <adr-122>`), so nothing
  in the shipped catalogue changes behaviour. The gate is in place for the
  first write tool and for MCP-provided ones, and the tests construct a
  write-declaring turn to exercise it.
