.. include:: /Includes.rst.txt

.. _adr-026:

==========================================
ADR-026: Provider Middleware Pipeline
==========================================

:Status: Accepted
:Date: 2026-04
:Authors: Netresearch DTT GmbH

.. _adr-026-context:

Context
=======

Every provider call in the extension is wrapped by the same
cross-cutting concerns — or rather, it *should* be, but today those
concerns are scattered:

* :php:`FallbackChainExecutor` (:code:`Classes/Service/FallbackChainExecutor.php`)
  is a ``try primary / catch / foreach fallbacks`` loop with two retryable
  exception types hardcoded. It has no pre/post hooks and no composition
  seam.
* It is applied **only** to database-backed configuration paths in
  :php:`LlmServiceManager::runWithFallback()`. Direct calls — ``chat()``,
  ``complete()``, ``embed()``, ``vision()`` — bypass it entirely, which
  silently splits retry semantics.
* :php:`BudgetService::check()` (ADR-025) and
  :php:`UsageTrackerService::trackUsage()` are primitives that no feature
  service actually calls. Budget enforcement and usage accounting must
  be remembered by every caller, which is a silent footgun.
* HTTP-level retry with back-off lives inside :php:`AbstractProvider`
  (``sendRequest()``). That is the wrong layer — a rate-limited provider
  should be *swapped*, not retried in-place.
* Cache lookup exists only inside :php:`EmbeddingService` as ad-hoc
  branches. There is no way to plug it in for deterministic completion
  scenarios (seed / temperature 0) without duplicating the branch.

The end result is that every new cross-cutting requirement — PII
redaction, prompt logging, trace correlation, per-provider rate limits,
circuit breakers, a cost calculator — forces either a bespoke branch in
every feature service or a subclass of one of the god classes.

.. _adr-026-decision:

Decision
========

Introduce a PSR-15-inspired middleware pipeline under
:code:`Classes/Provider/Middleware/`:

.. code-block:: php
   :caption: the contract

   interface ProviderMiddlewareInterface
   {
       public function handle(
           ProviderCallContext $context,
           LlmConfiguration $configuration,
           callable $next,           // callable(LlmConfiguration): mixed
       ): mixed;
   }

Each middleware receives

1. an immutable :php:`ProviderCallContext` (operation kind, correlation
   id, metadata map),
2. the current :php:`LlmConfiguration`,
3. a ``$next`` callable that continues the pipeline.

and decides whether to pass through, short-circuit, swap the
configuration, or wrap the call with before/after logic.
:php:`MiddlewarePipeline::run()` composes an ordered stack of them
around a terminal callable in classic onion fashion — the
first-registered middleware is the outermost layer.

The payload — messages, embedding input, tool specs, vision content —
stays captured in the terminal callable. That keeps the existing typed
response objects (:php:`CompletionResponse`, :php:`EmbeddingResponse`,
:php:`VisionResponse`) intact on the return side and avoids inventing a
generic ``ProviderRequest`` envelope that would then have to know about
every operation variant.

.. _adr-026-registration:

Registration
============

Implementations are discovered via the ``nr_llm.provider_middleware``
tag, which :php:`AutoconfigureTag` applies automatically to every class
that implements the interface. The pipeline's constructor injects the
collected middleware via :php:`AutowireIterator`. Ordering follows tag
priority; ``priority`` is an ordering hint only.

Contributors can add behaviour without touching :code:`Services.yaml` —
implement the interface, drop the class under
:code:`Classes/Provider/Middleware/`, you are done.

.. _adr-026-scope:

Scope of this ADR
=================

Infrastructure only. No behaviour change in this PR:

* :php:`ProviderMiddlewareInterface`, :php:`MiddlewarePipeline`,
  :php:`ProviderCallContext`, :php:`ProviderOperation` enum.
* Unit tests covering empty pipeline, single/multiple composition,
  short-circuit, configuration substitution, context propagation,
  generator-based iterables.
* This ADR.

:php:`FallbackChainExecutor` stays untouched. Feature services continue
to work exactly as they do today. The pipeline is opt-in: consumers
have to build a terminal callable and call :php:`MiddlewarePipeline::run()`
to use it.

.. _adr-026-followups:

Follow-ups
==========

Each item below is a separate PR that lands one behaviour at a time, so
the test matrix keeps green end-to-end:

1. **FallbackMiddleware** — port :php:`FallbackChainExecutor` to the
   interface. :php:`LlmServiceManager::runWithFallback()` stops
   instantiating the executor directly and runs the pipeline instead.
   Retry semantics become identical for *every* call path, not just
   database-backed ones. Deprecate the standalone executor.
2. **BudgetMiddleware** — call :php:`BudgetService::check()` before
   ``$next``; throw a typed :php:`BudgetExceededException` on denial so
   controllers can report which bucket tripped.
