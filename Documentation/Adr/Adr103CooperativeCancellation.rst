.. include:: /Includes.rst.txt

.. _adr-103:

============================================================================
ADR-103: Cooperative cancellation at step boundaries
============================================================================

:Status: Accepted
:Date: 2026-07-21
:Authors: Netresearch DTT GmbH

.. _adr-103-context:

Context
=======

Cancellation has been a persistence-level fence since :ref:`ADR-092 <adr-092>`:
the guarded terminal transition wins the row and a late settle is discarded —
but the in-flight loop itself kept running to completion, spending provider
calls and executing tools whose outcome was then thrown away. With queued runs
(:ref:`ADR-102 <adr-102>`) that gap grows: a worker run can be long, and
``nrllm:agent:cancel`` is the only brake an operator has. The P1 roadmap asks
for exactly this: check the cancel flag *between* model and tool steps.

.. _adr-103-decision:

Decision
========

The AgentRuntime's trace hook gains a **cancellation probe**. Every step
boundary — after a provider response, after each tool execution, before the
next round — already records a step through the runtime's ``onRecord`` closure;
the probe re-reads the run row there and, when it is CANCELLED, throws the
internal ``RunCancellationRequestedException``. The ladder catches it as
control flow (before the generic Throwable), attempts **no** settle (the
cancel already won the terminal transition; a late settle would be discarded
anyway) and returns the new ``AgentRunOutcome::CANCELLED``.

Properties:

- **The loop stays persistence-unaware** (ADR-081): the probe lives entirely
  in the runtime's trace closure; ``ToolLoopServiceInterface`` is untouched.
- **A step in flight runs to its boundary** — cancellation is a cooperative
  check, not a signal. The boundary step itself is still emitted and
  persisted, so the audit stream is complete up to the abort point.
- **No further spend after the boundary**: the next provider call and the
  pending tool executions of the following round never start.
- **Fail-soft probe**: the row read goes through the fail-soft persister — a
  store hiccup yields null, never a fabricated cancellation. One indexed row
  read per step; steps are provider-call-slow, so the cost is noise.
- Works identically for interactive (``run()``), resumed (``approve()``) and
  queued (``runQueued()``) segments — they share the one trace builder.

.. _adr-103-consequences:

Consequences
============

- ``AgentRunOutcome`` gained ``CANCELLED`` (the documented minor-release
  growth path; consumers match with a default arm). The playground maps it to
  a ``status: 'cancelled'`` payload / ``cancelled`` stream event — a decision,
  not an error.
- ``AgentRuntimeInterface::cancel()``'s contract is upgraded from "fence only"
  to "fence + cooperative stop at the next step boundary".
- A cancelled run's in-flight segment now ends within one step instead of
  running to completion — the remaining latency is bounded by the longest
  single step (one provider call or one tool execution), which the future
  heartbeat/lease epic can further constrain.
