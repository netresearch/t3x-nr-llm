.. include:: /Includes.rst.txt

.. _adr-062:

==========================================
ADR-062: Streaming Request Lifecycle
==========================================

:Status: Accepted
:Date: 2026-07
:Authors: Netresearch DTT GmbH

.. _adr-062-context:

Context
=======

Every non-streaming provider call goes through the middleware pipeline
(:ref:`adr-026`) and so inherits budget pre-flight (:ref:`adr-025`), usage
accounting, and telemetry (:ref:`adr-058`). Streaming did not: both
:php:`LlmServiceManager::streamChat()` and
:php:`streamChatWithConfiguration()` called the provider's
:php:`streamChatCompletion()` generator directly and returned it to the caller.

The consequence was a live budget hole. A streamed chat:

* ran **no** budget pre-flight — an over-budget user could stream freely;
* produced **no** usage row in :sql:`tx_nrllm_service_usage`, so streamed
  tokens were invisible to cost dashboards and to the very budget aggregate
  that is supposed to gate the next call;
* produced **no** telemetry row in :sql:`tx_nrllm_telemetry`, so a streamed
  call had no latency, no outcome, and no correlation id on record;
* had no fallback, not even in the window where one is still possible.

The pipeline cannot simply be reused. A PHP generator is **lazy**: calling
:php:`streamChatCompletion()` returns a suspended generator without running a
line of it. Wrapping that as the pipeline terminal makes every middleware run
against a stream that has not started — Budget would gate nothing meaningful,
:php:`UsageMiddleware` (which records *after* the terminal returns) would record
zero tokens, and :php:`TelemetryMiddleware` would measure a near-zero latency.
This laziness is exactly why :ref:`adr-026` sanctioned streaming as a documented
pipeline bypass.

.. _adr-062-decision:

Decision
========

Introduce a dedicated streaming lifecycle rather than forcing streams through
the response pipeline. A single private collaborator,
:php:`Netresearch\NrLlm\Service\Streaming\StreamingDispatcher`, owns it; the
manager's two streaming methods build an *opener* closure and hand off to it.
The dispatcher is a **wrapping generator** — it never changes the public
:php:`Generator`\<int, string, mixed, void> contract the seven providers and
their consumers rely on.

The lifecycle has four stages:

1. **Budget pre-flight — eager.** :php:`StreamingDispatcher::stream()` runs the
   same :php:`BudgetService::check()` gate as :php:`BudgetMiddleware` *before* it
   returns a generator, so an over-budget caller is rejected at call time with a
   typed :php:`BudgetExceededException` — not lazily on first iteration. This is
   the one part that must be eager: a caller that never drains the stream is
   never charged, but a caller that is already over budget must never receive a
   stream to drain.

2. **Provider selection with fallback — before the first chunk only.** The
   dispatcher walks the primary configuration's fallback chain (shallow, exactly
   as :php:`FallbackMiddleware` does) and *primes* each candidate generator
   (``rewind()``) inside a try/catch. Priming runs the provider up to its first
   yield — the HTTP request and first delta — which is the last moment a
   provider can still be swapped. A retryable failure here
   (:php:`ProviderConnectionException`, or a ``429``
   :php:`ProviderResponseException`) moves to the next candidate; a non-retryable
   failure bubbles up unchanged. Once a chunk has been handed to the caller a
   swap is impossible, so **fallback stops at the first chunk**. That single
   asymmetry with the non-streaming pipeline is intrinsic to streaming, not a
   shortcut.

3. **Drain accounting — lazy.** As the caller drains, the wrapper re-yields each
   chunk, appends it to a completion buffer, and stamps the time-to-first-token
   on the first chunk delivered.

4. **Settlement — in a ``finally``.** Usage and telemetry are written in the
   drain generator's ``finally`` block, so they land on **every** exit path:
   normal completion, a mid-stream exception, and an abandoned generator (client
   disconnect or a consumer ``break``). PHP runs a suspended generator's
   ``finally`` when the generator is destroyed, which is what makes early-break
   accounting work without a caller callback. An abandoned stream therefore
   records the **partial** tokens actually produced, never zero and never the
   full amount.

