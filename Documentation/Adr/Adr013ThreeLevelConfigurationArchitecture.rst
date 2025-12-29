.. include:: /Includes.rst.txt

.. _adr-013:

===========================================================================
ADR-013: Three-level configuration architecture (Provider-Model-Configuration)
===========================================================================

:Status: Accepted
:Date: 2024-12-27
:Authors: Netresearch DTT GmbH

Context
=======

The nr_llm extension needs to manage LLM configurations for various use cases (chat, translation, embeddings, etc.). Initially, configurations were stored in a single table mixing connection settings, model parameters, and use-case-specific prompts.

Problem statement
-----------------

A single-table approach creates several issues:

1. **API Key Duplication:** Same API key repeated across multiple configurations
2. **Model Redundancy:** Model capabilities and pricing duplicated
3. **Inflexible Connections:** Cannot have multiple API keys for same provider (prod/dev)
4. **Mixed Concerns:** Connection details, model specs, and prompts intermingled
5. **Maintenance Burden:** Changing an API key requires updating multiple records

Real-world scenarios not supported
----------------------------------

.. csv-table::
   :header: "Scenario", "Single-Table Problem"
   :widths: 40, 60

   "Separate prod/dev OpenAI accounts", "Must duplicate all configurations"
   "Self-hosted Ollama + cloud fallback", "Cannot model multiple endpoints"
   "Cost tracking per API key", "No clear key-to-usage mapping"
   "Model catalog with shared pricing", "Model specs repeated everywhere"
   "Team-specific API keys", "No multi-tenancy support"

Decision
========

Implement a **three-level hierarchical architecture** separating concerns:

::

   ┌─────────────────────────────────────────────────────────────────────────┐
   │ CONFIGURATION (Use-Case Specific)                                        │
   │ "blog-summarizer", "product-description", "support-translator"          │
   │                                                                          │
   │ Fields: system_prompt, temperature, max_tokens, top_p, use_case_type    │
   │ References: model_uid → Model                                            │
   └──────────────────────────────────┬──────────────────────────────────────┘
                                      │ N:1
   ┌──────────────────────────────────▼──────────────────────────────────────┐
   │ MODEL (Available Models)                                                 │
   │ "gpt-4o", "claude-3-sonnet", "llama-3.1-70b", "text-embedding-3-large"  │
   │                                                                          │
   │ Fields: model_id, context_length, capabilities, cost_input, cost_output │
   │ References: provider_uid → Provider                                      │
   └──────────────────────────────────┬──────────────────────────────────────┘
                                      │ N:1
   ┌──────────────────────────────────▼──────────────────────────────────────┐
   │ PROVIDER (API Connections)                                               │
   │ "openai-prod", "openai-dev", "local-ollama", "azure-openai-eu"          │
   │                                                                          │
   │ Fields: endpoint_url, api_key (encrypted), adapter_type, timeout        │
   └─────────────────────────────────────────────────────────────────────────┘

Level 1: Provider (Connection Layer)
------------------------------------

Represents a specific API connection with credentials.

::

   tx_nrllm_provider
   ├── identifier        -- Unique slug: "openai-prod", "ollama-local"
   ├── name              -- Display name: "OpenAI Production"
   ├── adapter_type      -- Protocol: openai, anthropic, gemini, ollama...
   ├── endpoint_url      -- Custom endpoint (empty = default)
   ├── api_key           -- Encrypted API key
   ├── organization_id   -- Optional org ID (OpenAI)
   ├── timeout           -- Request timeout in seconds
   ├── max_retries       -- Retry count on failure
   └── options           -- JSON: additional adapter options

**Key Design Points:**

- One provider = one API key = one billing relationship
- Same adapter type can have multiple providers (prod/dev accounts)
- Adapter type determines the protocol/client class used

Level 2: Model (Capability Layer)
---------------------------------

Represents a specific model available through a provider.

::

   tx_nrllm_model
   ├── identifier        -- Unique slug: "gpt-4o", "claude-sonnet"
   ├── name              -- Display name: "GPT-4o (128K)"
   ├── provider_uid      -- FK → Provider
   ├── model_id          -- API model identifier: "gpt-4o-2024-08-06"
   ├── context_length    -- Token limit: 128000
   ├── max_output_tokens -- Output limit: 16384
   ├── capabilities      -- CSV: chat,vision,streaming,tools
   ├── cost_input        -- Cents per 1M input tokens
   ├── cost_output       -- Cents per 1M output tokens
   └── is_default        -- Default model for this provider

**Key Design Points:**

- Models belong to exactly one provider
- Capabilities define what the model can do
- Pricing stored as integers (cents/1M tokens) to avoid float issues
- Same logical model can exist multiple times (different providers)

Level 3: Configuration (Use-Case Layer)
---------------------------------------

Represents a specific use case with model and prompt settings.

::

   tx_nrllm_configuration
   ├── identifier        -- Unique slug: "blog-summarizer"
   ├── name              -- Display name: "Blog Post Summarizer"
   ├── model_uid         -- FK → Model
   ├── system_prompt     -- System message for the model
   ├── temperature       -- Creativity: 0.0 - 2.0
   ├── max_tokens        -- Response length limit
   ├── top_p             -- Nucleus sampling
   ├── presence_penalty  -- Topic diversity
   ├── frequency_penalty -- Word repetition penalty
   └── use_case_type     -- chat, completion, embedding, translation

**Key Design Points:**

- Configurations reference models, not providers directly
- All LLM parameters are tunable per use case
- Same model can be used by multiple configurations

