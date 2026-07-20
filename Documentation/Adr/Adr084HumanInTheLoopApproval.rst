.. include:: /Includes.rst.txt

.. _adr-084:

============================================================================
ADR-084: Human-in-the-loop tool approval with suspend and resume
============================================================================

:Status: Accepted
:Date: 2026-07-18
:Authors: Netresearch DTT GmbH

.. _adr-084-context:

Context
=======

The agent loop (`ToolLoopService`) executes every tool the model calls
immediately. That is correct for the 41 shipped tools, which are read-only. But
a write or side-effecting tool must not run unattended — an operator has to
approve it first. The loop is synchronous (it runs to a result or throws), so
there was no way to pause it, get a human decision, and continue. The persisted
`AgentRun` (:ref:`ADR-081 <adr-081>`) already reserved the
``WAITING_FOR_APPROVAL`` status for exactly this.

.. _adr-084-decision:

Decision
========

**Opt-in marker, not a new interface method.** A tool that needs approval
implements the empty `RequiresApprovalInterface` marker. The 41 existing tools
are untouched, so their behaviour is provably unchanged — the loop only pauses
for a tool that opts in. (Adding a ``requiresApproval()`` method to
`ToolInterface` would have forced a change to every tool and every test double.)

**Suspend via a thrown control-flow signal.** When a turn contains an
approval-required call, `ToolLoopService` checks the whole turn *before executing
any of its calls* (so a multi-call turn stays consistent) and throws
`ToolApprovalRequiredException` carrying a `SuspendedRunState` — the serialised
transcript up to the assistant tool-call turn, the pending calls, and the
iteration/token counters. Using an exception keeps `runLoop()`'s return type
unchanged; the caller catches it **before** any generic ``catch (Throwable)`` so
a suspension is never mistaken for a failed run. The check is inert for the
existing tools, so the synchronous path is byte-for-byte the same.

**Persist and resume.** A new ``suspended_state`` column on ``tx_nrllm_agentrun``
stores the state; `AgentRunRepository::suspendRun()` is a *non-terminal*
transition to ``WAITING_FOR_APPROVAL`` (distinct from ``finishRun()``, which sets
a terminal status and clears the state). `ToolLoopService::resume()` rehydrates
the transcript, executes the pending calls (on approval) or feeds back a denial
result (on refusal), then re-enters `runLoop()` with assembly skipped — the
transcript already carries the system prompt and skills. The pre-suspend counters
are folded into the returned result so the totals span the whole run. The
playground exposes it through a ``resumeAction`` / ``nrllm_tool_resume`` route.

.. _adr-084-consequences:

Consequences
============

- A side-effecting tool now gets a human gate for free by implementing one empty
  marker; nothing else about the tool changes.
- Approval is per **suspension** (approve/deny the pending turn), not per
  individual call — the whole turn is held and resumed together, which keeps the
  provider transcript valid.
- The resumed tool execution is recorded on the run's event stream (the
  playground step list), but not in the lean `ToolLoopResult::$trace`
  (``ToolInvocation`` list), which only covers the continued loop. The event
  stream is the audit record; the invocation list is a summary.
- ``suspended_state`` stores the transcript (including prompts). It is cleared on
  settle, and — like the rest of the run — bounded by the AgentRun retention
  purge.
- The primitive lives in the runtime (`ToolLoopService` + the persistence layer),
  so any consumer — not only the playground — can suspend and resume; a
  downstream editor "review before the AI writes" flow builds on it.
- **Public-service policy (count authority).** Extracting
  ``ToolLoopServiceInterface`` — so downstream extensions inject and test-double
  the loop (both ``runLoop()`` and ``resume()``) rather than the final
  ``ToolLoopService`` — adds one ``public: true`` override, a Category B
  supporting-service interface alias (the concrete stays private). The audited
  count rises from **32 to 33** (Category B 5 → 6); ``PublicServicesPolicyTest``
  is updated to match, and this ADR supersedes ADR-083 as the count authority.
- Not in scope: a resume that re-plans (the model is simply continued from the
  approved result or the refusal), and per-call approval within a multi-call
  turn.
