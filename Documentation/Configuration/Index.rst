.. include:: /Includes.rst.txt

.. _configuration:

=============
Configuration
=============

The extension uses a database-based configuration architecture with three levels:
**Providers**, **Models**, and **Configurations**. All management is done through the
TYPO3 backend module.

.. contents::
   :local:
   :depth: 2

.. _configuration-backend-module:

Backend module
==============

Access the LLM management module at :guilabel:`Admin Tools > LLM`.

The backend module provides four sections:

:guilabel:`Dashboard`
   Overview of registered providers, models, and configurations with status indicators.

:guilabel:`Providers`
   Manage API connections with encrypted credentials. Test connections directly from the interface.

:guilabel:`Models`
   Define available models with their capabilities and pricing. Fetch models from provider APIs.

:guilabel:`Configurations`
   Create use-case-specific configurations with prompts and parameters.

.. _configuration-provider:

Provider configuration
======================

Providers represent API connections with credentials. Create providers in
:guilabel:`Admin Tools > LLM > Providers`.

.. _configuration-provider-required:

Required fields
---------------

.. confval:: identifier
   :name: confval-provider-identifier
   :type: string
   :required: true

   Unique slug for programmatic access (e.g., ``openai-prod``, ``ollama-local``).

.. confval:: name
   :name: confval-provider-name
   :type: string
   :required: true

   Display name shown in the backend.

.. confval:: adapter_type
   :name: confval-provider-adapter-type
   :type: string
   :required: true

   The protocol to use. Available options:

   - ``openai`` - OpenAI API.
   - ``anthropic`` - Anthropic Claude API.
   - ``gemini`` - Google Gemini API.
   - ``ollama`` - Local Ollama instance.
   - ``openrouter`` - OpenRouter multi-model API.
   - ``mistral`` - Mistral AI API.
   - ``groq`` - Groq inference API.
   - ``azure_openai`` - Azure OpenAI Service.
   - ``custom`` - Custom OpenAI-compatible endpoint.

.. confval:: api_key
   :name: confval-provider-api-key
   :type: string
   :required: true

   API key for authentication. Encrypted at rest using :php:`sodium_crypto_secretbox`.
   Not required for local providers like Ollama.

.. _configuration-provider-optional:

Optional fields
---------------

.. confval:: endpoint_url
   :name: confval-provider-endpoint-url
   :type: string
   :Default: (adapter default)

   Custom API endpoint. Leave empty to use the adapter's default URL.

.. confval:: organization_id
   :name: confval-provider-organization-id
   :type: string
   :Default: (empty)

   Organization ID for providers that support it (OpenAI, Azure).

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

   JSON object with additional adapter-specific options.

.. _configuration-provider-testing:

Testing provider connections
----------------------------

Use the :guilabel:`Test Connection` button to verify provider configuration.
The test makes an actual HTTP request to the provider's API and returns:

- Connection status (success/failure).
- Available models (if supported by the provider).
- Error details (on failure).

.. _configuration-model:

Model configuration
===================

Models represent specific LLM models available through a provider.
Create models in :guilabel:`Admin Tools > LLM > Models`.

.. _configuration-model-required:

Required fields
---------------

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

   The API model identifier. Examples vary by provider:

   - OpenAI: ``gpt-5``, ``gpt-5.2-instant``, ``o4-mini``.
   - Anthropic: ``claude-opus-4-5-20251101``, ``claude-sonnet-4-5-20251101``.
   - Google: ``gemini-3-pro-preview``, ``gemini-3-flash-preview``.

.. _configuration-model-optional:

Optional fields
---------------

.. confval:: context_length
   :name: confval-model-context-length
   :type: integer
   :Default: (provider default)

   Maximum context window in tokens (e.g., 128000 for GPT-5).

.. confval:: max_output_tokens
   :name: confval-model-max-output-tokens
   :type: integer
   :Default: (model default)

   Maximum output tokens (e.g., 16384).

.. confval:: capabilities
   :name: confval-model-capabilities
   :type: string (CSV)
   :Default: chat

   Comma-separated list of supported features:

   - ``chat`` - Chat completion.
   - ``completion`` - Text completion.
   - ``embeddings`` - Text-to-vector.
   - ``vision`` - Image analysis.
   - ``streaming`` - Real-time streaming.
   - ``tools`` - Function/tool calling.

.. confval:: cost_input
   :name: confval-model-cost-input
   :type: integer
   :Default: 0

   Cost per 1M input tokens in cents (for cost tracking).

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

.. _configuration-model-fetching:

Fetching models from providers
------------------------------

Use the :guilabel:`Fetch Models` action to automatically retrieve available models
from the provider's API. This populates the model list with the provider's
current offerings.

.. _configuration-llm:

LLM configuration
=================

Configurations define specific use cases with model selection and parameters.
Create configurations in :guilabel:`Admin Tools > LLM > Configurations`.

.. _configuration-llm-required:

Required fields
---------------