Relationships
-------------

::

   ┌────────────┐       ┌─────────┐       ┌───────────────┐
   │ Provider   │ 1───N │ Model   │ 1───N │ Configuration │
   └────────────┘       └─────────┘       └───────────────┘
        │                    │                    │
        │ api_key            │ model_id           │ system_prompt
        │ endpoint           │ capabilities       │ temperature
        │ adapter_type       │ pricing            │ max_tokens
        └────────────────────┴────────────────────┘

.. csv-table:: Entity Responsibilities
   :header: "Entity", "Responsibility", "Changes When"
   :widths: 20, 40, 40

   "Provider", "API authentication & connection", "API key rotates, endpoint changes"
   "Model", "Capabilities & pricing", "New model version, pricing update"
   "Configuration", "Use-case behavior", "Prompt tuning, parameter adjustment"

Implementation
==============

Database tables
---------------

.. code-block:: sql

   -- Level 1: Providers (connections)
   CREATE TABLE tx_nrllm_provider (
       uid int(11) PRIMARY KEY,
       identifier varchar(100) UNIQUE,
       adapter_type varchar(50),
       endpoint_url varchar(500),
       api_key varchar(500),  -- Encrypted
       ...
   );

   -- Level 2: Models (capabilities)
   CREATE TABLE tx_nrllm_model (
       uid int(11) PRIMARY KEY,
       identifier varchar(100) UNIQUE,
       provider_uid int(11) REFERENCES tx_nrllm_provider(uid),
       model_id varchar(150),
       capabilities text,  -- CSV: chat,vision,tools
       ...
   );

   -- Level 3: Configurations (use cases)
   CREATE TABLE tx_nrllm_configuration (
       uid int(11) PRIMARY KEY,
       identifier varchar(100) UNIQUE,
       model_uid int(11) REFERENCES tx_nrllm_model(uid),
       system_prompt text,
       temperature decimal(3,2),
       ...
   );

Domain models
-------------

.. code-block:: php

   // Provider → owns credentials
   class Provider extends AbstractEntity {
       public function getDecryptedApiKey(): string;
       public function toAdapterConfig(): array;
   }

   // Model → belongs to Provider
   class Model extends AbstractEntity {
       protected ?Provider $provider = null;
       protected int $providerUid = 0;

       public function hasCapability(string $cap): bool;
       public function getProvider(): ?Provider;
   }

   // Configuration → belongs to Model
   class LlmConfiguration extends AbstractEntity {
       protected ?Model $model = null;
       protected int $modelUid = 0;

       public function getModel(): ?Model;
       public function getProvider(): ?Provider; // Convenience
   }

Service layer access
--------------------

.. code-block:: php

   // Getting a ready-to-use provider from a configuration
   $config = $configurationRepository->findByIdentifier('blog-summarizer');
   $model = $config->getModel();
   $provider = $model->getProvider();

   // Provider adapter handles the actual API call
   $adapter = $providerAdapterRegistry->getAdapter($provider);
   $response = $adapter->chat($messages, $config->toOptions());

Backend module structure
------------------------

::

   Admin Tools → LLM
   ├── Dashboard      (overview, stats)
   ├── Providers      (CRUD, connection test)
   ├── Models         (CRUD, fetch from API)
   └── Configurations (CRUD, prompt testing)

Consequences
============

Positive
--------

●● **Single Source of Truth:** API key stored once per provider

●● **Flexible Connections:** Multiple providers of same type (prod/dev/backup)

● **Model Catalog:** Centralized model specs and pricing

● **Clear Separation:** Connection vs capability vs use-case concerns

◐ **Easy Key Rotation:** Update one provider, all configs inherit

◐ **Cost Tracking:** Usage attributable to specific providers

◐ **Multi-Tenancy Ready:** Different API keys per team/project

Negative
--------

◑ **Increased Complexity:** Three tables instead of one

◑ **More Joins:** Queries must traverse relationships

◑ **Migration Required:** Existing data needs transformation

◑ **Learning Curve:** Users must understand hierarchy

**Net Score:** +5 (Strong positive)

Trade-offs
----------

.. csv-table::
   :header: "Single Table", "Three-Level"
   :widths: 50, 50

   "Simple queries", "Normalized data"
   "Data duplication", "Referential integrity"
   "Faster reads", "Smaller storage"
   "Harder maintenance", "Easier updates"

Alternatives considered
=======================

1. Two-Level (Provider → Configuration)
---------------------------------------

**Rejected:** Models would be embedded in configurations, duplicating capabilities/pricing.

2. Four-Level (Provider → Model → Preset → Configuration)
---------------------------------------------------------

**Rejected:** Preset layer adds complexity without clear benefit. Temperature/token settings belong with use-case.

3. Single Table with JSON Columns
---------------------------------

**Rejected:** Loses referential integrity, harder to query, no normalization.

4. Configuration Inheritance
----------------------------

**Rejected:** Complex to implement, confusing precedence rules.

Future considerations
=====================

1. **Model Auto-Discovery:** Fetch available models from provider APIs
2. **Cost Aggregation:** Track usage and costs per provider/model
3. **Fallback Chains:** Configuration → fallback model if primary fails
4. **Rate Limiting:** Per-provider rate limit tracking
5. **Health Monitoring:** Provider availability status

References
==========

- `TYPO3 Extbase Domain Modeling <https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ExtensionArchitecture/Extbase/Reference/Domain/Model.html>`__
- `Database Normalization <https://en.wikipedia.org/wiki/Database_normalization>`__
