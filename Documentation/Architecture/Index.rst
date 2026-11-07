.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _architecture:

============
Architecture
============

nr-llm separates a use-case configuration, a model and its provider connection.
Applications normally call the configuration-bound service rather than an
adapter directly. The provider protocol maps that operation to its own API;
it is not always an OpenAI Chat Completions endpoint.

.. figure:: /Images/diagram-architecture-overview.svg
   :alt: Consumer services resolve a configuration to a model and provider,
       then apply provider-call middleware before contacting an API.
   :class: with-border

   Fixed selection names a model. Criteria selection resolves one per operation.

.. _architecture-three-tier:
.. _architecture-benefits:

Selection and execution
=======================

1. Resolve a configuration with the caller's access checks.
2. In fixed mode, use its model. In criteria mode, select an eligible model
   for the requested operation without mutating the configuration entity.
3. Resolve that model's provider and nr-vault credential reference.
4. Compose the applicable prompt, snippets and skills for that service path.
5. Screen inputs and execute the provider pipeline. Record usage and permitted
   telemetry. Apply output policy before returning the result.

The exact call path matters: converting a configuration to options does not
retain its identity or every policy. See :ref:`integration-guide-step5`,
:ref:`routing-reference` and :ref:`api-agent-runtime`.

.. _architecture-provider-layer:

Provider connection
===================

``tx_nrllm_provider`` holds adapter type, endpoint, a vault identifier,
request limits, declared trust zone and persistence ceiling. Several provider
records may use one protocol with different accounts or endpoints.
See :ref:`configuration-provider` for actual field names and defaults.

.. _architecture-model-layer:

Model catalogue
===============

``tx_nrllm_model`` belongs to a provider. It declares the outbound model ID,
context/output limits, capabilities and pricing. Capability provenance is
metadata, not proof of successful execution. A model's presence does not
establish support for every operation. See :ref:`configuration-model`.

.. _architecture-configuration-layer:

Use-case configuration
======================

``tx_nrllm_configuration`` stores fixed/criteria selection, optional system
prompt, parameters, budgets, fallback references, prompt attachments and
access rules. There is no ``use_case_type`` field; the calling service chooses
the operation. Criteria mode does not require a fixed model relation.
See :ref:`configuration-llm`.

.. _architecture-service-layer:
.. _architecture-feature-services:

Consumer and extension contracts
================================

- :ref:`api-reference` identifies public consumer interfaces and extension
  points. ``LlmServiceManagerInterface`` coordinates stateless operations.
- Feature services adapt completion, translation, image input and embeddings.
  :ref:`api-specialized-services` covers separate translator/image/speech paths.
- :ref:`api-conversations` persists session history with explicit actor checks.
- :ref:`api-agent-runtime` owns persisted execution, queueing and human pauses.
- :ref:`api-tool-extensions` and :ref:`api-guardrails` are implementation
  contracts for extensions. A manual tool protocol does not acquire the
  managed runtime's policy or approval behavior automatically.

.. _architecture-provider-adapters:
.. _architecture-adapter-types:

Adapter protocols
=================

The :ref:`configuration-adapters` is the shared protocol inventory. Decision
adapters answer typed questions; they do not generate chat text. Text adapters
can use Responses, Messages, generateContent or compatible endpoints.
Custom provider code remains trusted server code.

.. _architecture-security:
.. _architecture-api-key-encryption:

Credentials and boundaries
==========================

nr-llm's provider records store nr-vault UUID references, not encrypted key
material owned by nr-llm. Vault authentication resolves secrets when required;
some specialized routing paths briefly inspect a key in memory. Database
references do not remove the need to secure vault storage, backups and logs.

Budgets are preflight checks, not atomic reservations. Trust zones are operator
declarations, not a proof of provider hosting. Egress gates constrain
cooperating HTTP paths, not arbitrary PHP. Content logs, conversation history,
waiting-run state and caches have distinct retention rules. See
:ref:`administration-data-retention` and :ref:`administration-governance`.

Architecture history belongs in :ref:`architecture-decision-records`.
Those records explain decisions at their dates; current API and operations
pages state the contract to use today.
