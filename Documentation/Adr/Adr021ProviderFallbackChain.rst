.. include:: /Includes.rst.txt

.. _adr-021:

==========================================
ADR-021: Provider Fallback Chain
==========================================

:Status: Accepted
:Date: 2026-04
:Authors: Netresearch DTT GmbH

.. _adr-021-context:

Context
=======

A single misbehaving provider (OpenAI rate-limit, Claude outage, local Ollama
daemon not running) previously bubbled up as an uncaught exception to every
consuming extension. Operators had no built-in way to degrade gracefully to a
second or third provider.

.. _adr-021-decision:

Decision
========

A configuration's ``fallback_chain`` column stores an ordered JSON list of
other :php:`LlmConfiguration` identifiers. On retryable failures during
:php:`LlmServiceManager::chatWithConfiguration()` or
:php:`completeWithConfiguration()`, a :php:`FallbackChainExecutor` walks the
chain and returns the first successful response — or throws
:php:`FallbackChainExhaustedException` carrying every attempt error.

"Retryable" is narrowly defined: the request *might* succeed against a
different provider.

* :php:`ProviderConnectionException` — network / timeout / HTTP 5xx /
  retries exhausted
* :php:`ProviderResponseException` with HTTP code 429 — this provider is
  rate-limiting us, another might not be

Everything else (authentication, bad request, unsupported feature,
misconfiguration) bubbles up unchanged — a different provider won't help.

.. _adr-021-scope:

Scope limitations (v1)
======================

* **Streaming is not wrapped.** Once the first chunk has been yielded, we
  cannot swap providers mid-stream. :php:`streamChatWithConfiguration()`
  calls the primary adapter directly.

* **Shallow only.** A fallback configuration's own chain is ignored. This
  prevents both cycles (``a -> b -> a``) and exponential blow-up of attempts.

* **Inactive fallbacks are skipped**, not treated as failures.

* **Missing identifiers are skipped** with a warning log, not treated as
  failures. Misconfiguration should not mask outages.

.. _adr-021-storage:

Storage
=======

The chain is stored as a single JSON column to keep the schema change
minimal and avoid an additional relation table. The
:php:`Netresearch\\NrLlm\\Domain\\DTO\\FallbackChain` value object handles
serialization, deduplication, and order preservation.

TCA presents the field as a JSON textarea for v1. A richer UI (sortable
multi-select of available configurations) can replace the textarea without
schema or API change.

.. _adr-021-alternatives:

Alternatives considered
=======================

* **Fat middleware pipeline** (as in b13/aim). Rejected for this release —
  too invasive for a single-feature change. The middleware pattern remains
  on the roadmap as a v1.0 refactor; a fallback chain is the most valuable
  pipeline step users ask for and works fine as a standalone service.

* **Recursive chain resolution** (fallback's fallback). Rejected as the
  cost (cycle detection, attempt amplification) outweighs the benefit;
  operators can always append to the primary's chain directly.

* **Per-link retry policy** (per fallback: max retries, backoff, which
  exceptions). Rejected as over-engineered for the initial release.
