.. include:: /Includes.rst.txt

.. _adr-095:

============================================================================
ADR-095: One failure taxonomy for retry and circuit-breaker decisions
============================================================================

:Status: Accepted
:Date: 2026-07-20
:Authors: Netresearch DTT GmbH

.. _adr-095-context:

Context
=======

Three places decided "is this failure worth retrying against another provider":
``FallbackMiddleware::isRetryable()``, ``CircuitBreakerMiddleware::isTrippingFailure()``
and ``StreamingDispatcher::isRetryable()``. Each kept its own ``instanceof``
ladder, and they had drifted:

- the fallback middleware retried a connection error, a 429 and an open circuit;
- the circuit breaker tripped on a connection error and a 429, but not an open
  circuit (correctly) — and **not** on a 5xx;
- the streaming dispatcher retried a connection error and a 429 only.

None of them retried a **5xx**. A provider returning HTTP 500 repeatedly would
neither fail over to a healthy sibling nor open its circuit — the two mechanisms
built exactly for "this provider is unhealthy" both ignored the most common
signal that it is.

The specialized services had a related defect: ``mapErrorStatus()`` collapsed a
429 into the same ``ServiceUnavailableException`` as every other error, so a
rate limit was indistinguishable from an outage. ``ServiceQuotaExceededException``
existed for exactly this but was dead code, referenced only from tests.

.. _adr-095-decision:

Decision
========

**One vocabulary.** ``FailureClass`` (``connection``, ``rateLimit``, ``auth``,
``configuration``, ``clientError``, ``serverError``, ``circuitOpen``,
``unknown``) answers the two questions once: ``isRetryable()`` and
``tripsCircuit()``. ``FailureClassifier`` maps a throwable onto it — a pure,
static, directly-tested function recognising the provider exception family
(ADR-080) and the PSR-18 network contract. The three call sites delegate to it
and can no longer drift.

**A 5xx is now a provider-side fault.** ``serverError`` is retryable and
circuit-tripping, so a 500-ing provider both fails over and counts towards
opening its circuit. An ``auth``, ``clientError`` or ``configuration`` failure
is our fault, not the provider's, so neither retries nor trips. An already-open
circuit never re-trips itself.

**429 gets its own type on the specialized path.** ``mapErrorStatus()`` throws
``ServiceQuotaExceededException`` for a 429 — wiring the dead factory — and
records the upstream status under a ``statusCode`` context key that
``SpecializedServiceException::getStatusCode()`` exposes. The per-service error
paths (Whisper, TTS) and the base ``executeRequest()`` catch the base
``SpecializedServiceException`` when re-throwing, so a typed 429 is no longer
re-wrapped into a connection error one layer up.

.. _adr-095-consequences:

Consequences
============

- **Behaviour change:** a 5xx from a provider now triggers fallback and counts
  towards its circuit breaker, where before it bubbled up. This is the intended
  correction — the mechanisms exist for exactly this failure — and is covered by
  new tests in both middleware.
- **Breaking:** the specialized services throw ``ServiceQuotaExceededException``
  on HTTP 429 where they previously threw ``ServiceUnavailableException``. Both
  extend ``SpecializedServiceException``, so a catch on the base class is
  unaffected; a catch specifically on ``ServiceUnavailableException`` for rate
  limits must be widened.
- ``FailureClassifier`` deliberately does not yet classify the specialized
  exception family: those calls do not reach the retry/breaker middleware until
  the pipeline that routes them is generalised. ``getStatusCode()`` is the seam
  that will let it, and is added now so the data is recorded from this change on.
- This is the first step of unifying the specialized-service lifecycle with the
  chat middleware pipeline; the pipeline generalisation (moving the call context
  off "provider" specifics so image/speech/translation calls can run through it)
  is tracked as the following step.
