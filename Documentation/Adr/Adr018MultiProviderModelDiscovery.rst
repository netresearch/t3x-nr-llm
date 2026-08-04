.. include:: /Includes.rst.txt

.. _adr-018:

==========================================
ADR-018: Multi-Provider Model Discovery
==========================================

:Status: Accepted
:Date: 2025-12
:Authors: Netresearch DTT GmbH

.. _adr-018-context:

Context
=======

Different LLM providers expose different model listing APIs. OpenAI offers
``GET /v1/models``, Ollama uses ``GET /api/tags``, Anthropic has no public
listing endpoint, and Gemini uses a different URL structure entirely. The
setup wizard needs a unified way to discover available models regardless of
provider.

.. _adr-018-problem-statement:

Problem statement
-----------------

1. **Heterogeneous APIs:** No standard protocol for model listing.
2. **Authentication variance:** Bearer tokens, API key headers, URL parameters.
3. **Response format divergence:** Each provider returns
   different JSON structures.
4. **Offline providers:** Some providers (Anthropic,
   Azure) lack public model list APIs.
5. **Endpoint normalization:** Users enter URLs
   with/without trailing slashes, versions, schemes.

.. _adr-018-decision:

Decision
========

Abstract model discovery behind
:php:`ModelDiscoveryInterface` with two operations:

.. code-block:: php
   :caption: ModelDiscoveryInterface contract

   interface ModelDiscoveryInterface
   {
       /** @return array{success: bool, message: string} */
       public function testConnection(DetectedProvider $provider, string $apiKey): array;

       /** @return array<DiscoveredModel> */
       public function discover(DetectedProvider $provider, string $apiKey): array;
   }

The :php:`ModelDiscovery` implementation routes per adapter type to one
discoverer class per provider (extracted from the original single-class
implementation in 2026-08; behaviour unchanged):

.. code-block:: php
   :caption: Provider-specific dispatch

   public function discover(DetectedProvider $provider, string $apiKey): array
   {
       $discoverer = $this->discoverers[$provider->adapterType] ?? null;
       if (!$discoverer instanceof AbstractModelDiscoverer) {
           return $this->getDefaultModels($provider->adapterType);
       }

       $result = $discoverer->discover($endpoint, $apiKey);
       $this->lastDiscoveryUsedFallback = $result->usedFallback;

       return $result->models;
   }

Each provider's listing, filtering, enrichment and fallback catalog live in
:file:`Classes/Service/SetupWizard/Discovery/` as a
:php:`*ModelDiscoverer extends AbstractModelDiscoverer`; whether a result is
live API data or a canned catalog travels on the returned
:php:`DiscoveryResult` rather than on shared service state.

Key design elements:

- **API-driven discovery** for providers with listing endpoints (OpenAI, Ollama,
  Mistral, Groq, OpenRouter, Gemini).
- **Static fallback catalogs** for providers without
  listing endpoints (Anthropic, Azure, unknown).
  Maintained with current model information.
- **Provider detection** via :php:`ProviderDetector`
  using URL pattern matching with confidence scores
  (1.0 for exact match, 0.3 for unknown).
- **Normalized DTOs:** :php:`DiscoveredModel` unifies
  model metadata across providers (modelId, name,
  capabilities, contextLength, costs, recommended
  flag).
- **Authentication dispatch:** Per-provider header
  format (``Authorization: Bearer``,
  ``x-api-key``, ``x-goog-api-key``, none for
  Ollama).

.. _adr-018-detection:

Provider detection patterns
---------------------------

:php:`ProviderDetector` matches endpoint URLs against known patterns:

.. csv-table::
   :header: "Pattern", "Adapter Type", "Confidence"
   :widths: 40, 30, 15

   "api.openai.com", "openai", "1.0"
   "api.anthropic.com", "anthropic", "1.0"
   "generativelanguage.googleapis.com", "gemini", "1.0"
   "\\*.openai.azure.com", "azure_openai", "1.0"
   "localhost:11434", "ollama", "1.0"
   "\\*/v1/chat/completions (path match)", "openai", "0.6"
   "Unknown endpoint", "openai (fallback)", "0.3"

.. _adr-018-consequences:

Consequences
============
**Positive:**

- ●● Unified model discovery across seven provider types.
- ● Static catalogs ensure discovery works even without API access.
- ● Confidence scoring lets the UI warn about uncertain detections.
- ◐ PSR HTTP interfaces allow testing with mock HTTP clients.
- ◐ Endpoint normalization handles common user input variations.

**Negative:**

- ◑ Static catalogs require periodic updates as providers release new models.
- ◑ API-based discovery may expose all models, including deprecated ones.
- ✕ Rate limiting on model listing endpoints not handled.

**Net Score:** +5.0 (Strong positive)

.. _adr-018-files-changed:

Files changed
=============

**Added:**

- :file:`Classes/Service/SetupWizard/ModelDiscoveryInterface.php`
- :file:`Classes/Service/SetupWizard/ModelDiscovery.php`
  (since 2026-08 a facade over :file:`Classes/Service/SetupWizard/Discovery/`,
  one discoverer class per provider)
- :file:`Classes/Service/SetupWizard/ProviderDetector.php`
- :file:`Classes/Service/SetupWizard/DTO/DetectedProvider.php`
- :file:`Classes/Service/SetupWizard/DTO/DiscoveredModel.php`
