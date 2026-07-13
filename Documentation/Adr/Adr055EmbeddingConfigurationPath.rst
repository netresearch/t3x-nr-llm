.. include:: /Includes.rst.txt

.. _adr-055:

============================================================================
ADR-055: Embeddings join the configuration path; dimensions metadata
============================================================================

:Status: Accepted
:Date: 2026-07-13
:Authors: Netresearch DTT GmbH

.. _adr-055-context:

Context
=======

The three-tier model (Provider → Model → Configuration,
:ref:`ADR-001 <adr-001>`) reaches every chat-shaped capability:
``completeWithConfiguration()``, ``chatWithConfiguration()``,
``streamChatWithConfiguration()`` and
``chatWithToolsForConfiguration()`` all resolve the adapter from a
DB-backed ``LlmConfiguration`` (vault key + model + pricing) and run
through the middleware pipeline, so budgets are enforced and cost is
attributed per configuration.

Embeddings did not. ``LlmServiceManager::embed()`` only accepted
``EmbeddingOptions`` with raw ``provider``/``model`` strings, resolved
against ExtensionConfiguration and a model-less transient
configuration. An embedding consumer that persists vectors (a search
index, semantic auto-linking — see the scope boundary in
:ref:`ADR-050 <adr-050>`) therefore had to duplicate provider, model
and dimensionality into its *own* extension configuration, bypassing
per-configuration budgets and cost attribution entirely.

The dimensionality gap made this worse: no record anywhere stated how
many dimensions a model's vectors have. A consumer validating a
persisted vector index against the configured model had to run a live
"calibration probe" — embed a throwaway string and count the floats —
which costs a provider call and fails when the provider is unreachable.

.. _adr-055-decision:

Decision
========

**Embeddings join the configuration path.**
``LlmServiceManager::embedForConfiguration()`` mirrors
``chatWithToolsForConfiguration()``: it resolves the adapter via
``getAdapterFromConfiguration()``, runs through the middleware
pipeline with ``ProviderOperation::Embedding`` and the budget
metadata from the options, and guards the ``embeddings`` feature the
same way ``embed()`` does (``UnsupportedFeatureException`` when the
provider lacks it). Per-call ``EmbeddingOptions`` take precedence over
the configuration's stored defaults — an options ``model`` overrides
the configuration's model id. Caching mirrors ``embed()``: a positive
``cache_ttl`` places a cache key on the call context (keyed on the
configuration identifier plus the *effective* model), so two
configurations pointing at different models never share cache entries.

The high-level feature service follows:
``EmbeddingService::embedForConfiguration()`` and
``embedBatchForConfiguration()`` delegate to the manager and populate
``beUserUid`` via the shared auto-populate wiring, exactly like the
existing ``embed()``/``embedBatch()`` paths.

**Model records carry dimensions metadata.** ``tx_nrllm_model`` gains
a ``dimensions`` column (integer, ``0 = unknown``, declared like
``context_length``), surfaced in the TCA next to the other model
limits and on the ``Model`` entity as
``getDimensions()``/``setDimensions()``. It is descriptive metadata:
nothing in nr_llm enforces it at call time.

.. _adr-055-consequences:

Consequences
============

- Embedding consumers select a backend-managed configuration instead
  of duplicating provider + model + dimensions into their own
  extension configuration; per-configuration budgets and cost
  attribution apply to embeddings like to every chat-shaped
  capability.
- A consumer can validate a persisted vector index against the
  configured model by comparing its stored dimensionality with
  ``getLlmModel()->getDimensions()`` — no live calibration probe, no
  provider round-trip. A value of ``0`` means "unknown"; consumers
  fall back to their previous behaviour then.
- ``LlmServiceManagerInterface`` and ``EmbeddingServiceInterface``
  gained methods — implementers outside this repo must add them.
- nr_llm's embedding capability remains stateless
  (:ref:`ADR-050 <adr-050>`): the configuration path changes *how the
  call is resolved and accounted*, not what is persisted. Vector
  stores stay out of scope.
