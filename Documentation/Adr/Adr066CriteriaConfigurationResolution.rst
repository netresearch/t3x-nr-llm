.. include:: /Includes.rst.txt

.. _adr-066:

==================================================================
ADR-066: Criteria-mode configurations resolve in the service layer
==================================================================

:Status: Accepted
:Date: 2026-07-15
:Authors: Netresearch DTT GmbH

.. _adr-066-context:

Context
=======

:ref:`ADR-055 <adr-055>` and :ref:`ADR-056 <adr-056>` introduced
``LlmConfiguration`` records with two selection modes: ``fixed`` (a
direct ``model_uid`` relation) and ``criteria`` (a stored
``ModelSelectionCriteria`` JSON; ``model_uid = 0``, the concrete model
chosen at call time). ``ModelSelectionService::resolveModel()`` was the
resolver — but it had **no production caller**. Every
``*ForConfiguration()`` entry point (``embedForConfiguration``,
``chatWithConfiguration``, ``chatWithToolsForConfiguration``,
``completeWithConfiguration``, ``streamChatWithConfiguration``) obtained
its adapter through ``getAdapterFromConfiguration()``, which read
``$configuration->getLlmModel()`` — a plain getter — directly. For a
criteria-mode record that relation is ``null``, so the call threw
``ProviderException: Configuration "…" has no model assigned``.

This surfaced live: nr_ai_search's ``embeddingConfiguration`` /
``chatConfiguration`` presets are criteria-mode, so the entire retrieval
path failed the moment it reached the provider adapter.

.. _adr-066-decision:

Decision
========

``getAdapterFromConfiguration()`` — the single adapter choke point for
every ``*ForConfiguration()`` path — resolves the model through
``ModelSelectionService::resolveModel($configuration)``, which returns
the directly configured model unchanged for ``fixed`` mode and selects
from the stored criteria for ``criteria`` mode. It still throws the same
``ProviderException`` (code ``1735300100``) when resolution yields no
model.

The resolved model is **not** written back onto the configuration. The
configuration is a repository-managed Extbase entity; calling
``setLlmModel()`` would mark it dirty and Extbase would persist
``model_uid`` at request end, silently converting a criteria-mode record
into a fixed-mode one. Per-model cost analytics for criteria configs
(``UsageMiddleware`` reads ``getLlmModel()`` directly) therefore remain a
separate, non-destructive follow-up.

.. _adr-066-consequences:

Consequences
============

- Criteria-mode configurations work across embed / chat / tools /
  complete / stream without the caller pre-resolving a model.
- ``ModelSelectionServiceInterface`` is a trailing, nullable constructor
  dependency of ``LlmServiceManager`` (autowired); when absent the method
  falls back to the raw getter, so existing 5-argument constructions keep
  compiling.
- Cost analytics for criteria-mode configs fall back to the
  provider-reported model id (no per-model DB pricing) until the
  follow-up threads the resolved model through the call metadata.

See PR #372.
