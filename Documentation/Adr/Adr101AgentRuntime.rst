.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-101:

============================================================================
ADR-101: AgentRuntime — the agent-run lifecycle as a public service
============================================================================

:Status: Accepted (optional diagnostics clarified by
    :ref:`ADR-225 <adr-225>`)
:Amended: 2026-10-10 by :ref:`ADR-225 <adr-225>` (optional diagnostics)
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
  default. The cap holds for the whole run: a suspension keeps it in the
  suspended state, and a resume after an approval or an input runs under it,
  clamped again, rather than under the loop default.
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
  itemsProcFunc, to **37** by :ref:`ADR-201 <adr-201>`, whose
  ``UnavailableToolsResolverInterface`` a consumer looks up in the container by
  name to feature-detect it — Category B, and to **38** by
  :ref:`ADR-206 <adr-206>`, whose ``ToolDataHandler`` every writing tool
  creates through ``makeInstance()`` — Category E, and to **39** by
  :ref:`ADR-211 <adr-211>`, whose ``DecisionServiceInterface`` consumer
  extensions resolve by interface — Category A, and to **40** by
  :ref:`ADR-214 <adr-214>`, whose ``SkillDeletionGuardHook`` the DataHandler
  creates through ``makeInstance()`` with its connection pool — Category E, and
  to **42** by :ref:`ADR-237 <adr-237>`, whose ``FieldFillHook`` the DataHandler
  creates and whose ``FieldFillPlanner`` that hook fetches only when a field is
  opted in — Category E, and to **43** by :ref:`ADR-248 <adr-248>`, whose
  ``ExtensionUsageReaderInterface`` consuming extensions inject to read their
  own usage — Category B).
  This ADR supersedes ADR-094
  as the count authority. It is a
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

Clarification on 2026-10-10
===========================

:ref:`ADR-225 <adr-225>` contains errors from optional diagnostic emission in
the shared executor, queued failure recovery and agent-run persister. Original
outcomes, errors, ownership guards and storage refusal values remain the
authority; a failed logger neither changes the run decision nor grants a failed
mandatory audit, suspension or write fence success. The historical behavior
above remains recorded; this clarification covers the bounded diagnostic
repair, not every logging site in the extension.

Amendment (2026-11-04): the round limits are settings
=====================================================

**Context.** The ceiling of 20 was a constant, and the default of 5 for a run
that asks for no cap was a constructor argument of ``ToolLoopService``,
changeable only through a service definition. An installation whose runs
need more rounds, or that wants to spend less on each run, could not set
either in the backend.

**Decision.**

- Two extension settings, ``agent.defaultMaxIterations`` (fallback 5) and
  ``agent.maxIterations`` (fallback ``AgentRuntime::MAX_ITERATIONS``, 20),
  read through ``AgentRoundLimitsInterface`` on every call. An empty,
  non-numeric, zero or negative value is the fallback; a value set in
  ``settings.php`` or ``additional.php`` bypasses the range check of the
  settings form and is checked again here. A default above the ceiling is the
  ceiling.
- Every reader of the constant reads the setting: the executor clamps a
  requested cap, the resume coordinator clamps the cap kept on a suspended
  state to the ceiling configured **when the run resumes**, and the loop reads
  the configured default when the caller asks for none and clamps an explicit
  cap again, so a direct caller of ``runLoop()``, ``resume()`` or
  ``resumeWithInput()`` cannot pass the ceiling by. A cap below 1 counts as no
  cap. A default given to ``ToolLoopService`` explicitly still wins over the
  setting, within the ceiling.
- ``AgentRuntime::MAX_ITERATIONS`` stays, with its value, as the fallback
  ceiling. No reader outside nr_llm used it (searched across the
  organisation's extensions on 2026-11-04), so its narrower meaning breaks no
  caller.
- ``AgentRoundLimitsInterface`` is ``@api`` and a private DI alias, so a
  consuming extension can show the values that apply. The governance tab
  lists both beside the effective-policy readout (:ref:`ADR-140 <adr-140>`),
  from a readout in the agent module, since core does not depend on it.

**Consequences.**

- A cap applies per segment, as before: a run that resumes after an approval
  or an input gets its cap again, not what was left of it.
- The settings also govern every direct caller of ``ToolLoopServiceInterface``,
  for example an editor feature's tool endpoint: no cap gets the default, a
  cap above the maximum gets the maximum.
- A process that read the settings at boot and waits for work, such as a
  pre-started worker, applies a changed value from its next start.
- The Agent Runs inbox and the playground continue a run after an approval or
  an input inside the web request. On a host whose proxy closes a request
  after a fixed time, a high ceiling can let such a request outlive it; the
  run still completes in PHP, the reader sees an error page. Measured on a
  demo installation on 2026-11-04 over 75 rounds of 24 chat runs: median 3.1 s,
  p90 7.5 s, longest 25 s per round. At the p90, 16 rounds fill a 120 s proxy
  timeout, so the fallback ceiling of 20 can already exceed it. A ceiling
  setting does not change that; it is recorded in issue #1286.