.. _adr-062-usage-estimation:

Token counts are estimated
==========================

The seven streaming adapters yield only text deltas and return ``void`` — none
emits a usage frame at stream end. Real per-token usage is therefore *not
available* on this path today. The dispatcher estimates it with the ≈4
chars/token heuristic already used by
:php:`RenderedPrompt::estimateTokens()`: the caller passes the prompt character
count on the context metadata (it holds the messages; the dispatcher never sees
the payload, honouring the :ref:`adr-026` "context carries no payload" rule) and
the dispatcher counts the drained completion text.

Recording an estimate is a deliberate improvement over the previous state, which
recorded nothing at all — for budget enforcement an approximate figure is far
better than a silent zero. Exact stream usage would require enabling
provider-level usage frames (OpenAI ``stream_options.include_usage``, Anthropic
``message_delta`` usage, …) and threading them out of the generator without
breaking its ``string`` yield type — a follow-up, tracked separately, that would
replace the estimate with the reported figure where a provider supports it.

.. _adr-062-attribution:

Attribution
===========

Mirroring the non-streaming split (:ref:`adr-058`):

* **Telemetry** names the *requested* primary configuration; a pre-first-chunk
  swap shows as ``fallback_attempts > 0``. ``cache_hit`` is always ``false`` —
  streaming never caches. A new nullable ``time_to_first_token_ms`` column
  carries the TTFT; it is ``NULL`` for every non-streaming row (there is no
  partial-response milestone to measure), deliberately distinct from a real
  ``0 ms``.
* **Usage** attributes to the configuration that actually *served* — after a
  fallback swap that is the fallback's provider/model/uid, not the primary's.
  For an ad-hoc stream (a pinned provider with no configuration entity) the
  served provider comes from the context metadata the manager sets.

.. _adr-062-scope:

Scope
=====

* New :php:`StreamingDispatcher` (private service, autowired) and the
  :php:`LlmServiceManager` wiring for both streaming entry points.
* :php:`streamChatWithConfiguration()` gains a trailing ``array $metadata = []``
  parameter so streamed calls can carry budget attribution; it is additive and
  the three-argument callers stay source-compatible.
  :php:`LlmServiceManagerInterface` — a Category-1 public API — keeps its
  :php:`Generator` return contract, so this is backward compatible.
* :php:`tx_nrllm_telemetry` gains the nullable ``time_to_first_token_ms`` column
  and :php:`TelemetryRecord` a matching trailing nullable field.
* :ref:`adr-009` and :ref:`adr-026` updated: streaming is no longer a documented
  bypass.

.. _adr-062-alternatives:

Alternatives considered
=======================

* **Route the generator through the existing pipeline.** Rejected: generator
  laziness makes every middleware fire against a not-yet-started stream
  (see Context). Budget, usage, and telemetry would all be wrong.
* **Change the provider yield type to carry a final usage object**
  (``Generator<int, string|UsageStatistics, …>``). Rejected: the ``string``
  yield type is part of the public capability contract; every consumer would
  have to defend against a non-string chunk. Real usage belongs on the
  generator *return* value or a side channel, tracked as the follow-up above.
* **Eager drain inside the manager, then hand back a plain array.** Rejected:
  it defeats the entire point of streaming (memory efficiency, real-time
  output) and would change the public :php:`Generator` return type.

.. _adr-062-references:

References
==========

* :ref:`adr-009` — Streaming Implementation (the generator mechanism this
  lifecycle wraps).
* :ref:`adr-025` — Per-User AI Budgets (the pre-flight gate).
* :ref:`adr-026` — Provider Middleware Pipeline (why streaming could not use it,
  now no longer a sanctioned bypass).
* :ref:`adr-058` — Telemetry Middleware (the row shape and attribution model
  streaming reuses).
