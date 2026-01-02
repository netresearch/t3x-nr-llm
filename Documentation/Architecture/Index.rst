.. include:: /Includes.rst.txt

.. _architecture:

============
Architecture
============

This section describes the architectural design of the TYPO3 LLM extension.

.. contents::
   :local:
   :depth: 2

.. _architecture-three-tier:

Three-tier configuration architecture
=====================================

The extension uses a three-level hierarchical architecture separating concerns:

::

   ┌─────────────────────────────────────────────────────────────────────────┐
   │ CONFIGURATION (Use-Case Specific)                                        │
   │ "blog-summarizer", "product-description", "support-translator"          │
   │                                                                          │
   │ Fields: system_prompt, temperature, max_tokens, use_case_type           │
   │ References: model_uid → Model                                            │
   └──────────────────────────────────┬──────────────────────────────────────┘
                                      │ N:1
   ┌──────────────────────────────────▼──────────────────────────────────────┐
   │ MODEL (Available Models)                                                 │
   │ "gpt-5", "claude-sonnet-4-5", "llama-70b", "text-embedding-3-large"     │
   │                                                                          │
   │ Fields: model_id, context_length, capabilities, pricing                 │
   │ References: provider_uid → Provider                                      │
   └──────────────────────────────────┬──────────────────────────────────────┘
                                      │ N:1
   ┌──────────────────────────────────▼──────────────────────────────────────┐
   │ PROVIDER (API Connections)                                               │
   │ "openai-prod", "openai-dev", "local-ollama", "azure-openai-eu"          │
   │                                                                          │
   │ Fields: endpoint_url, api_key (encrypted), adapter_type, timeout        │
   └─────────────────────────────────────────────────────────────────────────┘

.. _architecture-benefits:

Benefits
--------

- **Multiple API keys per provider type**: Separate production and development accounts.
- **Custom endpoints**: Azure OpenAI, Ollama, vLLM, local models.
- **Reusable model definitions**: Centralized capabilities and pricing.
- **Clear separation of concerns**: Connection vs capability vs use-case.

.. _architecture-provider-layer:

Provider layer
--------------

Represents a specific API connection with credentials.

Database table: :sql:`tx_nrllm_provider`

.. csv-table:: Provider fields
   :header: "Field", "Type", "Description"
   :widths: 25, 20, 55

   "identifier", "string", "Unique slug (e.g., ``openai-prod``, ``ollama-local``)"
   "name", "string", "Display name (e.g., ``OpenAI Production``)"
   "adapter_type", "string", "Protocol: ``openai``, ``anthropic``, ``gemini``, ``ollama``, etc."
   "endpoint_url", "string", "Custom endpoint (empty = default)"
   "api_key", "string", "Encrypted API key (using sodium_crypto_secretbox)"
   "organization_id", "string", "Optional organization ID (OpenAI)"
   "timeout", "int", "Request timeout in seconds"
   "max_retries", "int", "Retry count on failure"
   "options", "JSON", "Additional adapter-specific options"

**Key design points:**

- One provider = one API key = one billing relationship.
- Same adapter type can have multiple providers (prod/dev accounts).
- Adapter type determines the protocol/client class used.
- API keys are encrypted at rest using sodium.

.. _architecture-model-layer:

Model layer
-----------

Represents a specific model available through a provider.

Database table: :sql:`tx_nrllm_model`

.. csv-table:: Model fields
   :header: "Field", "Type", "Description"
   :widths: 25, 20, 55

   "identifier", "string", "Unique slug (e.g., ``gpt-5``, ``claude-sonnet``)"
   "name", "string", "Display name (e.g., ``GPT-5 (128K)``)"
   "provider_uid", "int", "Foreign key to Provider"
   "model_id", "string", "API model identifier (e.g., ``gpt-5``, ``claude-opus-4-5-20251101``)"
   "context_length", "int", "Token limit (e.g., 128000)"
   "max_output_tokens", "int", "Output limit (e.g., 16384)"
   "capabilities", "CSV", "Supported features: ``chat,vision,streaming,tools``"
   "cost_input", "int", "Cents per 1M input tokens"
   "cost_output", "int", "Cents per 1M output tokens"
   "is_default", "bool", "Default model for this provider"

**Key design points:**

- Models belong to exactly one provider.
- Capabilities define what the model can do.
- Pricing stored as integers (cents/1M tokens) to avoid float issues.
- Same logical model can exist multiple times (different providers).

.. _architecture-configuration-layer:

Configuration layer
-------------------

Represents a specific use case with model and prompt settings.

Database table: :sql:`tx_nrllm_configuration`

