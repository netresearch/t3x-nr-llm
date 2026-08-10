.. _adr-141:

==============================================================
ADR-141: Every executing segment holds a lease, or writes stop
==============================================================

:Status: Accepted
:Date: 2026-08-10
:Amends: :ref:`ADR-112 <adr-112>` (where the fence arms, not how it works) and :ref:`ADR-135 <adr-135>` (its non-guarantee section)
:Authors: Netresearch DTT GmbH

Context
=======

The ADR-112 write fence stamps a tool's declared effect on the run row **before**
the tool runs and clears it after, both under an ownership-guarded ``UPDATE``.
A reaper reading a stamped ``NON_IDEMPOTENT_WRITE`` refuses to retry that run:
the side effect may already have landed.

The stamp needs an owner. ``markPendingEffect`` matches on
``claimed_by = :owner``, so a run nobody claimed cannot be fenced — and until
this decision, the only segment that claimed a run was a queue worker.

The consequence was recorded honestly in :ref:`ADR-135 <adr-135>` and in
``WritePathAcceptanceTest``: shipped writes ran unfenced. Two facts made it worse
than "the queue path is fenced, the interactive path is not":

**Nothing enqueued.** :php:`AgentRuntimeInterface::enqueue()` had no caller
outside ``Tests/``. The queue, the worker, the lease and the fence were a
complete mechanism that no shipped entry point entered.

**The write executes on the resume.** :ref:`ADR-134 <adr-134>` binds a declared
write to a human decision, and :php:`ToolLoopService` throws
``ToolApprovalRequiredException`` *before* invoking the tool. The tool therefore
runs in the continuation after Approve — and
:php:`AgentRunRepository::conditionalClaim()` explicitly wrote
``claimed_by = ''``, ``lease_expires = 0`` on that transition. The one segment
that executes side effects was the one segment that deliberately dropped its
claim.

Write entry points, as inventoried against the code:

.. list-table::
   :header-rows: 1

   * - Entry point
     - Reaches a tool through
     - Held a lease
   * - Tool Playground, batch run
     - ``AgentRuntime::run()``
     - no
   * - Tool Playground, streamed run
     - ``AgentRuntime::run()``
     - no
   * - Tool Playground, approve / submit input
     - ``ResumeCoordinator`` → ``executeResume()``
     - no
   * - Approvals inbox (``AgentRunController``)
     - ``ResumeCoordinator`` → ``executeResume()``
     - no
   * - Messenger handler
     - ``runQueued()``
     - yes, but nothing enqueued
   * - AI Tasks, wizard, specialized services
     - no tool loop at all
     - not a write path

Decision
========

Every segment that can execute a tool claims the run it executes, and a
side-effecting tool that cannot be fenced does not run.

Three parts, in that order of importance.

**1. The guard.** The fencing ``onBeforeTool`` hook is installed
unconditionally. When the resolved effect is a write and the segment holds no
persisted run or no lease, it throws
:php:`WriteWithoutDurableExecutionException` **before** the tool executes.
Previously the hook was simply not installed in that case, so an unleased write
proceeded silently. Fail-closed replaces fail-open, and this is what makes the
property hold for entry points that do not exist yet: a new caller that forgets
to claim its run cannot execute a write at all.

**2. The claims.** ``startRun`` writes a claim at birth for a synchronous run;
``claimForResume`` / ``claimForResumeFromInput`` write the winner's claim
instead of clearing it. Identities come from :php:`ExecutionIdentity` and name
the segment kind first — ``resume:web-01:4711`` — so a lease left behind says
which entry point abandoned it.

**3. The reaper rule.** A leased segment is visible to the stale-run reaper,
which is the point: an abandoned run settles instead of staying RUNNING forever.
But a synchronous run and a resume store no request payload, and
``runQueued()`` refuses a QUEUED row it cannot rehydrate. Reclaiming one would
strand it QUEUED forever, so the reaper dead-letters a stale run with no stored
request rather than requeueing it. The fence check keeps priority: a run caught
mid non-idempotent write is refused for that stronger reason first.

Why not route interactive writes through ``enqueue()``
------------------------------------------------------

The obvious alternative, and the one the programme plan proposed: have the
playground and the resumes call ``enqueue()`` instead of ``run()``. On the
default ``SyncTransport`` the run would execute inline and arrive fenced.

It was rejected on three counts, in ascending order of severity:

- ``enqueue()`` returns a uuid, not an :php:`AgentRunResult`. Every interactive
  caller wants the result.
- The queued message carries a uuid only, so the ``$onStep`` closure is lost.
  The Playground's streamed run (ADR-040/041) is built on it and would have to
  be rebuilt or dropped.
- **It does not reach the resume at all.** A resume is not a queued execution:
  it continues a suspended state, not a stored request. Since the write executes
  on the resume, an enqueue-based fix would leave every actual side effect
  exactly as unfenced as before while appearing to solve the problem.

The fence hangs on the lease, not on the queue. Giving each segment a lease is
therefore both narrower and more complete than moving segments onto the queue.

Consequences
============

✓ Both shipped writers execute fenced, on the segment that runs them.

✓ A remote (MCP) tool that declares a write but whose operator did not set the
approval flag — which ADR-134 exempts from the suspend rule, so it executes on
the FIRST pass — is fenced there too.

✓ An abandoned interactive run or resume settles via the reaper instead of
sitting RUNNING forever. This is new behaviour, not only a fence side effect.

✓ A future entry point cannot silently skip the fence. It either claims its run
or its writes are refused.

◐ Leases now appear on rows that never carried one. ``claimed_by`` is
diagnostic, but anything that treated a non-empty value as "this is a queue
worker" is wrong now — read the segment prefix.

◐ One extra ``UPDATE`` per step boundary on interactive runs (the heartbeat).
Steps are provider-call-slow; the cost is not measurable against them.

✕ Not exactly-once. ADR-112's limits are unchanged: a write that completed but
whose fence-clear did not persist is indistinguishable from one interrupted
mid-flight, and is refused a retry. Refusing a completed write's retry is the
safe direction.

Revisit when
============

A segment appears that legitimately cannot hold a lease — a read-only
introspection path that still executes tools, say. The guard would refuse its
writes, which is correct, but the question would then be whether such a segment
should exist rather than whether the guard should soften.

Also revisit if ``enqueue()`` gains a shipped caller. The two mechanisms would
then coexist, and the lease identity is what keeps their diagnostics apart.
