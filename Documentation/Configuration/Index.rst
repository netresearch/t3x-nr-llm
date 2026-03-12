.. include:: /Includes.rst.txt

.. _configuration:

=======================
Configuration reference
=======================

This page is the **field reference** for all
configurable entities. For step-by-step setup
instructions, see the
:ref:`Administration guide <administration>`.

.. contents::
   :local:
   :depth: 2

.. _configuration-provider:

Provider fields
===============

Providers represent API connections with credentials.

.. figure:: /Images/backend-providers.png
   :alt: LLM providers list with connection status
   :class: with-border with-shadow
   :zoom: lightbox

   Provider list showing adapter type, endpoint,
   API key status, and action buttons.

.. _configuration-provider-required:

Required
--------

.. confval:: identifier
   :name: confval-provider-identifier
   :type: string
   :required: true

   Unique slug for programmatic access
   (e.g., ``openai-prod``, ``ollama-local``).

.. confval:: name
   :name: confval-provider-name
   :type: string
   :required: true

   Display name shown in the backend.

.. confval:: adapter_type
   :name: confval-provider-adapter-type
   :type: string
   :required: true

   The protocol to use:

   - ``openai`` — OpenAI API
   - ``anthropic`` — Anthropic Claude API
   - ``gemini`` — Google Gemini API
   - ``ollama`` — Local Ollama instance
   - ``openrouter`` — OpenRouter multi-model API
   - ``mistral`` — Mistral AI API
   - ``groq`` — Groq inference API
   - ``azure_openai`` — Azure OpenAI Service
   - ``custom`` — OpenAI-compatible endpoint

.. confval:: api_key
   :name: confval-provider-api-key
   :type: string
   :required: true

   API key for authentication. Encrypted at rest
   using :php:`sodium_crypto_secretbox`. Not
   required for Ollama.

.. _configuration-provider-optional:

Optional
--------

.. confval:: endpoint_url
   :name: confval-provider-endpoint-url
   :type: string
   :Default: (adapter default)

   Custom API endpoint URL.

.. confval:: organization_id
   :name: confval-provider-organization-id
   :type: string
   :Default: (empty)

   Organization ID (OpenAI, Azure).

.. confval:: timeout
   :name: confval-provider-timeout
   :type: integer
   :Default: 30

   Request timeout in seconds.

.. confval:: max_retries
   :name: confval-provider-max-retries
   :type: integer
   :Default: 3

   Number of retry attempts on failure.

.. confval:: options
   :name: confval-provider-options
   :type: JSON
   :Default: {}

   Additional adapter-specific options.

.. _configuration-model:

Model fields
============

Models represent specific LLM models available
through a provider.

.. figure:: /Images/backend-models.png
   :alt: Model list showing capabilities and pricing
   :class: with-border with-shadow
   :zoom: lightbox

   Model list with capability badges, context
   length, and cost columns.

.. _configuration-model-required:

Required
--------

.. confval:: identifier (model)
   :name: confval-model-identifier
   :type: string
   :required: true

   Unique slug (e.g., ``gpt-5``, ``claude-sonnet``).

.. confval:: name (model)
   :name: confval-model-name
   :type: string
   :required: true

   Display name (e.g., ``GPT-5 (128K)``).

.. confval:: provider
   :name: confval-model-provider
   :type: reference
   :required: true

   Reference to the parent provider.

.. confval:: model_id
   :name: confval-model-model-id
   :type: string
   :required: true

   The API model identifier as the provider expects
   it (e.g., ``gpt-5.3-instant``,
   ``claude-sonnet-4-6``, ``gemini-3-flash``).

.. _configuration-model-optional:

Optional
--------

.. confval:: context_length
   :name: confval-model-context-length
   :type: integer
   :Default: (provider default)

   Maximum context window in tokens.

.. confval:: max_output_tokens
   :name: confval-model-max-output-tokens
   :type: integer
   :Default: (model default)

   Maximum output tokens.

.. confval:: capabilities
   :name: confval-model-capabilities
   :type: string (CSV)
   :Default: chat

   Comma-separated capabilities: ``chat``,
   ``completion``, ``embeddings``, ``vision``,
   ``streaming``, ``tools``.

.. confval:: cost_input
   :name: confval-model-cost-input
   :type: integer
   :Default: 0

   Cost per 1M input tokens in cents.

.. confval:: cost_output
   :name: confval-model-cost-output
   :type: integer
   :Default: 0

   Cost per 1M output tokens in cents.

.. confval:: is_default
   :name: confval-model-is-default
   :type: boolean
   :Default: false

   Mark as default model for this provider.

.. _configuration-llm:

Configuration fields
====================

Configurations define use-case presets with model
selection and parameters.

.. figure:: /Images/backend-configurations.png
   :alt: Configuration list with model assignments
   :class: with-border with-shadow
   :zoom: lightbox

   Configuration list showing linked model,
   use-case type, and parameters.

