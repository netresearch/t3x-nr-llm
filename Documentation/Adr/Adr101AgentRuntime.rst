.. include:: /Includes.rst.txt

.. _adr-101:

============================================================================
ADR-101: AgentRuntime — the agent-run lifecycle as a public service
============================================================================

:Status: Accepted
:Date: 2026-07-21
:Authors: Netresearch DTT GmbH

.. _adr-101-context:

Context
=======

Persistence (:ref:`ADR-081 <adr-081>`), human-in-the-loop approval
(:ref:`ADR-084 <adr-084>`) and termination semantics (:ref:`ADR-092 <adr-092>`)
work, but they were assembled inside ``ToolPlaygroundController``: it began the
run, built the trace, persisted events, caught the suspension, settled the
status and executed the resume. The lifecycle ladder — ``runLoop`` → catch
suspension → catch guardrail → catch failure → settle completed — was copied
three times (batch, resume, stream), and any new consumer (a scheduler task, a
queue worker, an editor action) would have had to re-assemble it again, each
copy a chance to get the fail-closed rules wrong.

.. _adr-101-decision:

Decision
========

``AgentRuntimeInterface`` (``Classes/Service/Agent/``) is the public
application service for agent runs; ``AgentRuntime`` implements it and owns the
ladder exactly once. The playground controller is now a UI adapter: it parses
the request into an ``AgentRunRequest`` / ``ApprovalDecision`` and maps the
returned ``AgentRunResult`` onto its JSON / NDJSON shapes, which are unchanged.

.. code-block:: php

   interface AgentRuntimeInterface
   {
       public function run(AgentRunRequest $request, ?Closure $onStep = null): AgentRunResult;
       public function approve(string $runUuid, ApprovalDecision $decision, ?Closure $onStep = null): AgentRunResult;
       public function cancel(string $runUuid): bool;
       /** @return list<AgentRunEvent> */
       public function events(string $runUuid, int $afterSequence = -1): array;
       public function status(string $runUuid): ?AgentRun;
   }

- ``run()`` / ``approve()`` **never throw for a run outcome** — the result is
  already settled, discriminated by ``AgentRunOutcome`` (COMPLETED /
  AWAITING_APPROVAL / SUSPEND_FAILED / GUARDRAIL_BLOCKED /
  GUARDRAIL_APPROVAL_REQUIRED / FAILED). ``approve()`` throws typed
  ``AgentRuntimeException``\ s only for an invalid request, before any
  execution: not-awaiting-approval, configuration gone, corrupt state, event
  position unavailable, claim lost.
- ``onStep`` is a live observer fired for each recorded step **before** it is
  persisted (preserving the streaming path's emit-before-persist order); the
  NDJSON stream is built on it.
- The roadmap sketched ``start(request): handle`` + ``run(handle)``. That split
  is only real once the request payload is persisted so a *different process*
  can execute it — the queue epic. Until then a handle would not be
  rehydratable, so ``run()`` is synchronous begin-and-execute;
  ``AgentRunRequest`` is a plain value object so the queue epic can serialise
  it and add asynchronous execution without changing these signatures.

Hardening folded in
-------------------

- **Suspension-safe finally-guard.** The abandoned-run settle (previously
  stream-only) now guards every path, and every branch — including a
  *successful* suspension — marks the run settled first. An unguarded
  finally-settle would flip a just-suspended run (WAITING_FOR_APPROVAL is
  non-terminal) to FAILED and destroy its resumable state.
- **Fail-closed re-suspension.** The old resume path silently ignored a failed
  re-suspension and still answered ``awaiting_approval`` — an unresumable
  promise. The unified ladder applies :ref:`ADR-092 <adr-092>`'s fail-closed
  rule everywhere; the distinct SUSPEND_FAILED outcome makes it visible.
- **Iteration ceiling.** The per-run round cap (20) is a runtime invariant
  (``AgentRuntime::MAX_ITERATIONS``), no longer a controller constant. Only an
  explicit ``maxIterations`` is clamped; null keeps the loop's own lower
  default.
- **Deterministic event sequence.** A resume continues the stream at
  ``MAX(sequence) + 1`` (was: a count that silently restarted at 0 on a query
  failure, interleaving segments). The position is probed *before* the claim —
  a failure refuses the resume while the run is still suspended, so the
  approval can simply be retried — and resolved *again* after winning it, so a
  request that stalled across another approval's continuation can never write
  duplicate sequences. (The narrower pre-existing window that such a stale
  request resumes the earlier suspension's *state* is unchanged from the
  previous controller code and bounded by the claim fence.)
- **Cancel is a real fence.** ``suspendRun`` is now a guarded transition
  (suspends only a run still RUNNING). Previously an in-flight loop's late
  suspension could resurrect a just-CANCELLED row to WAITING_FOR_APPROVAL,
  offering an approval flow — and an executable gated tool — for a run the
  operator was told was stopped. A refused suspension takes the fail-closed
  SUSPEND_FAILED path; the terminal-status guard then discards its settle, so
  the run stays CANCELLED.
- **No fail-open unpersisted suspension.** A run whose persistence was down at
  begin (null handle) that then hits an approval-gated tool is SUSPEND_FAILED,
  not AWAITING_APPROVAL: nothing was stored, so ``approve('')`` could only
  ever fail — the old code announced an approval flow that did not exist.
- **Single-sourced logging.** The runtime logs each failure / guardrail block /
  refused suspension once, with the run uuid; the controller no longer re-logs
  the same event (the old per-channel messages, including "Tool playground
  resume failed", are replaced by the runtime's).
- **Audited decisions.** The operator's decision is persisted as a new
  ``AgentEventKind::APPROVAL`` event (payload ``{approved, decidedBy}``,
  best-effort like every event write). ``fromRunStepKind()`` deliberately does
  not resolve it — it is not a ``RunStep`` kind; stored kinds hydrate via
  ``tryFrom()``. No free-text note: the event stream is privacy-filtered
  (:ref:`ADR-064 <adr-064>`) and prose would bypass that.
- **Privacy-safe status.** ``status()`` strips the suspended-state transcript:
  it is stored verbatim for resume and bypasses the privacy filter, so it is
  not part of the status surface.

.. _adr-101-consequences:

Consequences
============

- ``AgentRuntimeInterface`` is a **public DI alias** (Category B), raising the
  audited public-service count from 34 to **35** (later raised to **36** by
  :ref:`ADR-106 <adr-106>`, which makes ``GuardrailRegistry`` public for its TCA
  itemsProcFunc). This ADR supersedes ADR-094 as the count authority. It is a
  *consumer* interface: call it, do not
  implement or decorate it outside nr_llm — methods and ``AgentRunOutcome``
  cases may be added in minor releases (the queue epic will), so exhaustive
  matches need a default arm. ``ToolLoopServiceInterface`` stays public for
  consumers that want the bare loop; the runtime is the preferred surface.
- ``nrllm:agent:cancel`` goes through the runtime; the playground controller
  lost its ``ToolLoopService`` / ``AgentRunPersister`` dependencies.
- **Breaking:** ``AgentRunPersister::resumeHandle()`` now returns
  ``?AgentRunHandle`` (null = refuse the resume), and
  ``AgentRunRepositoryInterface`` gained ``maxEventSequence()``.
- Now possible without touching the playground: scheduler and messenger
  workers, consumer extensions, batch runs, review queues, editor actions and
  status polling (``events()`` pages by sequence) — the run lifecycle they get
  is the tested one.
