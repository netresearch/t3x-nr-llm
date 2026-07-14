.. include:: /Includes.rst.txt

.. _adr-058:

==========================================
ADR-058: Telemetry Middleware
==========================================

:Status: Accepted
:Date: 2026-07
:Authors: Netresearch DTT GmbH

.. _adr-058-context:

Context
=======

The middleware pipeline (:ref:`adr-026`) records cost and tokens through
:php:`UsageMiddleware`, but only for calls that *succeed*. Its own doc block
names the gap:

    The middleware never runs when ``$next`` throws: failed calls are not
    tracked here. If failure-rate telemetry is needed later, a dedicated
    middleware can wrap and record regardless of outcome.

So today there is no durable record of:

* how often a provider call *fails*, and with which exception type;
* how long a call takes (latency), including the cache lookup;
* whether a response was served from cache;
* how many fallback configurations had to be tried before one answered.

:php:`UsageMiddleware` writes to :sql:`tx_nrllm_service_usage`, which is a
**daily aggregate** keyed by service/provider/model/user — it cannot answer
"which correlation id failed at 14:03 and why". Correlation ids exist on the
:php:`ProviderCallContext` and are already logged by
:php:`FallbackMiddleware`, but logs are not queryable telemetry.

.. _adr-058-decision:

Decision
========

Add a dedicated :php:`TelemetryMiddleware` and a per-request log table.

**1. Table** :sql:`tx_nrllm_telemetry` — one immutable row per pipeline run
(not an aggregate). Columns: ``correlation_id``, ``operation``, ``provider``,
``model``, ``configuration_identifier``, ``be_user``, ``success``,
``error_class``, ``latency_ms``, ``cache_hit``, ``fallback_attempts``,
``crdate``. No TCA / backend UI — it is a log read via SQL / analytics, like
the other UI-less tables (:sql:`tx_nrllm_service_usage`,
:sql:`tx_nrllm_tool_state`).

**2.** :php:`TelemetryMiddleware` **at priority 110** — the outermost layer,
*outside* Cache. It measures wall-clock latency with :php:`hrtime()` around
``$next``, catches any :php:`Throwable`, writes **exactly one** row on both
success and failure, and re-throws the exception unchanged. Latency therefore
includes the cache lookup, and a cache-served response still produces a row.

**3. Cache-hit signal.** The pipeline threads one immutable
:php:`ProviderCallContext` through every layer and the ``$next`` callable only
forwards the :php:`LlmConfiguration` — never a context. An inner middleware
thus cannot hand a modified context back to an outer one. The one channel that
survives the unwind is a mutable object reachable from the shared context:
:php:`TelemetrySignals`. The context default-constructs one per call.
:php:`CacheMiddleware` calls ``recordCacheHit()`` on a hit; the outer
:php:`TelemetryMiddleware` reads it.

**4. Fallback count.** :php:`FallbackMiddleware` calls
``recordFallbackAttempt()`` on :php:`TelemetrySignals` once per fallback
configuration it actually dispatches (the primary attempt is not counted); the
middleware reads it (``0`` when it was never set).

**5. Attribution.** ``be_user`` is the caller-supplied
:php:`BudgetMiddleware::METADATA_BE_USER_UID` when present, else the ambient
``backend.user`` context aspect, else ``0`` — the same resolution
:php:`UsageTrackerService` uses.

**6. Persistence** goes through a narrow
:php:`TelemetryRepository` (Doctrine :php:`ConnectionPool` directly, no Extbase
repository), mirroring how :php:`UsageTrackerService` writes. The service is
**private** (ADR-028); nothing resolves it by class name from the container.

**7. Purge command** :bash:`nrllm:telemetry:purge` (``--days``, default 30)
deletes rows older than the retention window. Registered via the native
:php:`#[AsCommand]` attribute (autoconfigured), like TYPO3 core commands.

**8. Deactivation.** The extension setting ``telemetry.enabled`` (default
**on** — observability by default) turns the middleware into a verbatim
pass-through when disabled.

.. _adr-058-consequences:

Consequences
============

* **One row per request = growth.** Unlike the usage aggregate, the log grows
  with traffic. The :bash:`nrllm:telemetry:purge` command bounds it; run it
  from the scheduler.
* **error_class, not message — a deliberate privacy trade-off.** Only the
  exception FQCN is stored. Exception messages can carry payload fragments
  (a prompt substring, a URL with a token), so they are never persisted here.
  No prompts and no responses are stored either. The central privacy model
  (retention tiers none/metadata/redacted/full) is a later workstream; this
  middleware is metadata-only by construction.
* **Latency includes the cache lookup.** Because Telemetry sits outside Cache,
  ``latency_ms`` measures the whole pipeline as the caller experiences it,
  cache hits included. That is the intended semantic (end-to-end latency), not
  provider-only time.
* **provider / model reflect the requested primary configuration.** Telemetry
  sits outside :php:`FallbackMiddleware`, so it records the configuration the
  caller *asked for*. A fallback swap shows up as ``fallback_attempts > 0``;
  the provider/model/cost of the configuration that actually *served* live in
  the usage table (:php:`UsageMiddleware` sees the served config). Ad-hoc
  direct calls carry no attached model, so provider/model are empty and the
  provider is encoded in the ``ad-hoc:<operation>:<provider>`` identifier.
* **Fail-soft.** A telemetry write error is logged and swallowed; it never
  breaks the call it observes.
* **Streaming produces no telemetry row.** Streaming deliberately stays out of
  the pipeline (:ref:`adr-026`) — once the first chunk is emitted a provider
  cannot be swapped mid-stream. A streaming lifecycle (with its own
  telemetry) is a separate workstream.
* **Mutable state on an "immutable" context.** :php:`TelemetrySignals` is the
  one mutable object the otherwise-immutable :php:`ProviderCallContext`
  carries. It holds only cross-cutting observability state, never payload, so
  it does not weaken ADR-026's "payload stays in the terminal closure" rule.
  A dedicated typed property (rather than a magic metadata key seeded by every
  caller) means every pipeline run — present and future entry points — captures
  cache/fallback signals with no per-caller wiring.

.. _adr-058-alternatives:

Alternatives considered
=======================

* **Extend** :php:`UsageMiddleware` **to also record failures.** Rejected:
  usage is an aggregate keyed for cost roll-ups; per-request failure/latency
  rows have a different shape, lifetime (purged) and index set. Overloading one
  table would break both.
* **Log-only (PSR-3), no table.** Rejected: logs are not queryable telemetry.
  Failure-rate and latency questions need a table an analytics view can group.
* **Seed a mutable signal bag into the metadata map at each pipeline entry
  point.** Rejected: it pushes telemetry wiring into every caller and silently
  loses cache/fallback signals for any future entry point that forgets to seed.
  A default-constructed context property covers all runs by construction.
* **Record provider/model from the response.** Rejected for the outer layer:
  there is no response on the failure path, and duplicating
  :php:`UsageMiddleware`'s response-shape extraction at the outermost layer
  couples telemetry to every response type. Sourcing from the requested
  configuration is deterministic on success *and* failure.

.. _adr-058-references:

References
==========

* :ref:`adr-026` — Provider Middleware Pipeline (the pipeline and ordering this
  extends; :php:`UsageMiddleware`'s open failure-telemetry note).
* ADR-025 — Per-User AI Budgets (``be_user`` attribution key reused here).
* ADR-028 — Public Services Policy (the recorder stays private).
