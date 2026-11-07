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

.. _configuration-provider-inventory:

Field inventory
===============

.. list-table:: TCA fields (0.43.20 source)
   :header-rows: 1
   :widths: 22 12 12 8 46

   * - Field
     - TCA type
     - Default
     - Required in TCA
     - Effect
   * - ``hidden``
     - check
     - ``0``
     - no
     - TYPO3 record visibility; separate from runtime is_active.
   * - ``identifier``
     - input
     - ``unset in TCA``
     - yes
     - Unique programmatic identifier.
   * - ``name``
     - input
     - ``unset in TCA``
     - yes
     - Backend display name.
   * - ``description``
     - text
     - ``unset in TCA``
     - no
     - Description for operators.
   * - ``adapter_type``
     - select
     - ``"openai"``
     - yes
     - Protocol adapter; see the shared adapter catalogue.
   * - ``trust_zone``
     - select
     - ``"externalGlobal"``
     - yes
     - Operator-declared inference zone; default externalGlobal. Not independent certification.
   * - ``privacy_level``
     - select
     - ``empty``
     - no
     - Optional stricter persistence ceiling; empty inherits. Cannot loosen global policy.
   * - ``endpoint_url``
     - input
     - ``unset in TCA``
     - no
     - Adapter endpoint override; required for Azure and custom endpoints.
   * - ``api_key``
     - input
     - ``unset in TCA``
     - no
     - nr-vault credential UUID, not the plaintext key.
   * - ``organization_id``
     - input
     - ``unset in TCA``
     - no
     - OpenAI organization header where supported; not sent by Azure.
   * - ``api_timeout``
     - number
     - ``120``
     - no
     - Provider request timeout in seconds; model/configuration may override.
   * - ``max_retries``
     - number
     - ``3``
     - no
     - Retries after the initial buffered request; timeouts are not retried.
   * - ``options``
     - text
     - ``unset in TCA``
     - no
     - Additional adapter options; validate against the selected adapter.
   * - ``is_active``
     - check
     - ``1``
     - no
     - Whether the record is eligible for runtime use.
   * - ``priority``
     - number
     - ``50``
     - no
     - Operator priority used during model selection.

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

   The protocol adapter; see the complete shared
   :ref:`configuration-adapters`. Text-generation adapters and decision-only
   adapters have different operation contracts.

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

   Name a deployment after the model it serves (``gpt-5.2``): nr-llm reads
   the model's capabilities, such as a reasoning profile, from the model id,
   and a custom deployment name does not carry them. For reasoning models
   prefer the v1 URL or set a recent ``apiVersion``; older versions of the
   deployment API reject newer request fields. Enter the resource URL, not
   the portal's full target URI with ``/chat/completions`` in it. A resource
   behind a private endpoint resolves to a private address, which the
   nr-vault host filter refuses until the host is listed in its
   ``allowed_hosts``.

.. confval:: organization_id
   :name: confval-provider-organization-id
   :type: string
   :Default: (empty)

   Organization ID (OpenAI). Sent as the `OpenAI-Organization`
   request header by the OpenAI-compatible adapters (`openai`,
   `together`, `fireworks`, `perplexity`, `custom`). Azure OpenAI does not
   read it, and `azure_openai` does not send it.

.. confval:: api_timeout
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

.. _configuration-provider-trust:

Trust, privacy and priority
===========================

``trust_zone`` is an operator declaration: ``local``, ``privateHosted``,
``externalEu`` or ``externalGlobal`` (default). It controls the maximum data
class offered to the provider, including fallback reach. nr-llm does not
verify hosting jurisdiction, contracts or the truth of that declaration.
Do not raise the zone merely to make a denied call succeed.

``privacy_level`` is empty by default and inherits the global level. An
explicit ``none``, ``metadata``, ``redacted`` or ``full`` can only tighten the
combined persistence policy. See :ref:`administration-data-retention` for
exceptions such as functional resumable state and background answers.

``is_active`` defaults to true. ``priority`` defaults to 50 and supplies an
operator preference to routing; it does not override eligibility checks.
