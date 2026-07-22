.. include:: /Includes.rst.txt

.. _adr-107:

============================================================================
ADR-107: Agent-loop context-window management
============================================================================

:Status: Accepted
:Date: 2026-07-22
:Authors: Netresearch DTT GmbH

.. _adr-107-context:

Context
=======

The tool loop (:ref:`ADR-081 <adr-081>` ff.) appends each assistant tool-call
turn and its tool_result messages to one transcript that is re-sent on every
iteration. Nothing bounded that transcript against the model's context window
(``Model::getContextLength()``), so a long agentic run — many tool calls, large
tool outputs — eventually overflowed the window and failed at the provider with
a raw 4xx that :ref:`FailureClassifier <adr-095>` maps to a generic
``CLIENT_ERROR``, indistinguishable from an auth or config error.

.. _adr-107-decision:

Decision
========

An optional ``ContextWindowManager`` collaborator on ``ToolLoopService`` bounds
the transcript before each provider send. Absent it (the lean test wiring) the
loop sends the full transcript exactly as before — every enforcement site is a
no-op.

- **Turn-atomic pruning (the correctness crux).** Pruning drops oldest WHOLE
  turns — an assistant tool-call message together with ALL its tool_result
  replies — so the tool-call/tool-result pairing the provider requires is never
  broken. The head (the leading system run plus everything up to and including
  the first user message) and the newest turn are never dropped, so the output
  is a structurally valid, non-empty transcript that still carries the task and
  the most recent context. A cheap post-fit pairing guard defers to the provider
  rather than ever emit a known-orphaned request. Summarization was rejected: it
  needs an extra provider call per prune (cost, latency, non-determinism through
  ``SuspendedRunState``); dropping is deterministic, cheap and provably safe.
- **Over-counting estimator.** No BPE tokenizer (no runtime dependency): a
  content-class-aware ``chars/N`` — prose divides by 3.5, DENSE segments (tool
  JSON arguments, tool_result payloads, the tool-schema block) by 2.5, plus
  per-message and per-tool-call overhead. A calibration factor seeded above 1.0
  scales the estimate and only ever grows toward the real prompt-token counts
  each provider call reports, so the estimate errs high throughout and never
  under-prunes into an overflow. The manager is stateful per run and self-resets
  on each loop's first send (a null ``lastUsage``), so a single shared
  ``ToolLoopService`` never carries one run's calibration into the next.
- **Reserve + graceful failure.** ``budget = contextLength - reserve -
  safety``, where the reserve is the response allocation (options ``max_tokens``,
  else the model output cap, else a proportional floor) and an unknown context
  length falls back to a conservative 8192. When even the pruned floor still
  exceeds the budget, no provider call is made: a ``ContextTruncatedException``
  stops the loop on the new ``AgentRunTerminationReason::CONTEXT_TRUNCATED``
  (non-retryable) — a legible terminus instead of a misclassified provider 4xx.

.. _adr-107-consequences:

Consequences
============

- ``AgentRunTerminationReason`` gained ``CONTEXT_TRUNCATED``
  (``isRetryable() === false``) — the documented minor-release growth path.
- Global default only, no per-configuration knob (YAGNI); ``maxTokensPerDay`` /
  ``modelSelectionMode`` are the storage precedent if an override is ever needed.
- Enforcement covers the three real provider-send sites in ``runLoop`` (the
  in-loop tool send, the no-tools plain completion, the cap-hit synthesis);
  every send goes through one of them, so no separate pre-loop assembly pass is
  needed. The plain-completion sites pass no tool schemas, so phantom schema
  bytes never inflate the estimate of a payload that will not carry them.
- **Streaming is out of scope.** ``StreamingDispatcher`` runs a separate
  single-shot pipeline that never calls ``runLoop()``; bounding a streamed
  request against the window (and reconciling its own ``chars/4`` heuristic) is
  a follow-up.
- Observability: a pruning event is logged at info level; a dedicated inspector
  ``RunStep`` for the trace is a follow-up. The ``CONTEXT_TRUNCATED`` reason is
  the load-bearing operator signal and is on the result.
