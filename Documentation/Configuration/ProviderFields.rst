.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

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
   - ``typesafe`` — TypeSafe decision API
     (decisions only, :ref:`api-decision-service`)
   - ``decision_sidecar`` — local decision sidecar
     (decisions only, no API key)

.. confval:: api_key
   :name: confval-provider-api-key
   :type: string

   API key for authentication. Stored as a
   `nr-vault <https://github.com/netresearch/t3x-nr-vault>`__
   UUID identifier (envelope encryption).
   nr-llm never stores raw API keys in the
   database. Required for cloud providers
   (OpenAI, Claude, Gemini, etc.); not required
   for local providers like Ollama.

.. _configuration-provider-optional:

Optional
========

.. confval:: endpoint_url
   :name: confval-provider-endpoint-url
   :type: string
   :Default: (adapter default)

   Custom API endpoint URL.

   For ``azure_openai`` it is required and names the URL shape
   (:ref:`ADR-247 <adr-247>`):

   - the resource URL, for example ``https://contoso.openai.azure.com``:
     requests go to
     ``/openai/deployments/<model id>/<operation>?api-version=<version>``,
     so the model record's model id is the **deployment name**;
   - a URL ending in ``/openai/v1``: requests go to the OpenAI paths below
     it, the deployment name travels as the body's ``model``, and no
     ``api-version`` is sent.

   Both send the key in the ``api-key`` header.

.. confval:: organization_id
   :name: confval-provider-organization-id
   :type: string
   :Default: (empty)

   Organization ID (OpenAI). Sent as the `OpenAI-Organization`
   request header by the OpenAI-compatible adapters (`openai`,
   `together`, `fireworks`, `perplexity`, `custom`). Azure OpenAI does not
   read it, and `azure_openai` does not send it.

.. confval:: timeout
   :name: confval-provider-timeout
   :type: integer
   :Default: 120

   Maximum time in seconds to wait for the complete API response. A
   configuration- or model-level timeout overrides this value per request.

.. confval:: max_retries
   :name: confval-provider-max-retries
   :type: integer
   :Default: 3

   Number of retries after the first failed request (0 = try once, no
   retries). Timed-out requests are not retried.

.. confval:: options
   :name: confval-provider-options
   :type: JSON
   :Default: {}

   Additional adapter-specific options. Supported keys:

   `customHeaders`
      Object mapping header names to values, sent on every API request
      (including streaming), for example:

      .. code-block:: json

         {"customHeaders": {"X-Custom-Header": "value"}}

      Custom headers are applied after the adapter's own headers, so a
      colliding name overrides the adapter value — a mistyped
      `Content-Type` breaks that provider's calls.

   `apiVersion`
      ``azure_openai`` on the deployment API only: the ``api-version`` to
      send, for example ``{"apiVersion": "2025-04-01-preview"}``. Default
      ``2024-10-21``; a value not shaped like a version falls back to it.

   .. note::

      A per-provider `proxy` is not supported; TYPO3's global HTTP proxy
      configuration (`$GLOBALS['TYPO3_CONF_VARS']['HTTP']['proxy']`)
      applies to all outgoing provider requests.
