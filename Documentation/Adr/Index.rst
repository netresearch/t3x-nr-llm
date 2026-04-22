.. include:: /Includes.rst.txt

.. _adr:
.. _architecture-decision-records:

==============================
Architecture Decision Records
==============================

This section documents significant architectural decisions made during the
development of the TYPO3 LLM Extension.

.. _adr-symbol-legend:

Symbol legend
=============

Each consequence in the ADRs is marked with severity
symbols to indicate impact weight:

+--------+------------------+-------------+
| Symbol | Meaning          | Weight      |
+========+==================+=============+
| ●●     | Strong Positive  | +2 to +3    |
+--------+------------------+-------------+
| ●      | Medium Positive  | +1 to +2    |
+--------+------------------+-------------+
| ◐      | Light Positive   | +0.5 to +1  |
+--------+------------------+-------------+
| ✕      | Medium Negative  | -1 to -2    |
+--------+------------------+-------------+
| ✕✕     | Strong Negative  | -2 to -3    |
+--------+------------------+-------------+
| ◑      | Light Negative   | -0.5 to -1  |
+--------+------------------+-------------+

Net Score indicates the overall impact of the decision (sum of weights).

.. _adr-decision-records:

Decision records
================

.. _adr-foundation:

Foundation
----------

.. card-grid::
   :columns: 1
   :columns-md: 2
   :gap: 4
   :card-height: 100

   .. card:: ADR-001: Provider abstraction layer

      Unified interface for OpenAI, Claude, Gemini,
      Ollama, and more.

      .. card-footer:: :ref:`Read <adr-001>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-002: Feature services architecture

      Translation, vision, embeddings, completion as
      injectable services.

      .. card-footer:: :ref:`Read <adr-002>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-003: Typed response objects

      Immutable value objects for all LLM responses.

      .. card-footer:: :ref:`Read <adr-003>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-007: Multi-provider strategy

      Fallback chains and provider selection logic.

      .. card-footer:: :ref:`Read <adr-007>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-013: Three-level configuration

      Provider -> Model -> Configuration hierarchy.

      .. card-footer:: :ref:`Read <adr-013>`
         :button-style: btn btn-secondary stretched-link

.. _adr-integration:

TYPO3 integration
-----------------

.. card-grid::
   :columns: 1
   :columns-md: 2
   :gap: 4
   :card-height: 100

   .. card:: ADR-004: PSR-14 event system

      Extension points via TYPO3 events.

      .. card-footer:: :ref:`Read <adr-004>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-005: Caching framework

      Instance-default backend, ``nrllm`` cache group.

      .. card-footer:: :ref:`Read <adr-005>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-012: API key encryption

      Superseded — now via nr-vault envelope encryption.

      .. card-footer:: :ref:`Read <adr-012>`
         :button-style: btn btn-secondary stretched-link

.. _adr-api-design:

API design
----------

.. card-grid::
   :columns: 1
   :columns-md: 2
   :gap: 4
   :card-height: 100

   .. card:: ADR-006: Option objects vs arrays

      Typed option objects for API calls.

      .. card-footer:: :ref:`Read <adr-006>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-008: Error handling strategy

      Exception hierarchy and retry logic.

      .. card-footer:: :ref:`Read <adr-008>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-009: Streaming implementation

      Chunked transfer for real-time output.

      .. card-footer:: :ref:`Read <adr-009>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-010: Tool/function calling

      Provider-agnostic tool call abstraction.

      .. card-footer:: :ref:`Read <adr-010>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-011: Object-only options API

      Removed array support, typed objects only.

      .. card-footer:: :ref:`Read <adr-011>`
         :button-style: btn btn-secondary stretched-link

.. _adr-modern:

Modern architecture (v0.4+)
----------------------------

.. card-grid::
   :columns: 1
   :columns-md: 2
   :gap: 4
   :card-height: 100

   .. card:: ADR-014: AI-powered wizard system

      Natural language -> structured configuration
      generation with fallback defaults.

      .. card-footer:: :ref:`Read <adr-014>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-015: Type-safe domain models

      PHP 8.1+ enums, DTOs, and value objects.

      .. card-footer:: :ref:`Read <adr-015>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-016: Thinking block extraction

      Reasoning blocks from Claude, DeepSeek, Qwen.

      .. card-footer:: :ref:`Read <adr-016>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-017: SafeCastTrait

      PHPStan level 10 compliance for mixed input.

      .. card-footer:: :ref:`Read <adr-017>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-018: Model discovery

      Multi-provider model listing with fallback
      catalogs.

      .. card-footer:: :ref:`Read <adr-018>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-019: Internationalization

      XLIFF + locale-aware features with {lang}
      placeholders.

      .. card-footer:: :ref:`Read <adr-019>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-020: Output format rendering

      Client-side plain/markdown/HTML toggle.

      .. card-footer:: :ref:`Read <adr-020>`
         :button-style: btn btn-secondary stretched-link

.. toctree::
   :hidden:

   Adr001ProviderAbstractionLayer
   Adr002FeatureServicesArchitecture
   Adr003TypedResponseObjects
   Adr004Psr14EventSystem
   Adr005Typo3CachingFrameworkIntegration
   Adr006OptionObjectsVsArrays
   Adr007MultiProviderStrategy
   Adr008ErrorHandlingStrategy
   Adr009StreamingImplementation
   Adr010ToolFunctionCallingDesign
   Adr011ObjectOnlyOptionsApi
   Adr012ApiKeyEncryption
   Adr013ThreeLevelConfigurationArchitecture
   Adr014AiPoweredWizardSystem
   Adr015TypeSafeDomainModels
   Adr016ThinkingReasoningBlockExtraction
   Adr017SafeCastTrait
   Adr018MultiProviderModelDiscovery
   Adr019InternationalizationStrategy
   Adr020BackendOutputFormatRendering
   Adr021ProviderFallbackChain
   Adr022AttributeBasedProviderRegistration
