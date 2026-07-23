.. include:: /Includes.rst.txt

.. _adr-112:

============================================================================
ADR-112: Lease-before-op fence — no retry for an interrupted write
============================================================================

:Status: Accepted
:Date: 2026-07-23
:Authors: Netresearch DTT GmbH

.. _adr-112-context:

Context
=======

:ref:`ADR-111 <adr-111>` classified a tool's side effect and made a write's
audit fail-closed. It named two follow-ups; this is the one that closes the
double-execution window itself.

The queue is at-least-once (:ref:`ADR-102 <adr-102>`) and the lease renews at a
step boundary — *after* an operation completes (:ref:`ADR-104 <adr-104>`). A
tool call that outlives ``LEASE_SECONDS`` is reaped and the run re-executed. For
a ``NON_IDEMPOTENT_WRITE`` that is a double effect: the reaper cannot tell that
the run was mid-write, because nothing is recorded until *after* the tool
returns.

.. _adr-112-decision:

Decision
========

Record the in-flight write BEFORE it runs, and refuse to retry a run reaped
while that record stands.

The fence
---------

``tx_nrllm_agentrun`` gains a ``pending_effect`` column. A new
:php:`RunTrace::beforeToolExecution()` hook fires immediately before the loop
invokes a tool; the runtime resolves the tool's :php:`ToolEffect` and, for a
WRITE, stamps ``pending_effect`` and renews the lease in one ownership-guarded
write (:php:`AgentRunRepository::markPendingEffect()`). When the tool's step is
recorded, the same guarded write clears the fence. Read-only tools are never
fenced — repeating them is always safe, so they cost no extra write. The stamp
is guarded exactly like the heartbeat: a worker that has lost the run stamps
nothing and stops before the side effect.

Refuse the retry
----------------

Both retry deciders consult the fence and treat a fenced value through
:php:`AgentRuntime::mayRetryAfterFence()` — ``NON_IDEMPOTENT_WRITE`` is not
retryable, everything else (including an unset or unrecognised value) is:

- the stale-run reaper (:php:`ReapStaleAgentRunsCommand`) dead-letters a run
  reaped mid non-idempotent-write instead of reclaiming it onto the queue,
  regardless of the remaining retry budget;
- the in-process recovery (:php:`AgentRuntime::recoverQueuedFailure()`)
  dead-letters such a run even when the failure class is otherwise retryable —
  a transient provider blip does not make a repeated write safe.

An ``IDEMPOTENT_WRITE`` converges on repeat, so it is reclaimed normally.

.. _adr-112-consequences:

Consequences
============

- The guarantee is "a non-idempotent write runs at most once": if it is reaped
  or fails mid-flight, the run fails rather than repeating it. It is NOT
  exactly-once — a write that completed but whose fence-clear did not persist is
  still failed, never silently retried (fail-closed toward not-repeating).
- ``pending_effect`` defaults to ``''`` so an un-migrated or fresh row reads as
  "no write in flight" — the fail-safe default.
- The lease-before-op renewal also gives each write a full lease window at its
  start, but the durable fence — not the extra renewal — is what makes the
  refusal correct.
- Idempotency KEYS for safe dedup of an ``IDEMPOTENT_WRITE`` remain future work;
  this ADR only guarantees a non-idempotent write is not repeated.
