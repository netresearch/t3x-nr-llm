.. _adr-132:

==========================================================
ADR-132: Fail-closed approval audit and turn binding
==========================================================

:Status: Accepted
:Date: 2026-08-09
:Supersedes: the stale-review binding of :ref:`ADR-109 <adr-109>`

Context
=======

Two defects sat on the same code path, :php:`ResumeCoordinator::approve()`.

**The decision that authorises a write was not audited.**
:php:`AgentRunPersister::recordApproval()` caught every :php:`Throwable`,
logged a warning and returned :php:`void`. The coordinator called it unchecked
and continued into the execution. A destructive tool call could therefore be
approved and executed with nothing anywhere recording who approved it. The
write *step* audit has been fail-closed since :ref:`ADR-111 <adr-111>` —
:php:`AuditPersistenceFailedException` fails a run whose WRITING tool executed
but whose step could not be stored. The decision that authorised the write was
not covered by anything.

**The reviewed turn was bound on one surface only.** The stale-review digest
introduced with :ref:`ADR-109 <adr-109>` lived in
:php:`AgentRunController::approveAction()`. The Tool Playground's
``awaiting_approval`` payload returned no digest and its resume endpoint read
only ``runUuid`` and ``approve`` — so the playground could approve a turn it had
never displayed. Worse, the module's own check ran *before* the atomic claim,
against the row as it was then.

Decision
========

**One digest definition.** The computation moved out of the inbox view factory
into :php:`PendingTurnDigest`, a small ``@internal`` service both the rendering
side and the verifying side use. A digest is a comparison; two implementations
that drift apart silently compare different things. The hash covers the pending
calls only — the transcript and the counters change every round and would make
every digest stale.

**The digest travels with the decision.** :php:`ApprovalDecision` carries a
third property, ``turnDigest``. Both surfaces hand it through; neither compares
anything. The comparison happens once, inside the coordinator.

**The verified state is the state loaded after the claim.** Losing the claim
race does not leave the run untouched: the winner runs the turn, the loop
continues, and the run can suspend *again* on a different turn — which is
exactly the row the next claim succeeds on. The pre-claim read would be the
previous turn, so the digest check, the write classification and the execution
all read the freshly claimed row.

**The state is nevertheless decoded twice, and the two decodes answer different
questions.** The pre-claim decode asks "was this row readable when we found
it" and refuses without claiming — a row corrupted outside the extension stays
``WAITING_FOR_APPROVAL`` with its blob intact, so an operator can inspect and
repair it. The post-claim decode asks "is the row we actually won readable" and
must *settle* the run on a no, because the claim is already held and an
unreadable state cannot be written back. Both are needed. Dropping the pre-claim
one would make the ordinary corrupt-row case terminal on the first Approve
click, and the guarded terminal settle clears ``suspended_state`` — destroying
the evidence along with the run, the outcome
:php:`AgentRunExecutor` already names as the one to avoid. Dropping the
post-claim one would let the race resume a turn nobody verified.

**Two gates between the claim and the execution.**

1. The decision must name the turn it was made on. A ``null`` digest and a
   mismatching digest both mean "the reviewed turn is not known", so both are
   refused; the comparison is :php:`hash_equals()`.
2. An approval whose APPROVAL event could not be stored may not execute a turn
   that declares a write. "Declares a write" is
   :php:`ToolEffectResolver::effectFor()` (ADR-111), which already resolves an
   unknown name to ``NON_IDEMPOTENT_WRITE``; a pending entry too corrupt to
   yield a call at all counts as a write too.

**A refused decision releases the run.** Both gates suspend the run back to
``WAITING_FOR_APPROVAL`` — the existing RUNNING → WAITING transition, which
clears the claim and the lease and writes the state back. Nothing executed and
nothing settled, so the operator re-reviews the current turn and decides again.
A release that itself fails settles the run instead, because a run left RUNNING
with no worker is invisible to the inbox and to the reaper alike.

What this deliberately is not
=============================

- **Not fail-closed for read-only turns.** A read-only turn whose decision
  could not be stored continues, with the failure logged. The audit gap is
  real, but nothing changes state, and refusing would strand a harmless run
  behind an unavailable audit store.
- **Not fail-closed for a denial.** A denial passes gate 1 — deciding on a turn
  nobody reviewed is as wrong when the answer is "no" — but not gate 2. Gate 2
  exists to stop an unaudited *write* from executing, and a denial executes
  nothing. Refusing it would leave the write-declaring turn pending and
  approvable while the operator who wanted it gone is turned away. "Who denied"
  is still lost, which is why the failure is logged rather than silent.
- **Not a new repository method.** The release reuses
  :php:`AgentRunPersister::suspend()`. The transition it needs already exists.
- **Not a per-call verdict.** One decision still covers the whole pending turn;
  the digest binds the turn, not individual calls.

Consequences
============

- :php:`AgentRunPersister::recordApproval()` returns :php:`bool`, the same shape
  as :php:`recordStep()`. The class is no longer uniformly "fail-soft": it never
  throws, but two methods hand the caller the evidence to fail closed.
- Two new request-validation exceptions,
  :php:`StaleApprovalTurnException` and :php:`ApprovalNotAuditableException`,
  join the :php:`AgentRuntimeException` family. Both surfaces map them: the
  module to the existing ``runs.error.staleReview`` and a new
  ``runs.error.notAuditable`` flash, the playground to a 409 and a 503 that
  both re-signal ``awaiting_approval``.
- :php:`ApprovalDecision`'s constructor gained a third argument. It is optional
  in the signature for source compatibility only — a ``null`` is refused at
  runtime — so a third party constructing the decision itself must supply the
  digest of the turn it displayed.
- The playground carries ``turnDigest`` in both its batch pause payload and its
  streamed ``awaiting_approval`` event. It ships no approval UI of its own
  today, so no client code consumes it yet; the value is there for the first
  one that does.
- The controller-side stale check in :php:`AgentRunController` is gone. One
  definition of the invariant, in the one place that holds the claim. Its
  unreadable-state pre-filter is gone with it, but the operator sees the same
  ``runs.unreadable`` flash: the coordinator's pre-claim decode throws
  :php:`CorruptSuspendedStateException`, which that action already maps. The Tool
  Playground, which never had such a pre-filter, gains the guard for the first
  time.
