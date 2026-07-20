.. include:: /Includes.rst.txt

.. _adr-097:

============================================================================
ADR-097: Specialized services dispatch through the shared pipeline
============================================================================

:Status: Accepted
:Date: 2026-07-20
:Authors: Netresearch DTT GmbH

.. _adr-097-context:

Context
=======

The specialized services — DALL·E, FAL, Whisper, TTS, DeepL — dispatch HTTP
directly and bypass the middleware pipeline, so they get none of what a chat
call gets: no telemetry row, no correlation id, no circuit breaker, no uniform
error classification. :ref:`ADR-096 <adr-096>` made that reachable by moving the
configuration onto the call context, so a caller without an ``LlmConfiguration``
entity can now drive the pipeline through a ``forService()`` context.

.. _adr-097-decision:

Decision
========

``AbstractSpecializedService`` takes the ``MiddlewarePipeline`` as a required
dependency and exposes ``runLifecycle(ProviderCallContext $context, callable
$call)``, which runs the actual HTTP dispatch as the pipeline terminal. Each
service builds a ``ProviderCallContext::forService(operation, provider, model)``
and wraps its dispatch in ``runLifecycle()``.

For a service context (no configuration entity, no budget metadata) most
middleware self-disable: fallback has no chain, cache and idempotency have no
key, the guardrail passes a non-completion result through, and the budget
middleware is inert because the per-call budget is still enforced by
``enforceBudget()`` before dispatch. What the pipeline adds is a **telemetry row
with a correlation id** and the **provider circuit breaker** — a flapping image
or speech endpoint now trips and fails fast like a chat provider.

This ADR migrates the first service, **DALL·E** (all four entry points:
generate, generate-multiple, variations, edit). The other four follow the same
shape.

.. _adr-097-consequences:

Consequences
============

- **Breaking:** ``AbstractSpecializedService`` gained a required
  ``MiddlewarePipeline`` constructor parameter (after ``budgetService``). A
  subclass or manual construction must pass it; an empty ``MiddlewarePipeline([])``
  is a valid pass-through for tests that do not exercise the lifecycle.
- DALL·E calls now write a telemetry row (operation ``image``, the provider and
  model, a correlation id) and are guarded by the circuit breaker. No other
  behaviour changes: usage is still recorded by the service's own
  ``trackImageUsage()`` (unifying that into a pipeline extractor is a later
  step), and the budget is still enforced by ``enforceBudget()`` before
  dispatch — the budget middleware stays inert for a service context, so there
  is no double check.
- ``Classes/Specialized`` now depends on ``Classes/Provider/Middleware``. That is
  the point — the specialized services join the shared lifecycle rather than
  reimplementing it.
- Deferred, each its own step: migrating FAL / Whisper / TTS / DeepL; applying
  input-guardrail screening to the specialized prompts; the fail-closed dispatch
  seam that makes a forgotten lifecycle wrapper throw rather than spend
  unmetered (it can only be switched on once all five services route through
  ``runLifecycle()``); and folding the per-service usage recording into a tagged
  pipeline extractor.