3. **UsageMiddleware** — after ``$next`` returns, hand the response to
   :php:`UsageTrackerService::trackUsage()`. Centralises cost/token
   accounting regardless of which feature called in.
4. **CacheMiddleware** — opt-in per operation via
   :php:`ProviderOperation`. Embedding lookups start going through it;
   the branch currently inside :php:`EmbeddingService` comes out.
5. **Direct-method wiring (centralised)** — every direct API method on
   :php:`LlmServiceManager` (``chat``, ``complete``, ``embed``,
   ``vision``, ``chatWithTools``) builds its terminal callable and
   invokes the pipeline via a synthesised transient
   :php:`LlmConfiguration`. Because every feature service
   (:php:`CompletionService`, :php:`EmbeddingService`,
   :php:`TranslationService`, :php:`VisionService`) delegates to these
   methods, feature-service traffic inherits the full middleware stack
   without each service owning its own pipeline glue.

   The transient configuration is unpersisted (no uid), carries an
   empty fallback chain (so :php:`FallbackMiddleware` passes through
   verbatim), and uses a human-readable ``ad-hoc:<operation>:<provider>``
   identifier so log / trace labels distinguish direct traffic from
   configuration-backed calls. Middleware that needs more context
   (``beUserUid`` for :php:`BudgetMiddleware`, cache keys for
   :php:`CacheMiddleware`) reads it from the
   :php:`ProviderCallContext` metadata, not from the configuration.

   Streaming (:php:`streamChat` / :php:`streamChatWithConfiguration`)
   deliberately stays out of the pipeline per the ADR's original scope:
   once the first chunk has been emitted, we cannot swap providers
   mid-stream, and most middleware assume a single terminal result.

   **Why the centralised form rather than "every feature service owns
   glue":** the ADR's problem statement explicitly identifies direct
   calls as the bug ("``chat()``, ``complete()``, ``embed()``,
   ``vision()`` — bypass [the fallback executor] entirely, which
   silently splits retry semantics"). Wiring feature services only
   would have left direct :php:`LlmServiceManager` callers still
   bypassing the pipeline. Centralising on :php:`LlmServiceManager`
   fixes both in one step and keeps feature services free of pipeline
   concerns.

Each follow-up is scoped to a single concern and keeps the codebase
shippable after every step.

Remaining cleanup
-----------------

:php:`EmbeddingService::embedFull()` still contains an inline cache
branch from before the middleware landed. The branch is harmless —
:php:`CacheMiddleware` is opt-in via :php:`ProviderCallContext`
metadata keys, and the direct-call pipeline does not currently set
them — but it is dead duplication. Removing it requires (a) adding
``toArray()`` / ``fromArray()`` helpers to the typed response objects
so :php:`CacheMiddleware` (which persists ``array<string, mixed>``)
can store / restore them, and (b) plumbing the cache key from
:php:`EmbeddingOptions` through :php:`LlmServiceManager::embed()` onto
the context metadata. Tracked separately; does not block this step.

.. _adr-026-alternatives:

Alternatives considered
=======================

* **Per-operation pipelines** (separate middleware stacks for chat /
  embed / vision / tools). Rejected: every middleware we can foresee
  — fallback, budget, usage, cache, retry, tracing — wants to run for
  multiple operations. Filtering inside a middleware via
  :php:`ProviderCallContext::operation` is cheaper than maintaining N
  parallel stacks.
* **Generic ``ProviderRequest`` envelope** with a ``mixed $payload``.
  Rejected: forces every provider / middleware / test to downcast
  payloads. Keeping the payload inside the terminal closure preserves
  the typed signatures already defined by :php:`ProviderInterface` and
  the capability interfaces.
* **PSR-15 directly** (``ServerRequestInterface`` / ``ResponseInterface``
  shapes). Rejected: HTTP semantics do not fit an LLM call, mapping
  OpenAI's message array onto a :php:`ServerRequestInterface` is lossy,
  and the extension already owns :php:`LlmConfiguration` and typed
  response objects that are a better fit than a generic PSR-7 request.
* **Event dispatcher** (PSR-14) pre/post hooks. Rejected: events cannot
  short-circuit, cannot substitute the call target, and cannot return a
  response to the caller — all three are load-bearing for fallback and
  cache middleware.

.. _adr-026-references:

References
==========

* Audit (2026-04-23): claim #1 — "No middleware pipeline — cross-cutting
  concerns are scattered or absent". Locally stored under
  :code:`claudedocs/audit-2026-04-23-architecture.md`.
* ADR-021 — Provider Fallback Chain (the behaviour this pipeline will
  eventually subsume).
* ADR-025 — Per-User AI Budgets (budget primitive to be wired via
  BudgetMiddleware).
