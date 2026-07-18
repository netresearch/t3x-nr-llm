.. include:: /Includes.rst.txt

.. _adr-081:

============================================================================
ADR-081: Agent run persistence and a durable event stream
============================================================================

:Status: Accepted
:Date: 2026-07-18
:Authors: Netresearch DTT GmbH

.. _adr-081-context:

Context
=======

A run of the tool-calling agent loop (`ToolLoopService::runLoop()`) existed only
for the lifetime of one HTTP request. It returned a `ToolLoopResult` value object
and was then gone. The only inspectable trace, `RunTrace`, is an in-memory
collector the admin playground builds to stream steps to the browser; nothing was
written to storage.

That is the missing foundation under every larger agent capability: a run cannot
be resumed after a worker restart, queued for later execution, paused for a human
approval, audited, or replayed by a consumer UI, because there is no persisted run
to attach any of that to. Cross-call usage already carried a `correlation_id`
(`tx_nrllm_telemetry`), but nothing promoted it into a first-class run.

.. _adr-081-decision:

Decision
========

**Persist each run and its steps in two UI-less log tables**,
`tx_nrllm_agentrun` (one summary row per run) and `tx_nrllm_agentrun_event` (one
row per recorded step, ordered by ``sequence``). Both follow the telemetry
precedent (:ref:`ADR-058 <adr-058>`): raw Doctrine access through
`AgentRunRepository`, no Extbase model and no TCA, because a run log is written
and read programmatically, never edited in FormEngine.

**Drive persistence from the existing `RunTrace.onRecord` hook.** A fail-soft
`AgentRunPersister` opens a run (`begin()` → RUNNING), records each `RunStep` as
an event as it is emitted (`recordStep()`), and settles the run to a terminal
state (`settleCompleted()` / `settleFailed()`). The tool loop is **not touched**:
the persister is wired through the same per-step callback the playground already
uses to stream steps, so the loop's control flow is unchanged and unaware of it.

**Type the vocabulary without inventing what the loop cannot emit.**
`AgentRunStatus` carries the full lifecycle the later epics need (``queued``,
``running``, ``waiting_for_approval``, ``waiting_for_input``, ``completed``,
``failed``, ``cancelled``), but this change only ever writes RUNNING →
COMPLETED/FAILED. `AgentEventKind` is limited to the four kinds `RunStep` actually
produces (``request``, ``llm``, ``tool``, ``assembled``); richer kinds are added
by the epics that emit them.

**Fail-soft is a hard requirement.** Every persister method logs and swallows a
storage error; `begin()` returns null on failure, which the caller treats exactly
as a null `RunTrace` callback — "record nothing". A database hiccup can therefore
never break an otherwise-successful run. The streamed path settles the run in a
``finally`` block (mirroring `StreamingDispatcher`), so a client disconnect
mid-stream cannot leave a run stuck RUNNING.

.. _adr-081-consequences:

Consequences
============

- Playground runs (batch and streamed) now persist. The admin playground is the
  first consumer; the tables are the substrate the human-in-the-loop, batch/queue
  and feedback epics build on.
- A run that exhausts its iteration cap **or** is denied by the budget guard both
  surface as COMPLETED with ``truncated = true``, because `ToolLoopService`
  catches the budget denial internally and returns a normal result. Recording a
  budget denial as a distinct FAILED run is deferred to the human-in-the-loop
  epic, which reshapes the loop's exit paths.
- Event payloads store the full `RunStep` snapshot, which includes the messages
  sent (the prompt). `AgentRunRepository::purgeOlderThan()` provides the retention
  hook; a scheduled purge command is a follow-up. Runs are admin-authored for now
  (the playground is admin-only), so the exposure is limited.
- The persister depends on `AgentRunRepositoryInterface`, so it is unit-tested
  against a recording double; the raw SQL and schema are covered by a functional
  round-trip.
