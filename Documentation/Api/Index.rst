.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _api-reference:

=============
API reference
=============

Reference for supported integration paths, public services and value objects.
See :ref:`api-stability` for the distinction between consumer interfaces and
extension points.

This ``main`` manual describes the development branch, including changes
not yet released after 0.43.20. Select your installed release in the version
menu. See :ref:`api-availability` for the first release of selected methods.

Choose a starting point:

- A single configured exchange: :ref:`integration-guide-step5`.
- Stored lifecycle, approvals or background work: :ref:`api-agent-runtime`.
- Session memory: :ref:`api-conversations`.
- Executable tools and availability: :ref:`api-tool-extensions`.
- Input/output policy extensions: :ref:`api-guardrails`.
- Translation, image, speech and document paths: :ref:`api-specialized-services`.

.. toctree::
   :maxdepth: 2

   Stability
   Availability
   Deprecation
   SupportMatrix
   LlmServiceManager
   AgentRuntime
   Conversations
   CompletionService
   DecisionService
   EmbeddingService
   VisionService
   DocumentAnalysisService
   TranslationService
   BudgetStatus
   ExtensionUsage
   ToolCallingService
   ToolExtensions
   Guardrails
   Middleware
   SpecializedServices
   ToolInvocationPolicy
   McpDelegation
   KeywordSearch
   ReciprocalRankFusion
   RetrievalProvenance
   Reranker
   ResponseObjects
   OptionClasses
   ProviderInterface
   Events
   Exceptions
