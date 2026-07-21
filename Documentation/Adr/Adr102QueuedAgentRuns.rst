.. include:: /Includes.rst.txt

.. _adr-102:

============================================================================
ADR-102: Queued agent runs over the TYPO3 message bus
============================================================================

:Status: Accepted
:Date: 2026-07-21
:Authors: Netresearch DTT GmbH

.. _adr-102-context:

Context
=======

``AgentRunStatus::QUEUED`` has been reserved vocabulary since ADR-081, and
:ref:`ADR-101 <adr-101>` deliberately deferred asynchronous execution: a
``start()/run()`` split is only real once the request payload is persisted so a
*different process* can execute it. Every consumer that wants batch runs,
review queues or scheduled agent work needs exactly that — enqueue now, execute
in a worker, poll status.

TYPO3 core (v13.4 and v14.3 identically) ships Symfony Messenger as the
message bus: ``MessageBusInterface`` is autowireable, handlers register via
``#[AsMessageHandler]``, the default routing sends every message to the
synchronous transport, and an installation opts into asynchronous execution by
routing messages to the core-provided doctrine transport and running
``bin/typo3 messenger:consume``. No composer change is needed.

.. _adr-102-decision:

Decision
========

``AgentRuntimeInterface`` (consumer contract: methods may be added in minor
releases, ADR-101) gains the queued half of its lifecycle:

.. code-block:: php

   public function enqueue(AgentRunRequest $request): string;                 // returns run uuid
   public function runQueued(string $runUuid, ?Closure $onStep = null): ?AgentRunResult;

**The run row is the state; the message is only a wake-up call.**
``enqueue()`` serialises the request into a new ``queued_request`` column on a
QUEUED ``tx_nrllm_agentrun`` row (entities travel as uids and are re-loaded at
execution time — the same identity-over-snapshot choice ``approve()`` makes;
messages and options use their established array forms, the ADR-084
``SuspendedRunState`` precedent). Two round-trip details are load-bearing:
``plannedCost`` and the idempotency key are deliberately excluded from
``ToolOptions::toArray()`` (sound for the ADR-084 resume, whose pre-flight
already ran) but a *queued* run's budget pre-flight has not happened yet, so
they travel out-of-band in the payload and are re-injected at rehydration —
``run()`` and ``enqueue()`` of the same request hit the identical budget gate
and dedup. And a null ``RunAugmentation`` stays null: fabricating an empty one
would flip the loop into its prompt-baking assembly branch and silently change
the prompt composition versus the identical direct run. ``enqueue()`` then
dispatches an ``AgentRunQueuedMessage``
carrying nothing but the uuid. ``AgentRunQueuedHandler``
(``#[AsMessageHandler]``) calls ``runQueued()``, which atomically claims the
row — a guarded ``QUEUED → RUNNING`` UPDATE in the ``claimForResume()`` idiom,
stamping ``started_at`` plus a worker lease (``claimed_by``,
``lease_expires``) — rehydrates the request and drives the identical
fail-closed ladder as ``run()``.

Consequences of that split:

- **Duplicate or stale messages are harmless** — the claim decides; the loser
  sees ``null`` and the handler treats it as a non-event (no redelivery).
- **Cancel-while-queued works with zero new code**: the guarded terminal
  transition already covers QUEUED, and a cancelled run is unclaimable.
- **Fail-closed enqueue**: a row that cannot be stored throws
  ``RunEnqueueFailedException``; a dispatch failure settles the just-stored
  row FAILED first — no orphaned QUEUED run that no message will ever wake.
- **Fail-closed execution**: the claim comes first; a rehydration failure
  (corrupt payload, configuration deleted while queued) settles the claimed
  run FAILED instead of stranding it. A skill or snippet deleted while queued
  is simply no longer forced — live resolution, like the interactive path.
- ``run()`` and ``runQueued()`` share one execution path, so the iteration
  ceiling, the trace wiring and the outcome taxonomy cannot drift.
- The handler never throws for run outcomes, so under ``messenger:consume`` a
  failed *run* is a handled message (the row carries the outcome) — messenger
  retry/dead-letter machinery, which TYPO3 wires none of, is not relied upon.

Operations
----------

Default (no configuration): the SyncTransport executes the run in-process
during ``enqueue()`` — semantically identical to ``run()``, just addressed by
uuid. For genuinely asynchronous execution the installation routes the message
to the doctrine transport and runs a consumer:

.. code-block:: php

   // settings.php / additional.php
   $GLOBALS['TYPO3_CONF_VARS']['SYS']['messenger']['routing']
       [\Netresearch\NrLlm\Service\Agent\Queue\AgentRunQueuedMessage::class] = 'doctrine';

.. code-block:: bash

   bin/typo3 messenger:consume doctrine

Schema: ``queued_request mediumtext`` (cleared by the guarded terminal settle,
like ``suspended_state`` — and stripped from ``status()`` for the same ADR-064
privacy reason), ``claimed_by varchar(64)`` and ``lease_expires int`` with
fail-safe ``''``/``0`` defaults. The lease (15 min) is written at claim time
and is diagnostic for now — the stale-run reaper, heartbeat, per-failure-class
retry and ``WAITING_FOR_INPUT`` are the remaining slices of the queue epic,
each its own step on top of this substrate.

.. _adr-102-consequences:

Consequences
============

- Batch runs, review queues, scheduled agent work and editor actions can
  enqueue through the public runtime and poll via ``status()`` / ``events()``
  — no playground involvement, no bespoke lifecycle.
- ``AgentRunRepositoryInterface`` gained ``enqueueRun()`` and ``claimQueued()``
  (**breaking** for out-of-tree implementors — the interface is in-repo plus
  test doubles by design).
- ``AgentRuntime`` gained optional ``MessageBusInterface`` /
  ``SkillRepository`` / ``PromptSnippetRepository`` dependencies (autowired in
  production); with no bus wired, ``enqueue()`` fails closed.
- A run FAILED by a dispatch or rehydration failure records
  ``PROVIDER_FAILED`` as its termination reason — acceptable coarseness until
  the retry epic introduces per-failure-class classification at the run level
  (ADR-095 groundwork exists).
