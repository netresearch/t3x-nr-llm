.. include:: /Includes.rst.txt

.. _configuration-provider:

===============
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
========

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
========

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
