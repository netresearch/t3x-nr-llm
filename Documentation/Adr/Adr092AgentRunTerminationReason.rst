.. include:: /Includes.rst.txt

.. _adr-092:

============================================================================
ADR-092: A run records why it ended, and cannot be settled twice
============================================================================

:Status: Accepted
:Date: 2026-07-20
:Authors: Netresearch DTT GmbH

.. _adr-092-context:

Context
=======

``tx_nrllm_agentrun`` recorded *what* state a run was in and nothing about how
it got there. Three consequences, all of them operational:

- **A budget stop and an iteration cap were indistinguishable.** Both return a
  normal ``ToolLoopResult`` with ``truncated = true`` — the loop deliberately
  swallows the budget denial so the partial trace survives — and both are
  settled COMPLETED. The stored row could not tell an operator whether a run
  ended because the prompt needed more rounds or because the money ran out. Only
  a log line, thrown away with the next rotation, carried the difference.
- **A guardrail stop was recorded as a crash.** ``settleFailed()`` stored the
  exception FQCN, so a policy decision looked exactly like a provider outage in
  the run table. ADR-086 claimed the row reflected the guardrail verdict; it did
  not.
- **Settling was unguarded.** ``finishRun()`` updated by ``uid`` alone. The
  streamed path settles in a ``finally`` block precisely because a client
  disconnect can abandon a run, so a late settle landing on an
  already-completed run would overwrite its totals and error class.

Three enum cases — ``QUEUED``, ``WAITING_FOR_INPUT``, ``CANCELLED`` — also had
no writer at all. ``CANCELLED`` in particular left operators with no way to
retire a run that a dead PHP process left RUNNING, or an approval nobody would
ever give.

.. _adr-092-decision:

Decision
========

**Status and reason are separate fields.** ``AgentRunTerminationReason`` —
``completed``, ``max_iterations``, ``budget_exhausted``, ``policy_denied``,
``approval_denied``, ``provider_failed``, ``cancelled`` — is carried on
``ToolLoopResult`` from the loop's exit path and stored in a new
``termination_reason`` column. The status stays the coarse lifecycle state; the
reason explains it. ``isRetryable()`` on the enum answers the question a retry
policy actually asks: only a provider failure may be worth another attempt —
an exhausted budget or a policy decision will not fix itself.

**Guardrail stops are policy outcomes, not failures.**
``settlePolicyStopped()`` records FAILED with ``policy_denied`` for an outright
denial and ``approval_denied`` when a guardrail required an approval that was
never obtained. The HTTP contract is unchanged (200 with ``success: false``,
ADR-086); only the persisted reason gains meaning — and ADR-086's claim about
the row now holds.

**Terminal is terminal.** ``finishRun()`` updates only rows whose status is
non-terminal and returns whether it transitioned. A duplicate or late settle
keeps the first outcome and is logged at notice level rather than silently
merged. This is the same conditional-UPDATE technique ``claimForResume()``
already used for the double-approval race (ADR-084).

**Suspension is fail-closed for the caller.** ``suspend()`` still swallows the
store error — the persister is fail-soft by design — but now reports it. The
playground fails the run instead of answering "awaiting approval", because an
approval-gated tool is by definition side-effecting: promising a resume that
cannot happen is worse than an honest error. Read-only recording stays
fail-soft; a database hiccup must not break an otherwise successful run.

**Cancellation is implemented, not merely enumerated.**
``nrllm:agent:cancel <uuid>`` moves a non-terminal run to CANCELLED through the
same guarded transition, dropping its resumable state. CANCELLED is distinct
from FAILED: nothing went wrong, somebody stopped it.

.. _adr-092-consequences:

Consequences
============

- ``ToolLoopResult`` gains a constructor parameter with a default, so existing
  positional constructions keep working; ``truncated`` is retained rather than
  derived, because "the answer is incomplete" and "this is why" are different
  questions and consumers already read the former.
- ``AgentRunRepositoryInterface::finishRun()`` gains a parameter and returns
  ``bool`` instead of ``void`` — a breaking change to a DI-private interface,
  called only by the persister.
- ``AgentRun`` gains ``terminationReason`` and ``terminationReasonEnum()``.
  Unknown stored values return null rather than being coerced, matching how
  ``statusEnum()`` already guards forward compatibility.
- Runs written before this change carry an empty reason. That is honest —
  the information was never recorded — and reads as "unknown", not as
  "completed normally".
- ``WAITING_FOR_INPUT`` still has no writer. It stays in the enum as the
  reserved state for the queue work (roadmap P1), and this ADR does not pretend
  otherwise.
- Guardrail approval remains **terminal**: the run ends with
  ``approval_denied`` rather than suspending for a decision, because
  ``GuardrailApprovalRequiredException`` carries no resumable state (ADR-086).
  Making it resumable means teaching the guardrail path to hand back the
  flagged content and the transcript, the way the tool-approval path already
  does — a separate change with its own ADR, not a side effect of this one.
