.. include:: /Includes.rst.txt

.. _adr-063:

======================================================================
ADR-063: Provider Resilience — Circuit Breaker, Health, Idempotency
======================================================================

:Status: Accepted
:Date: 2026-07
:Authors: Netresearch DTT GmbH

.. _adr-063-context:

Context
=======

A comparison of this extension against a sibling agent framework surfaced a set
of resilience features neither side had: a **circuit breaker** (stop hammering a
provider that is down), **provider health scoring** (know which provider is
currently healthy), and **request idempotency** (a retried request must not
double-charge or produce a second, different answer). The middleware pipeline
(:ref:`adr-026`) and the telemetry log (:ref:`adr-058`) already give the seams
to add these without touching provider adapters.

The same comparison listed **OpenTelemetry** as a gap. It is addressed here too
— by deciding *not* to build it (see below).

.. _adr-063-decision:

Decision
========

Circuit breaker
---------------

A new :php:`CircuitBreakerMiddleware` tracks consecutive failing calls **per
provider**. After ``circuitBreaker.failureThreshold`` consecutive *tripping*
failures the circuit **opens**: for ``circuitBreaker.cooldownSeconds`` further
calls to that provider fail fast with a :php:`CircuitOpenException` instead of
waiting on a connection timeout. After the cooldown a single **half-open** probe
is allowed; success **closes** the circuit, failure re-opens it.

*Tripping* failures are exactly the set :php:`FallbackMiddleware` already treats
as retryable — :php:`ProviderConnectionException` (network / timeout / 5xx /
retries exhausted) and a ``429`` :php:`ProviderResponseException` (rate limit).
Client errors (other 4xx), misconfiguration and unsupported-feature errors mean
the provider *answered*; they are not a health signal and neither trip nor reset
the circuit.

**Pipeline placement — innermost, priority 20.** This is the load-bearing design
choice, so it is spelled out:

.. code-block:: text

   TelemetryMiddleware      110  observes every run
     IdempotencyMiddleware  105  replays a stored result by key
       CacheMiddleware      100  payload cache; short-circuits on hit
         BudgetMiddleware    75  pre-flight budget gate
           FallbackMiddleware 50 swaps configuration on retryable failure
             UsageMiddleware  25 records the served call
               CircuitBreaker 20 guards the actual provider call   (THIS)
                 <terminal>

* **Inside FallbackMiddleware.** An open circuit throws
  :php:`CircuitOpenException`, which :php:`FallbackMiddleware` now treats as
  retryable. Because the breaker sits *inside* the fallback loop, that exception
  is raised from within ``$next($configuration)`` on each attempt, so Fallback
  catches it and advances to the next configuration/provider. A naïve reading
  puts the breaker "between Budget (75) and Fallback (50)"; that would be
  **wrong** — outside Fallback, an open primary circuit would abort the whole
  call before any fallback ran, the opposite of the intent (skip the sick
  provider, use a healthy one). The breaker therefore lives *below* Fallback.
* **Inside UsageMiddleware.** The breaker wraps only the terminal, so the health
  signal reflects the pure provider call — usage bookkeeping (success-only, with
  its own error handling) never contaminates it, and a genuine success closes
  the circuit before any post-processing.

:php:`CircuitOpenException` extends :php:`ProviderException` (so it carries the
:ref:`adr-053` marker interface); :php:`ProviderConnectionException` is
``final``, so extending *that* was not an option — instead
:php:`FallbackMiddleware::isRetryable()` gained one explicit ``instanceof``
arm.

**Circuit state storage: cache, not a table.** State
(``consecutiveFailures`` + ``openedAt``) lives in the ``nrllm_circuit`` cache via
:php:`CircuitBreakerStoreInterface` (no hardcoded backend — the instance's
Redis/Valkey is shared across web workers, so one worker tripping a circuit
protects them all). Rationale: the state is inherently transient, self-decaying
(a forgotten entry reads as *closed*, the fail-safe default), and needs no
schema, no purge command, and no write on every call. A DB table would add write
load on the hot path and a maintenance surface for data that is meant to be
ephemeral. The half-open single-probe gate is pragmatic (refresh the open window
before probing) rather than an atomic compare-and-set, which the cache backend
cannot portably offer — a few extra probes after a cooldown is acceptable.

Provider health scoring
-----------------------

:php:`ProviderHealthService` reads the **existing** telemetry log
(:sql:`tx_nrllm_telemetry`, :ref:`adr-058`) over a rolling window and computes a
per-provider :php:`ProviderHealthScore` (success rate + mean latency into one
comparable ``0.0–1.0`` number; success rate weighted 4:1 over latency). No new
write path and no second source of truth — telemetry already records
success/latency per run, so health is a **read model** over it. Reads go through
a dedicated :php:`ProviderHealthRepository`; the telemetry recorder's own
interface stays append/purge-only, as :ref:`adr-058` intended.

The one built-in consumer is :php:`FallbackMiddleware`, which asks
:php:`ProviderHealthService::reorder()` to prefer healthier providers among the
fallback candidates. It is a **hint, opt-in, and minimal-invasive**:

* Gated by ``health.reorderFallback`` — **OFF by default**. When off, the chain
  is returned untouched with *no* telemetry query, so the configured fallback
  order stays the default.
* When on, it is a **stable** sort by descending health: providers of equal (or
  unknown) health keep their configured order, and no candidate is ever dropped.
  A provider with no telemetry scores *neutral*, never unhealthy — an
  un-exercised provider must not sink just for lack of data.

A pure config-order-primary "tie-break" would be a no-op on a totally-ordered
chain (there are no ties to break), so health-primary reordering is offered as
the opt-in instead; the default-off flag is what keeps existing behaviour
identical.

Idempotency keys
----------------