.. _configuration-llm-required:

Required
--------

.. confval:: identifier (config)
   :name: confval-config-identifier
   :type: string
   :required: true

   Unique slug (e.g., ``blog-summarizer``).

.. confval:: name (config)
   :name: confval-config-name
   :type: string
   :required: true

   Display name (e.g., ``Blog Post Summarizer``).

.. confval:: model
   :name: confval-config-model
   :type: reference
   :required: true

   Reference to the model to use.

.. confval:: system_prompt
   :name: confval-config-system-prompt
   :type: text
   :required: true

   System message that sets the AI's behavior.

.. _configuration-llm-optional:

Optional
--------

.. confval:: temperature
   :name: confval-config-temperature
   :type: float
   :Default: 0.7

   Creativity (0.0 = deterministic, 2.0 = creative).

.. confval:: max_tokens (config)
   :name: confval-config-max-tokens
   :type: integer
   :Default: (model default)

   Maximum response length in tokens.

.. confval:: top_p
   :name: confval-config-top-p
   :type: float
   :Default: 1.0

   Nucleus sampling (0.0–1.0).

.. confval:: frequency_penalty
   :name: confval-config-frequency-penalty
   :type: float
   :Default: 0.0

   Reduces word repetition (-2.0 to 2.0).

.. confval:: presence_penalty
   :name: confval-config-presence-penalty
   :type: float
   :Default: 0.0

   Encourages topic diversity (-2.0 to 2.0).

.. confval:: use_case_type
   :name: confval-config-use-case-type
   :type: string
   :Default: chat

   Task type: ``chat``, ``completion``,
   ``embedding``, ``translation``.

.. _configuration-tasks:

Task fields
===========

Tasks combine a configuration with a user prompt
template for one-shot AI operations.

.. figure:: /Images/backend-tasks.png
   :alt: Task list page
   :class: with-border with-shadow
   :zoom: lightbox

   Task list with assigned configurations.

Each task references an LLM configuration and adds
a user prompt template. The same configuration can
power multiple tasks with different prompts.

.. _configuration-typoscript:

TypoScript settings
===================

Runtime settings via TypoScript constants:

.. code-block:: typoscript
   :caption: Configuration/TypoScript/constants.typoscript

   plugin.tx_nrllm {
       settings {
           # Default provider (openai, claude, gemini)
           defaultProvider = openai
           # Enable response caching
           enableCaching = 1
           # Cache lifetime in seconds
           cacheLifetime = 3600
       }
   }

.. _configuration-environment:

Environment variables
=====================

.. code-block:: bash
   :caption: .env

   # TYPO3 encryption key (used for API key encryption)
   TYPO3_CONF_VARS__SYS__encryptionKey=your-key

   # Optional: Override default timeout
   TYPO3_NR_LLM_DEFAULT_TIMEOUT=60

.. _configuration-security:

Security
========

.. _configuration-security-api-keys:

API key protection
------------------

1. **Encrypted storage** — API keys use
   :php:`sodium_crypto_secretbox`.
2. **Database security** — ensure backups are
   encrypted.
3. **Backend access** — restrict the module to
   authorized administrators.
4. **Key rotation** — changing the TYPO3
   :php:`encryptionKey` requires re-encryption.

.. _configuration-security-input:

Input sanitization
------------------

Sanitize user input before sending to providers:

.. code-block:: php
   :caption: Example: Sanitizing user input

   use TYPO3\CMS\Core\Utility\GeneralUtility;

   $sanitizedInput = GeneralUtility::removeXSS(
       $userInput
   );

.. _configuration-security-output:

Output handling
---------------

Treat LLM responses as untrusted content:

.. code-block:: php
   :caption: Example: Escaping output

   $safeOutput = htmlspecialchars(
       $response->content, ENT_QUOTES, 'UTF-8'
   );

.. _configuration-logging:

Logging
=======

.. code-block:: php
   :caption: config/system/additional.php

   $GLOBALS['TYPO3_CONF_VARS']['LOG']
       ['Netresearch']['NrLlm'] = [
       'writerConfiguration' => [
           \Psr\Log\LogLevel::DEBUG => [
               \TYPO3\CMS\Core\Log\Writer\FileWriter
                   ::class => [
                   'logFileInfix' => 'nr_llm',
               ],
           ],
       ],
   ];

Log files: :file:`var/log/typo3_nr_llm_*.log`

.. _configuration-caching:

Caching
=======

The extension uses TYPO3's caching framework:

- **Cache identifier**: ``nrllm_responses``
- **Default TTL**: 3600 seconds (1 hour)
- **Embeddings TTL**: 86400 seconds (24 hours)

Clear cache:

.. code-block:: bash

   vendor/bin/typo3 cache:flush --group=nrllm