.. confval:: identifier (config)
   :name: confval-config-identifier
   :type: string
   :required: true

   Unique slug for programmatic access (e.g., ``blog-summarizer``).

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

   System message that sets the AI's behavior and context.

.. _configuration-llm-optional:

Optional fields
---------------

.. confval:: temperature
   :name: confval-config-temperature
   :type: float
   :Default: 0.7

   Creativity level from 0.0 (deterministic) to 2.0 (creative).

.. confval:: max_tokens (config)
   :name: confval-config-max-tokens
   :type: integer
   :Default: (model default)

   Maximum response length in tokens.

.. confval:: top_p
   :name: confval-config-top-p
   :type: float
   :Default: 1.0

   Nucleus sampling parameter (0.0 - 1.0).

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

   The type of task:

   - ``chat`` - Conversational interactions.
   - ``completion`` - Text completion.
   - ``embedding`` - Vector generation.
   - ``translation`` - Language translation.

.. _configuration-using:

Using configurations
====================

Retrieve configurations programmatically:

.. code-block:: php
   :caption: Example: Using configurations in a controller

   use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
   use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;

   class MyController
   {
       public function __construct(
           private readonly LlmConfigurationRepository $configRepository,
           private readonly ProviderAdapterRegistry $adapterRegistry,
       ) {}

       public function processAction(): void
       {
           // Get configuration by identifier
           $config = $this->configRepository->findByIdentifier('blog-summarizer');

           // Get the model and provider
           $model = $config->getModel();
           $provider = $model->getProvider();

           // Create adapter and make requests
           $adapter = $this->adapterRegistry->createAdapterFromModel($model);
           $response = $adapter->chatCompletion($messages, $config->toOptions());
       }
   }

.. _configuration-typoscript:

TypoScript settings
===================

Runtime settings can be configured via TypoScript:

.. _configuration-typoscript-constants:

Constants
---------

.. code-block:: typoscript
   :caption: Configuration/TypoScript/constants.typoscript

   plugin.tx_nrllm {
       settings {
           # Default temperature (0.0-2.0)
           defaultTemperature = 0.7

           # Maximum tokens for responses
           defaultMaxTokens = 1000

           # Cache lifetime in seconds
           cacheLifetime = 3600

           # Enable/disable response caching
           enableCaching = 1

           # Enable streaming by default
           enableStreaming = 0
       }
   }

.. _configuration-environment:

Environment variables
=====================

For deployment flexibility, use environment variables:

.. code-block:: bash
   :caption: .env

   # TYPO3 encryption key (used for API key encryption)
   TYPO3_CONF_VARS__SYS__encryptionKey=your-secure-encryption-key

   # Optional: Override provider settings via environment
   TYPO3_NR_LLM_DEFAULT_TIMEOUT=60

.. _configuration-security:

Security
========

.. _configuration-security-api-keys:

API key protection
------------------

1. **Encrypted storage**: API keys are encrypted using :php:`sodium_crypto_secretbox`.
2. **Database security**: Ensure database backups are encrypted.
3. **Backend access**: Restrict backend module access to authorized users.
4. **Key rotation**: Changing the TYPO3 :php:`encryptionKey` requires re-encryption.

.. _configuration-security-input:

Input sanitization
------------------

Always sanitize user input before sending to LLM providers:

.. code-block:: php
   :caption: Example: Sanitizing user input

   use TYPO3\CMS\Core\Utility\GeneralUtility;

   $sanitizedInput = GeneralUtility::removeXSS($userInput);
   $response = $adapter->chatCompletion([
       ['role' => 'user', 'content' => $sanitizedInput]
   ]);

.. _configuration-security-output:

Output handling
---------------

Treat LLM responses as untrusted content:

.. code-block:: php
   :caption: Example: Escaping output

   $response = $adapter->chatCompletion($messages);
   $safeOutput = htmlspecialchars($response->content, ENT_QUOTES, 'UTF-8');

.. _configuration-logging:

Logging
=======

Enable detailed logging for debugging:

.. code-block:: php
   :caption: config/system/additional.php

   $GLOBALS['TYPO3_CONF_VARS']['LOG']['Netresearch']['NrLlm'] = [
       'writerConfiguration' => [
           \Psr\Log\LogLevel::DEBUG => [
               \TYPO3\CMS\Core\Log\Writer\FileWriter::class => [
                   'logFileInfix' => 'nr_llm',
               ],
           ],
       ],
   ];

Log file location: :file:`var/log/typo3_nr_llm_*.log`

.. _configuration-caching:

Caching
=======

The extension uses TYPO3's caching framework:

- **Cache identifier**: ``nrllm_responses``.
- **Default TTL**: 3600 seconds (1 hour).
- **Embeddings TTL**: 86400 seconds (24 hours).

Clear cache via CLI:

.. code-block:: bash
   :caption: Clear extension caches

   vendor/bin/typo3 cache:flush --group=nrllm