An optional idempotency key on any options object
(:php:`AbstractOptions::withIdempotencyKey()`) makes a repeated request return
the stored result instead of calling the provider again. A new
:php:`IdempotencyMiddleware` (priority 105 — just inside Telemetry, outside
Cache/Budget, so a replay short-circuits the behavioural stack and is not
re-charged, yet is still observed) stores the result under the key and replays
it on the next call with the same key. Failed calls and streaming generators are
never stored.

**Cache, not a table** — and *not* an overload of the existing cache key. The
task hypothesis ("make it a thin layer on CacheMiddleware's key") was
investigated and rejected: :php:`CacheMiddleware` is deliberately array-only (it
persists ``array<string, mixed>``), so it cannot round-trip the **typed**
responses (:php:`CompletionResponse`, …) that the chat/completion paths return —
which is the case idempotency actually matters for. The dedicated middleware
stores over its own ``nrllm_idempotency`` :php:`VariableFrontend`, which
serialises any response value, so idempotency works for every operation while
staying cache-backed: idempotency results are transient and TTL-bounded by
nature, so a table (with its purge/TCA surface) would be the wrong store.

OpenTelemetry — deliberately not built
--------------------------------------

OTel exporters/collectors are **out of scope for a TYPO3 extension**. The
extension should not own an OTLP exporter, a collector endpoint, or trace
sampling configuration — that is the **host instance's** observability
responsibility (the same instance that owns logging, APM, and metrics scraping).
What the extension *can* own — durable per-request metrics — already exists as
:sql:`tx_nrllm_telemetry` (:ref:`adr-058`) with a correlation id per run; a host
that runs OTel can scrape or forward that. Building an in-extension OTel pipeline
would duplicate host infrastructure, couple the extension to an exporter SDK, and
add configuration the operator already manages centrally.

.. _adr-063-consequences:

Consequences
============

* **Faster failure, healthier routing.** A downed provider is skipped within one
  cooldown window instead of timing out on every call; with the opt-in reorder
  on, a flapping provider is de-prioritised among fallbacks.
* **Circuit state is cluster-wide but forgettable.** On a shared cache backend
  every worker sees the same circuit; a cache flush resets all circuits to
  closed (a conservative retry), which is acceptable.
* **Health is advisory only.** With ``health.reorderFallback`` off (default),
  provider selection is byte-for-byte unchanged. The reorder, when enabled,
  re-loads each candidate configuration once to resolve its provider — a cost
  paid only on the opt-in path, on the (already-failing) fallback route.
* **Idempotency is opt-in and best-effort.** No key ⇒ no behavioural change. A
  cache miss/flush simply re-runs the call. It does not deduplicate concurrent
  in-flight requests — it replays a *completed* result.
* **One new retryable exception.** :php:`FallbackMiddleware::isRetryable()` now
  also matches :php:`CircuitOpenException`; the pipeline order test and the
  fallback tests pin this.
* **Three new caches** (``nrllm_circuit``, ``nrllm_health``,
  ``nrllm_idempotency``), all without a hardcoded backend, all in the ``nrllm``
  cache group so a group flush clears them.

.. _adr-063-alternatives:

Alternatives considered
=======================

* **Circuit state in a DB table** (``tx_nrllm_provider_circuit``). Rejected:
  ephemeral, hot-path state does not want a table, a per-call write, or a purge
  command. Cache is the natural store and shares state across workers for free.
* **Circuit breaker outside FallbackMiddleware** (priority ~60). Rejected: an
  open primary circuit would abort the call before fallback, defeating the
  purpose. The breaker must be *inside* the fallback loop.
* **Health scoring on its own rolling-window state.** Rejected: telemetry
  already records exactly the success/latency signal; a second store would
  duplicate it and drift. Read the log.
* **Health as a config-order-primary tie-break** (never reorders). Rejected as a
  no-op: a totally-ordered chain has no ties, so this would never change
  anything. Opt-in health-primary reorder (default off) gives a real,
  operator-controlled behaviour without changing the default.
* **Idempotency as a thin overload of CacheMiddleware's key.** Rejected:
  CacheMiddleware is array-only by design and cannot store the typed responses
  the valuable (chat) case returns. A dedicated middleware over its own
  VariableFrontend is the minimal store that works for every response type.
* **Idempotency in a DB table.** Rejected: transient, TTL-bounded data belongs
  in the cache, not a table with a purge/TCA surface.
* **Build OpenTelemetry into the extension.** Rejected: host-instance
  responsibility; the telemetry log already exposes per-request metrics a host
  OTel stack can consume.

.. _adr-063-followups:

Deferred / follow-ups
=====================

* A backend readout of circuit state and health scores (a diagnostics panel).
  The services expose the data; only a view is missing.
* Per-operation idempotency TTL override via call metadata (the middleware uses
  a single window today).
* Concurrent-double-submit dedup. The current get-then-run-then-set has no atomic
  reserve-on-miss — a portable one is not available through the cache
  :php:`FrontendInterface` — so two genuinely simultaneous same-key requests are
  not deduplicated; only sequential retries are (see *Consequences*). A future
  revision could gate run+store with :php:`\TYPO3\CMS\Core\Locking\LockFactory`.

.. _adr-063-references:

References
==========

* :ref:`adr-026` — Provider Middleware Pipeline (the pipeline and ordering this
  extends).
* :ref:`adr-058` — Telemetry Middleware (the log health scoring reads; the
  per-request metrics that make in-extension OTel unnecessary).
* :ref:`adr-053` — Exception marker interface (:php:`CircuitOpenException`
  inherits it via :php:`ProviderException`).
* ADR-021 — Provider Fallback Chain (the chain the health reorder reprioritises).
* ADR-028 — Public Services Policy (all new services stay private).
