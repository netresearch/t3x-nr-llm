.. include:: /Includes.rst.txt

.. _adr-104:

============================================================================
ADR-104: Worker heartbeat, stale-run reaper, retry and dead-letter
============================================================================

:Status: Accepted
:Date: 2026-07-21
:Authors: Netresearch DTT GmbH

.. _adr-104-context:

Context
=======

Queued runs (:ref:`ADR-102 <adr-102>`) claim a row with a **lease**
(``claimed_by`` + ``lease_expires``), but until now nothing renewed or acted on
it — the lease was diagnostic only. Two gaps remained:

- **A dead worker strands its run forever.** If the PHP process is killed, the
  container recycled or the machine rebooted mid-loop, the run stays RUNNING
  with a lease nobody renews. No other worker can take it (the claim is
  optimistic on QUEUED), so it never finishes.
- **A transient provider failure is terminal.** A queued run that hit a 5xx, a
  rate limit or an exhausted fallback chain settled FAILED immediately — no
  retry, even though a different worker moments later might have succeeded.

The P1 roadmap asks for a heartbeat, a stale-run reaper, retry-per-failure-class
and a dead-letter terminus.

.. _adr-104-decision:

Decision
========

**Heartbeat.** The runtime's trace hook (the same step-boundary closure the
cancellation probe uses, :ref:`ADR-103 <adr-103>`) renews the lease on every
step for a worker run — an ownership-guarded UPDATE
(``WHERE status='running' AND claimed_by = :me``). A renewal that affects **no
row** means the worker lost the run (reaped, re-claimed or terminated); it
throws the internal ``RunLeaseLostException``, caught by a dedicated ladder arm
that stops **without settling** (the row belongs to its new owner now) and
returns ``AgentRunOutcome::LEASE_LOST``. Interactive ``run()``/``approve()``
segments hold no lease and never renew — the heartbeat is worker-only. To avoid
a zombie worker appending an event whose sequence collides with the new owner's
stream, the boundary step is persisted **after** the renewal check, not before.

**Reaper.** ``nrllm:agent:reap`` (schedulable) finds RUNNING runs whose lease
has expired (``lease_expires > 0 AND lease_expires < now`` — the ``> 0`` excludes
interactive runs) and either requeues them (budget permitting) or dead-letters
them. Both mutations re-check staleness **inside** the UPDATE, so a heartbeat
renewal that lands between the reaper's SELECT and its write wins and the merely
slow (not dead) worker is left alone.

**Retry.** A queued run's failure runs through a recovery hook in the ladder,
before the default settle. The failure is classified through the existing
:ref:`FailureClassifier <adr-095>` (extended so a ``FallbackChainExhausted``
wrapper classifies by its most recent attempt rather than as UNKNOWN):

- **Not retryable** (auth, configuration, 4xx) → dead-letter now with reason
  ``NOT_RETRYABLE``.
- **Retryable but the requeue budget is spent** → dead-letter with
  ``RETRIES_EXHAUSTED``.
- **Retryable and under budget** → ownership-guarded requeue (bumping
  ``requeue_count``) and a re-dispatch with an exponential ``DelayStamp``
  backoff, returning ``AgentRunOutcome::REQUEUED``.

Interactive runs pass no recovery hook and surface failures unchanged.

**Budget.** A new ``requeue_count`` column, shared by both requeue sources
(failure retry and stale reclaim), capped by ``AgentRuntime::MAX_REQUEUES`` (3),
so a deterministically crashing or hanging run cannot loop forever.

**Dead-letter = FAILED + reason axis.** No new status is introduced (the status
model stays stable, and purge/retention are untouched). Dead-lettering is
expressed on the :ref:`ADR-092 <adr-092>` reason axis via the two new
non-retryable reasons above.

.. _adr-104-consequences:

Consequences
============

- ``AgentRunOutcome`` gained ``REQUEUED`` and ``LEASE_LOST``;
  ``AgentRunTerminationReason`` gained ``RETRIES_EXHAUSTED`` and
  ``NOT_RETRYABLE`` (both ``isRetryable() === false``). All are the documented
  minor-release growth path — consumers match with a default arm, and the
  playground never sees the new outcomes (they arise only on the worker path).
- ``tx_nrllm_agentrun`` gained ``requeue_count``.
- Real exponential backoff requires the doctrine transport (which honours the
  ``DelayStamp``); the default SyncTransport ignores the delay and retries
  in-process, bounded by ``MAX_REQUEUES`` — the same requirement ADR-102 already
  places on async execution.
- A worker that renews its lease adds one guarded UPDATE per step boundary
  (alongside the ADR-103 read); steps are provider-call-slow, so the cost is
  noise.
- The reaper only reclaims abandoned **queue** workers. An interactive run
  abandoned by a dying client keeps no lease and is still reaped by the
  age-based retention path (``nrllm:privacy:purge``).
