.. include:: /Includes.rst.txt

.. _adr-096:

============================================================================
ADR-096: The pipeline configuration lives on the call context
============================================================================

:Status: Accepted
:Date: 2026-07-20
:Authors: Netresearch DTT GmbH

.. _adr-096-context:

Context
=======

The middleware pipeline that wraps every chat/embedding/vision call —
budget, telemetry, cache, idempotency, guardrail, fallback, usage, circuit
breaker — took the ``LlmConfiguration`` as a **separate positional parameter**
of ``MiddlewarePipeline::run()`` and of every ``ProviderMiddlewareInterface::handle()``.
Six of the eight middleware merely forwarded it; it existed as its own parameter
only so ``FallbackMiddleware`` could substitute a sibling configuration on a
retryable failure.

That shape hard-wires the pipeline to callers that *have* an ``LlmConfiguration``
entity. The specialized services — DALL·E, FAL, Whisper, TTS, DeepL — do not:
they are identified by provider and model strings and dispatch HTTP directly,
which is exactly why they bypass the pipeline and, with it, telemetry,
correlation ids, the circuit breaker and input guardrails.

.. _adr-096-decision:

Decision
========

**The configuration moves onto the context.** ``ProviderCallContext`` gains a
nullable ``configuration`` plus ``provider`` / ``model`` /
``configurationIdentifier`` strings. ``MiddlewarePipeline::run(context, terminal)``
and ``ProviderMiddlewareInterface::handle(context, next)`` drop the separate
configuration parameter; ``$next`` and the terminal now receive the context.
``FallbackMiddleware`` swaps the configuration through
``ProviderCallContext::withConfiguration()``.

When the configuration entity is present it is the source of truth; when it is
null the string fields are — ``telemetryProvider()`` / ``telemetryModel()`` /
``telemetryConfigurationIdentifier()`` encode that fallback in one place, so
telemetry, usage and the circuit key work whether the call came from a
configuration entity or a bare service descriptor. Three factories name the
intent: ``for()`` (generic), ``forConfiguration()`` (an entity), ``forService()``
(provider/model strings, no entity).

``ProviderOperation`` gains the specialized cases — image generation/edit/
variation, transcription, speech synthesis, translation — so every AI call is
labelled from one vocabulary.

The class names keep the ``Provider`` prefix for now. Renaming
``ProviderCallContext`` → ``AiCallContext`` and the sibling types is a pure
cosmetic follow-up (~40 references) and is deliberately not bundled into this
behaviour-preserving change.

.. _adr-096-consequences:

Consequences
============

- **No behaviour change.** This is a structural refactor: the chat path builds a
  context via ``forConfiguration()`` and every existing test passes unchanged in
  intent. ``TelemetryMiddleware`` reads provider/model/identifier from the
  context helpers rather than the entity, which also means the "requested
  primary configuration" survives a fallback swap for free.
- **Breaking for downstream pipeline callers.** ``MiddlewarePipeline::run()`` and
  ``ProviderMiddlewareInterface::handle()`` changed signature, and a custom
  middleware or a direct ``run()`` caller must move the configuration onto the
  context. In-tree this was a mechanical migration across the middleware tests.
- ``UsageMiddleware`` and ``CircuitBreakerMiddleware`` now tolerate a null
  configuration (a specialized call): usage attributes by the context's model
  string and the circuit keys off the context's provider.
- This is the enabling step. It delivers no user-visible change on its own — the
  specialized services do not yet route through the pipeline. Adding the
  fail-closed dispatch seam and migrating those five services onto it is the
  following step, at which point they gain telemetry, correlation ids, the
  circuit breaker and input guardrails.
