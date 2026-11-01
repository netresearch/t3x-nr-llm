<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->

# An AI Task runs in the background

AI Tasks run only while an editor waits in the backend. The queue side of agent runs exists (ADR-102, ADR-104, ADR-219), but nothing puts work on it, and on the default synchronous transport `enqueue()` executes in the caller. An integrator queues a task from the command line or the scheduler, a worker executes it, and the backend shows what happened (ADR-234).

## Required behavior

1. `nrllm:task:enqueue <identifier>` stores one queued run for an active task and returns. No provider is called. `--input` carries the input of a manual task; a manual task without input is refused. A task with a source reads it when it is queued.
2. `AgentRuntimeInterface::defer()` stores the row. It dispatches a wake-up message only where the routing sends `AgentRunQueuedMessage` to a transport other than `default`. A failed dispatch leaves the row queued. The change to `Tests/Unit/Api/api-surface.txt` is additive: this method, `AgentRunRequest::$origin`, `AgentRun::$origin` and `$answer`, and two `ToolOptions` methods.
3. `nrllm:agent:work` executes queued runs oldest first through `runQueued()`, bounded by `--limit` and `--time-limit`. A run another worker claimed is counted. A failed run does not fail the command, an unreadable queue does.
4. The sweep asks the budget before it claims. A run the budget would refuse stays queued with its request, and the runs behind it continue, whatever `--limit` says about the refused ones. A retryable failure of a background run on the synchronous transport waits for the next tick.
5. A task run offers no tool, uses the task's configuration or the default, carries the task's skills, uses JSON mode for a JSON task, and its usage row names the task.
6. It acts as a service account holding `configuration:use`, never as `_cli_`.
7. Both commands are schedulable.
8. The module lists runs with an origin in every status, restricted like the other lists, and one unreadable sealed value does not empty the list or fail a tick. The detail page of such a run shows its answer, escaped.
9. A finished background run keeps its answer sealed, on every privacy level but `none`; `redacted` keeps the redacted text. The rotator covers the column.

A run that kept no answer stores NULL, and a run that was never finished has none either. The page says so; it does not show an empty answer.

## Not done

No backend form to queue a task, no recurring definition of its own (the scheduler is that), no consumer of its own, no change to `enqueue()`.

## Boundaries

Provider input and output are unchanged. A new stored content column exists: `tx_nrllm_agentrun.answer`, sealed like `queued_request`, deleted with the row by the purge. That is more than the default `metadata` level stores for events, and ADR-234 says why.

## Tests

- Unit `WorkAgentQueueCommandTest`, `EnqueueTaskCommandTest`, `TaskRunRequestFactoryTest`, `DeferredRunTest`, `ToolLoopServiceTest`.
- Functional `BackgroundTaskQueueTest` (the whole way against a database, schedulable, order, history), `AgentRunControllerTest` (list and detail), `AgentStateEnvelopeRotatorTest`.
- Unit `ApiSurfaceSnapshotTest`: the snapshot holds the additions.
