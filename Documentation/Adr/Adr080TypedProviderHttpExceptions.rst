.. include:: /Includes.rst.txt

.. _adr-080:

============================================================================
ADR-080: Typed provider HTTP exceptions (authentication 401, rate-limit 429)
============================================================================

:Status: Accepted
:Date: 2026-07-18
:Authors: Netresearch DTT GmbH

.. _adr-080-context:

Context
=======

A provider's 4xx responses were all flattened into one class. The standard
adapter path (`AbstractProvider::handleResponse()` and
`assertStreamingResponseOk()`) mapped every 4xx — including **401**
(authentication) and **429** (rate limit) — to a generic
`ProviderResponseException` carrying the numeric `httpStatus`. A consumer that
wanted to react differently to "the API key is wrong" versus "we are being
throttled" (a controller turning either into user-facing copy, a retry policy)
had to inspect `getCode()`/`$httpStatus` or, worse, re-parse the message string
— the exact fragile string-matching :ref:`ADR-053 <adr-053>`'s marker interface
was meant to end.

The mapping was also **inconsistent** across adapters:
`OpenRouterProvider::handleOpenRouterError()` routed 401 to
`ProviderConfigurationException` and 429 to `ProviderConnectionException`, so the
same HTTP status produced a different class on OpenRouter than on the other six
providers.

.. _adr-080-decision:

Decision
========

**Introduce two status-specific subclasses of `ProviderResponseException`:**
`ProviderAuthenticationException` (401) and `ProviderRateLimitException` (429).
They are empty `final` subclasses — they inherit the constructor and the typed
`httpStatus` / `responseBody` / `endpoint` fields unchanged. `ProviderResponseException`
drops its own `final` so it can be extended; it stays otherwise identical.

A private `AbstractProvider::clientErrorException()` factory picks the class from
the status (401 → authentication, 429 → rate-limit, every other 4xx → the base
`ProviderResponseException`) and is used by both the buffered and the streaming
4xx branches, so the two paths never drift. `OpenRouterProvider` is realigned to
the same 401/429 classes (402 stays `ProviderConfigurationException`, 503 stays
`ProviderConnectionException`).

**Backward compatibility is preserved by inheritance:**

- ``catch (ProviderResponseException)`` still catches a 401 or 429 — the new
  classes *are* `ProviderResponseException`.
- ``catch (ProviderException)`` and ``catch (\Netresearch\NrLlm\Exception\NrLlmExceptionInterface)``
  are unaffected.
- ``getCode()`` still returns the HTTP status, so the retry/fallback
  (`FallbackMiddleware`) and circuit-breaker (`CircuitBreakerMiddleware`) checks
  that key off ``getCode() === 429`` keep firing for the rate-limit class.

.. _adr-080-consequences:

Consequences
============

- Consumers branch on the exception class (``catch (ProviderRateLimitException)``)
  instead of the status code or the message text.
- **Behaviour change on OpenRouter only:** a 401 is now
  `ProviderAuthenticationException` (was `ProviderConfigurationException`) and a
  429 is now `ProviderRateLimitException` (was `ProviderConnectionException`).
  Both remain `ProviderResponseException`/`ProviderException`, and 429 keeps
  ``getCode() === 429``, so retry/circuit-breaker semantics are unchanged; only a
  consumer catching those two *specific* OpenRouter classes sees the more correct
  type. The realignment is a separate commit in the introducing PR.
- ``ProviderResponseException`` is no longer `final`; the two shipped subclasses
  are the only intended extensions.
- The exceptions carry no `Retry-After` value — none is parsed today; adding it
  is a separate change.
