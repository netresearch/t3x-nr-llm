.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _api-agent-runtime:

=======================
Persisted agent runtime
=======================

Inject :php:`Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface` for runs
that need persisted status, approval, input or background execution. It is a
consumer interface: call it rather than implementing or decorating it.
Methods and outcome cases may be added in minor releases; handle unknown
outcomes with a default branch.

.. _api-agent-runtime-request:

Starting a run
==============

Resolve a configuration as in :ref:`integration-guide-step5` and construct an
actor from the authenticated backend user at the request boundary. Never
accept user IDs, admin flags or grants directly from request data.

.. code-block:: php
   :caption: Run with an explicit configuration and authenticated actor

   use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
   use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
   use Netresearch\NrLlm\Service\Agent\AgentRunRequest;

   $actor = AiActorContext::fromBackendUser($backendUser);
   $request = new AgentRunRequest(
       configuration: $configuration,
       messages: [ChatMessage::user('Summarize this page.')],
       actor: $actor,
       allowedToolNames: [],
       maxIterations: 3,
   );
   $result = $agentRuntime->run($request);

``allowedToolNames: []`` offers no tools. ``null`` starts from the enabled
set; configuration, actor and skill policy still narrow it. A list narrows
the offer to those names. The requested round cap is clamped to the instance
ceiling. Raw response capture is off by default.

The returned :php:`AgentRunResult` has ``outcome``, ``runUuid`` and ``steps``.
On completion, ``loopResult`` holds the answer and aggregate usage. An
approval suspension carries ``suspendedState``; failures carry ``error``.
Run outcomes return as results, while invalid resume requests can throw
:php:`AgentRuntimeException`. A blank ``runUuid`` means persistence failed:
do not present that result as a stored, resumable run.

.. _api-agent-runtime-lifecycle:

Lifecycle methods
=================

``run($request, $onStep = null)``
    Executes synchronously. The optional callback receives each step as it is
    recorded, including live text deltas that are not persisted.

``enqueue($request)``
    Stores and dispatches the run, returning its UUID. The default synchronous
    message transport can execute it before this method returns.

``defer($request)``
    Stores background work without executing it in the caller. Route it to an
    asynchronous consumer or run ``nrllm:agent:work``.

``runQueued($runUuid, $onStep = null)``
    Worker entry point. Claims the queued run atomically; returns ``null`` if
    it is already claimed, cancelled or otherwise unavailable.

``approve($actor, $runUuid, $decision, $onStep = null)``
    Decides the whole pending turn and continues it. Build an
    :php:`ApprovalDecision` with the reviewed turn's digest; a missing or
    stale digest is refused. Approval does not bypass current tool or actor
    permissions. A continuation can suspend again.

``submitInput($actor, $runUuid, $submission, $onStep = null)``
    Validates an :php:`InputSubmission` against the pending schema and turn
    digest, claims the decision and continues. Input remains untrusted data.

``cancel($actor, $runUuid)``
    Cancels a non-terminal run; returns whether this caller won the guarded
    transition. Cancellation is cooperative at step boundaries; an in-flight
    call without cancellation support can finish its current step.

``cancelIfWaiting($actor, $runUuid)``
    Withdraws only a run waiting for approval or input. A run already released
    for execution is not cancelled. Restricted to its initiator or an admin.

``endProcess($actor, $runUuid)``
    Releases a held guided process and cancels a waiting run. It does not undo
    approved writes. A queued or running run cannot be ended through this
    method. Restricted to its initiator or an admin.

``status($actor, $runUuid)``
    Returns the run or ``null`` when unknown. Resumable transcript state is
    stripped from this public readout.

``events($actor, $runUuid, $afterSequence = -1)``
    Returns events in ascending sequence order, strictly after the supplied
    cursor. An empty list alone does not prove that the run exists. An event
    of the kind ``tool_changes`` lists the records one tool call changed;
    :php:`ObservedChangeSet::tryFromEventPayload()` reads its payload. The list
    is what DataHandler runs reported and is never complete (:ref:`adr-292`).

.. _api-agent-runtime-boundaries:

Approval, identity and storage
==============================

A tool approval creates a waiting run. A guardrail ``approval_required``
verdict fails the run with a policy reason; it does not create that approval
workflow. See :ref:`administration-agent-runs` for the operator's view.

Workers restore the initiating actor rather than borrowing an ambient backend
user. Service accounts need explicit scopes and do not acquire backend-user
privileges. Re-check authorization at the application's HTTP boundary.

Waiting runs retain verbatim state needed to resume. Event-log privacy
settings do not suppress that state. See :ref:`administration-data-retention`
for background-answer storage and expiry.

For an unpersisted loop, inject
:php:`Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface`. Its
``runLoop()`` accepts messages, configuration, :php:`ToolExecutionContext`
and an explicit allowed-tool list. It can throw approval or input suspension
exceptions; callers must provide their own lifecycle handling. Prefer the
persisted runtime when users need to resume work. See
:ref:`developer-tool-calling` and :ref:`administration-tools-register`.
