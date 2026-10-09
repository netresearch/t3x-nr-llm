<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# Worker operations

Status: specified before implementation. Decision: ADR-219.

## Capability and boundaries

Existing AgentRuntime, atomic claims, Messenger queue handler and reaper remain
the execution mechanism. Add internal worker heartbeats, queue-entry timing and
an operational CLI. No public API contract changes, no separate queue, no live
model needed for verification. Heartbeat output exposes no actor, payload or
credential. Metadata retention is bounded and unrelated to run privacy policy.

## Requirements

1. Enqueue and both successful requeue paths record the current queue-entry
   time. A failed conditional requeue changes nothing. Claim does not erase
   timing. Legacy rows remain unknown; no migration guesses from creation time.
2. Consumer start and idle/running events record one process identity and its
   transport names. Stop removes it. Identities are random and process-local;
   two workers are counted separately. Count workers only for the requested
   transport (default `doctrine`). Expired entries are periodically pruned.
3. Operational snapshots count queued, running and dead-letter rows; expired
   running leases use the same strict expiry boundary as the reaper. Queue wait
   is computed only for queued rows with a valid timestamp; unknown counts are
   explicit and an empty queue reports null oldest wait, not a fake zero.
4. `nrllm:agent:status --json` outputs only aggregates. Configurable maximum queue
   wait and minimum worker thresholds return failure when violated; malformed
   or negative options return invalid. Expired run leases and queued rows with
   unknown/future queue-entry times are unhealthy. An unrelated transport's
   consumer cannot satisfy the minimum worker threshold.
5. Documentation shows asynchronous routing, consumers bounded using options
   supported by each TYPO3 version or a supervisor, periodic reaper,
   health invocation, concurrency sizing and long-call heartbeat limitations.
6. Container wiring receives real Messenger events on supported TYPO3 versions;
   monitoring does not weaken claims, fencing or retry limits.

## Acceptance evidence

| Requirement | Suite and test |
|---|---|
| Enqueue/requeue timestamp and failed claim isolation | functional: `WorkerOperationsRepositoryTest` |
| State aggregates, unknown vs zero, strict lease boundary | functional: `WorkerOperationsRepositoryTest` |
| Start/idle/stop/two workers, pruning | functional: `WorkerOperationsWiringTest`, `WorkerOperationsRepositoryTest` |
| JSON privacy, threshold/default/invalid exit status | unit: `AgentStatusCommandTest` |
| Actual container listener registration and Messenger consumer events | functional: `WorkerOperationsWiringTest` |
| Deployment and operational limits | documentation inspection and docs render |

Run the repository gate and focused functional tests; report any baseline
dependency/runtime limitation explicitly. No new test is allowed outside a
configured suite. Regression tests must fail if queue timestamp recording or
the unhealthy threshold condition is removed.