.. csv-table:: Configuration fields
   :header: "Field", "Type", "Description"
   :widths: 25, 20, 55

   "identifier", "string", "Unique slug (e.g., ``blog-summarizer``)"
   "name", "string", "Display name (e.g., ``Blog Post Summarizer``)"
   "model_uid", "int", "Foreign key to Model"
   "system_prompt", "text", "System message for the model"
   "temperature", "float", "Creativity: 0.0 - 2.0"
   "max_tokens", "int", "Response length limit"
   "top_p", "float", "Nucleus sampling"
   "presence_penalty", "float", "Topic diversity"
   "frequency_penalty", "float", "Word repetition penalty"
   "use_case_type", "string", "``chat``, ``completion``, ``embedding``, ``translation``"

**Key design points:**

- Configurations reference models, not providers directly.
- All LLM parameters are tunable per use case.
- Same model can be used by multiple configurations.

.. _architecture-service-layer:

Service layer
=============

The extension follows a layered service architecture:

::

   ┌─────────────────────────────────────────┐
   │         Your Application Code           │
   └────────────────┬────────────────────────┘
                    │
   ┌────────────────▼────────────────────────┐
   │         Feature Services                │
   │  (Completion, Embedding, Vision, etc.)  │
   └────────────────┬────────────────────────┘
                    │
   ┌────────────────▼────────────────────────┐
   │         LlmServiceManager               │
   │    (Provider selection & routing)       │
   └────────────────┬────────────────────────┘
                    │
   ┌────────────────▼────────────────────────┐
   │       ProviderAdapterRegistry           │
   │    (Maps adapters to database providers)│
   └────────────────┬────────────────────────┘
                    │
   ┌────────────────▼────────────────────────┐
   │       Provider Adapters                 │
   │  (OpenAI, Claude, Gemini, Ollama, etc.) │
   └─────────────────────────────────────────┘

.. _architecture-feature-services:

Feature services
----------------

High-level services for common AI tasks:

- :php:`CompletionService`: Text generation with format control (JSON, Markdown).
- :php:`EmbeddingService`: Text-to-vector conversion with caching.
- :php:`VisionService`: Image analysis for alt-text, titles, descriptions.
- :php:`TranslationService`: Language translation with glossaries.

.. _architecture-provider-adapters:

Provider adapters
-----------------

The extension includes adapters for multiple LLM providers:

- **OpenAI** (:php:`OpenAiProvider`): GPT-5.x series, o-series reasoning models.
- **Anthropic** (:php:`ClaudeProvider`): Claude Opus 4.5, Claude Sonnet 4.5, Claude Haiku 4.5.
- **Google** (:php:`GeminiProvider`): Gemini 3 Pro, Gemini 3 Flash, Gemini 2.5 series.
- **Ollama** (:php:`OllamaProvider`): Local model deployment.
- **OpenRouter** (:php:`OpenRouterProvider`): Multi-model routing.
- **Mistral** (:php:`MistralProvider`): Mistral models.
- **Groq** (:php:`GroqProvider`): Fast inference.

.. _architecture-security:

Security
========

.. _architecture-api-key-encryption:

API key encryption
------------------

API keys are encrypted at rest in the database using :php:`sodium_crypto_secretbox` (XSalsa20-Poly1305).

- Keys are derived from TYPO3's :php:`encryptionKey` with domain separation.
- Nonce is randomly generated per encryption (24 bytes).
- Encrypted values are prefixed with ``enc:`` for detection.
- Legacy plaintext values are automatically encrypted on first access.

For details, see :ref:`adr-012`.

.. _architecture-adapter-types:

Supported adapter types
-----------------------

.. csv-table::
   :header: "Adapter Type", "PHP Class", "Default Endpoint"
   :widths: 20, 40, 40

   "openai", ":php:`OpenAiProvider`", "https://api.openai.com/v1"
   "anthropic", ":php:`ClaudeProvider`", "https://api.anthropic.com/v1"
   "gemini", ":php:`GeminiProvider`", "https://generativelanguage.googleapis.com/v1beta"
   "ollama", ":php:`OllamaProvider`", "http://localhost:11434"
   "openrouter", ":php:`OpenRouterProvider`", "https://openrouter.ai/api/v1"
   "mistral", ":php:`MistralProvider`", "https://api.mistral.ai/v1"
   "groq", ":php:`GroqProvider`", "https://api.groq.com/openai/v1"
   "azure_openai", ":php:`OpenAiProvider`", "(custom Azure endpoint)"
   "custom", ":php:`OpenAiProvider`", "(custom endpoint)"
