.. include:: /Includes.rst.txt

.. _configuration:

=============
Configuration
=============

The extension uses a database-based configuration
architecture with three levels: **Providers**,
**Models**, and **Configurations**. All management
is done through the TYPO3 backend module.

.. contents::
   :local:
   :depth: 2

.. _configuration-backend-module:

Backend module
==============

Access the LLM management module at
:guilabel:`Admin Tools > LLM`.

.. figure:: /Images/backend-dashboard.png
   :alt: LLM backend module dashboard with wizard
       callouts
   :class: with-border with-shadow
   :zoom: lightbox

   The LLM dashboard shows setup progress, provider
   and model counts, AI wizard buttons, and
   quick-reference PHP code.

The backend module provides five sections:

:guilabel:`Dashboard`
   Overview of registered providers, models,
   and configurations with status indicators.

:guilabel:`Providers`
   Manage API connections with encrypted
   credentials. Test connections directly
   from the interface.

:guilabel:`Models`
   Define available models with their capabilities
   and pricing. Fetch models from provider APIs.

:guilabel:`Configurations`
   Create use-case-specific configurations with
   prompts and parameters.

:guilabel:`Tasks`
   Define one-shot prompt templates that combine a
   configuration with a user prompt for reusable
   AI operations.

.. _configuration-provider:

Provider configuration
======================

Providers represent API connections with credentials.
Create providers in
:guilabel:`Admin Tools > LLM > Providers`.

.. figure:: /Images/backend-providers.png
   :alt: LLM providers list with connection status
   :class: with-border with-shadow
   :zoom: lightbox

   Provider list showing adapter type, endpoint,
   API key status, and action buttons.

.. _configuration-provider-required:

Required fields
---------------

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

   API key for authentication. Encrypted at rest
   using :php:`sodium_crypto_secretbox`. Not
   required for local providers like Ollama.

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

Models represent specific LLM models available
through a provider. Create models in
:guilabel:`Admin Tools > LLM > Models`.

.. figure:: /Images/backend-models.png
   :alt: Model list showing capabilities and pricing
   :class: with-border with-shadow
   :zoom: lightbox

   The model list displays each model's capabilities,
   context length, pricing, and default status.

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

   - OpenAI: ``gpt-5.3-instant``, ``gpt-5.4-thinking``,
     ``gpt-5.4-pro``.
   - Anthropic: ``claude-opus-4-6``,
     ``claude-sonnet-4-6``, ``claude-haiku-4-5``.
   - Google: ``gemini-3.1-pro-preview``,
     ``gemini-3-flash-preview``.

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

Use the :guilabel:`Fetch Models` action to
automatically retrieve available models from
the provider's API. This populates the model
list with the provider's current offerings.

.. _configuration-llm:

LLM configuration
=================

Configurations define specific use cases with model
selection and parameters. Create configurations in
:guilabel:`Admin Tools > LLM > Configurations`.

.. figure:: /Images/backend-configurations.png
   :alt: Configurations list with model and use case
       details
   :class: with-border with-shadow
   :zoom: lightbox

   The configurations list shows each configuration's
   model assignment, use case type, and parameters.

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

.. _configuration-tasks:

Tasks
=====

Tasks are one-shot prompt templates that combine a
configuration with a specific user prompt. They
provide reusable AI operations that can be executed
with a single click or API call.

Create tasks in :guilabel:`Admin Tools > LLM > Tasks`.

.. figure:: /Images/backend-tasks.png
   :alt: Task list page showing available tasks
   :class: with-border with-shadow
   :zoom: lightbox

   The task list displays all configured tasks with
   their assigned configuration, description, and
   action buttons.

Each task references an LLM configuration (which
provides the model, system prompt, and parameters)
and adds a user prompt template. This separation
allows the same configuration to power multiple
tasks with different prompts.

Example use cases for tasks:

- **Summarize content** - condense long articles.
- **Generate meta descriptions** - SEO optimization.
- **Translate text** - one-click translation to a
  target language.
- **Extract keywords** - pull key terms from content.

.. _configuration-wizards:

AI-powered wizards
==================

The extension includes AI-powered wizards that
simplify setup and configuration. These wizards use
your existing LLM providers to generate
configurations and tasks automatically.

.. _configuration-wizards-setup:

Setup wizard
------------

The setup wizard guides new users through the
initial configuration in five steps: creating a
provider, testing the connection, fetching models,
creating a configuration, and running a test prompt.

Access it from the :guilabel:`Dashboard` when no
providers are configured, or click the setup
wizard link on the dashboard at any time.

.. figure:: /Images/backend-setup-wizard.png
   :alt: Five-step setup wizard guiding initial
       configuration
   :class: with-border with-shadow
   :zoom: lightbox

   The setup wizard walks through provider creation,
   connection testing, model fetching, configuration,
   and test prompts.

.. _configuration-wizards-config:

Configuration wizard
--------------------

The configuration wizard generates a complete LLM
configuration using AI. Click
:guilabel:`Create with AI` on the configurations
list page to open the wizard.

Describe your use case in plain language (e.g.,
"summarize blog posts in three sentences") and the
wizard generates the identifier, name, system
prompt, temperature, and other parameters.

.. figure:: /Images/backend-config-wizard.png
   :alt: AI configuration wizard form
   :class: with-border with-shadow
   :zoom: lightbox

   The configuration wizard generates all fields
   from a natural-language description of the
   desired use case.

.. _configuration-wizards-task:

Task wizard
-----------

The task wizard creates a complete task setup in one
step. Click :guilabel:`Create with AI` on the tasks
list page and describe the task in plain language.

The wizard generates a task with prompt template,
a dedicated configuration with system prompt and
parameters, and a model recommendation.

.. figure:: /Images/backend-task-wizard.png
   :alt: AI task wizard form
   :class: with-border with-shadow
   :zoom: lightbox

   The task wizard generates a complete task
   definition from a description of the desired
   operation.

.. _configuration-wizards-model-discovery:

Model discovery
---------------

On the model edit form, use the
:guilabel:`Fetch Models` button to query the
provider's API and automatically populate the
available models list. This ensures your model
catalogue stays current as providers release new
models.

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
           # Default LLM provider (openai, claude, gemini)
           defaultProvider = openai

           # Enable/disable response caching
           enableCaching = 1

           # Cache lifetime in seconds
           cacheLifetime = 3600

           # Per-provider settings
           providers {
               openai {
                   enabled = 1
                   defaultModel = gpt-5.3-instant
                   temperature = 0.7
                   maxTokens = 4096
               }

               claude {
                   enabled = 1
                   defaultModel = claude-sonnet-4-6
                   temperature = 0.7
                   maxTokens = 4096
               }

               gemini {
                   enabled = 1
                   defaultModel = gemini-3-flash-preview
                   temperature = 0.7
                   maxTokens = 4096
               }
           }
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

1. **Encrypted storage**: API keys are encrypted
   using :php:`sodium_crypto_secretbox`.
2. **Database security**: Ensure database backups
   are encrypted.
3. **Backend access**: Restrict backend module
   access to authorized users.
4. **Key rotation**: Changing the TYPO3
   :php:`encryptionKey` requires re-encryption.

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
