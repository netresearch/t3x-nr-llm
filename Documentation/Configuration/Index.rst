.. include:: /Includes.rst.txt

.. _configuration:

=============
Configuration
=============

The extension uses a database-based configuration architecture with three levels:
**Providers**, **Models**, and **Configurations**. All management is done through the
TYPO3 Backend Module.

.. contents::
   :local:
   :depth: 2

.. _backend-module:

Backend module
==============

Access the LLM management module at :guilabel:`Admin Tools > LLM`.

The backend module provides four sections:

Dashboard
   Overview of registered providers, models, and configurations with status indicators.

Providers
   Manage API connections with encrypted credentials. Test connections directly from the interface.

Models
   Define available models with their capabilities and pricing. Fetch models from provider APIs.

Configurations
   Create use-case-specific configurations with prompts and parameters.

.. _provider-configuration:

Provider configuration
======================

Providers represent API connections with credentials. Create providers in
:guilabel:`Admin Tools > LLM > Providers`.

Required fields
---------------

identifier
   Unique slug for programmatic access (e.g., ``openai-prod``, ``ollama-local``)

name
   Display name shown in the backend

adapter_type
   The protocol to use. Available options:

   - ``openai`` - OpenAI API
   - ``anthropic`` - Anthropic Claude API
   - ``gemini`` - Google Gemini API
   - ``ollama`` - Local Ollama instance
   - ``openrouter`` - OpenRouter multi-model API
   - ``mistral`` - Mistral AI API
   - ``groq`` - Groq inference API
   - ``azure_openai`` - Azure OpenAI Service
   - ``custom`` - Custom OpenAI-compatible endpoint

api_key
   API key for authentication. Encrypted at rest using sodium_crypto_secretbox.
   Not required for local providers like Ollama.

Optional fields
---------------

endpoint_url
   Custom API endpoint. Leave empty to use the adapter's default URL.

organization_id
   Organization ID for providers that support it (OpenAI, Azure).

timeout
   Request timeout in seconds. Default: 30

max_retries
   Number of retry attempts on failure. Default: 3

options
   JSON object with additional adapter-specific options.

Testing connections
-------------------

Use the :guilabel:`Test Connection` button to verify provider configuration.
The test makes an actual HTTP request to the provider's API and returns:

- Connection status (success/failure)
- Available models (if supported by the provider)
- Error details (on failure)

.. _model-configuration:

Model configuration
===================

Models represent specific LLM models available through a provider.
Create models in :guilabel:`Admin Tools > LLM > Models`.

Required fields
---------------

identifier
   Unique slug (e.g., ``gpt-5``, ``claude-sonnet``)

name
   Display name (e.g., ``GPT-5 (128K)``)

provider
   Reference to the parent Provider

model_id
   The API model identifier. Examples vary by provider:

   - OpenAI: ``gpt-5``, ``gpt-5.2-instant``, ``o4-mini``
   - Anthropic: ``claude-opus-4-5-20251101``, ``claude-sonnet-4-5-20251101``
   - Google: ``gemini-3-pro-preview``, ``gemini-3-flash-preview``

Optional fields
---------------

context_length
   Maximum context window in tokens (e.g., 128000 for GPT-5)

max_output_tokens
   Maximum output tokens (e.g., 16384)

capabilities
   Comma-separated list of supported features:

   - ``chat`` - Chat completion
   - ``completion`` - Text completion
   - ``embeddings`` - Text-to-vector
   - ``vision`` - Image analysis
   - ``streaming`` - Real-time streaming
   - ``tools`` - Function/tool calling

cost_input
   Cost per 1M input tokens in cents (for cost tracking)

cost_output
   Cost per 1M output tokens in cents

is_default
   Mark as default model for this provider

Fetching models from provider
-----------------------------

Use the :guilabel:`Fetch Models` action to automatically retrieve available models
from the provider's API. This populates the model list with the provider's
current offerings.

.. _llm-configuration:

LLM configuration
=================

Configurations define specific use cases with model selection and parameters.
Create configurations in :guilabel:`Admin Tools > LLM > Configurations`.

Required fields
---------------

identifier
   Unique slug for programmatic access (e.g., ``blog-summarizer``)

name
   Display name (e.g., ``Blog Post Summarizer``)

model
   Reference to the Model to use

system_prompt
   System message that sets the AI's behavior and context

Optional fields
---------------

temperature
   Creativity level from 0.0 (deterministic) to 2.0 (creative). Default: 0.7

max_tokens
   Maximum response length in tokens

top_p
   Nucleus sampling parameter (0.0 - 1.0)

frequency_penalty
   Reduces word repetition (-2.0 to 2.0)

presence_penalty
   Encourages topic diversity (-2.0 to 2.0)

use_case_type
   The type of task:

   - ``chat`` - Conversational interactions
   - ``completion`` - Text completion
   - ``embedding`` - Vector generation
   - ``translation`` - Language translation

.. _using-configurations:

Using configurations
====================

Retrieve configurations programmatically:

.. code-block:: php

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

.. _typoscript-configuration:

TypoScript configuration
========================

Runtime settings can be configured via TypoScript:

Constants
---------

.. code-block:: typoscript
   :caption: TypoScript Constants

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

.. _environment-variables:

Environment variables
=====================

For deployment flexibility, use environment variables:

.. code-block:: bash
   :caption: .env

   # TYPO3 encryption key (used for API key encryption)
   TYPO3_CONF_VARS__SYS__encryptionKey=your-secure-encryption-key

   # Optional: Override provider settings via environment
   TYPO3_NR_LLM_DEFAULT_TIMEOUT=60

.. _security-considerations:

Security considerations
=======================

API key protection
------------------

1. **Encrypted storage**: API keys are encrypted using sodium_crypto_secretbox
2. **Database security**: Ensure database backups are encrypted
3. **Backend access**: Restrict backend module access to authorized users
4. **Key rotation**: Change the TYPO3 encryptionKey requires re-encryption

Input sanitization
------------------

Always sanitize user input before sending to LLM providers:

.. code-block:: php

   use TYPO3\CMS\Core\Utility\GeneralUtility;

   $sanitizedInput = GeneralUtility::removeXSS($userInput);
   $response = $adapter->chatCompletion([
       ['role' => 'user', 'content' => $sanitizedInput]
   ]);

Output handling
---------------

Treat LLM responses as untrusted content:

.. code-block:: php

   $response = $adapter->chatCompletion($messages);
   $safeOutput = htmlspecialchars($response->content, ENT_QUOTES, 'UTF-8');

.. _logging-configuration:

Logging configuration
=====================

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

Log file location: ``var/log/typo3_nr_llm_*.log``

.. _caching-configuration:

Caching configuration
=====================

The extension uses TYPO3's caching framework:

- **Cache identifier**: ``nrllm_responses``
- **Default TTL**: 3600 seconds (1 hour)
- **Embeddings TTL**: 86400 seconds (24 hours)

Clear cache via CLI:

.. code-block:: bash

   vendor/bin/typo3 cache:flush --group=nrllm
