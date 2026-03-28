.. include:: /Includes.rst.txt

.. _administration-providers:

==================
Managing providers
==================

Providers represent connections to AI services.
Each provider stores an API endpoint, encrypted
credentials, and adapter-specific settings.

.. figure:: /Images/backend-providers.png
   :alt: Provider list showing adapter type,
       endpoint URL, API key status, and actions
   :class: with-border with-shadow
   :zoom: lightbox

   The provider list with connection status
   indicators and action buttons.

.. _administration-providers-add:

Adding a provider
=================

1. Navigate to :guilabel:`Admin Tools > LLM >
   Providers`.
2. Click :guilabel:`Add Provider`.
3. Fill in the required fields:

   :guilabel:`Identifier`
      A unique slug for programmatic access
      (e.g., ``openai-prod``, ``ollama-local``).

   :guilabel:`Name`
      A display name for the backend
      (e.g., ``OpenAI Production``).

   :guilabel:`Adapter Type`
      Select the provider protocol. Available
      adapters: ``openai``, ``anthropic``,
      ``gemini``, ``ollama``, ``openrouter``,
      ``mistral``, ``groq``, ``azure_openai``,
      ``custom``.

   :guilabel:`API Key`
      Your API key. Stored securely via
      `nr-vault <https://github.com/netresearch/t3x-nr-vault>`__
      envelope encryption. Leave empty for local
      providers like Ollama.

4. Optionally set the endpoint URL, organization
   ID, timeout, and retry count.
5. Click :guilabel:`Save`.

.. tip::

   Use the :ref:`Setup wizard
   <administration-wizards-setup>` for guided
   first-time setup — it auto-detects the provider
   type from your endpoint URL.

.. _administration-providers-test:

Testing a connection
====================

After saving a provider, click
:guilabel:`Test Connection` to verify the setup.
The test makes an HTTP request to the provider API
and reports:

- Connection status (success or failure).
- Available models (if the provider supports
  listing).
- Error details on failure.

.. figure:: /Images/backend-provider-test.png
   :alt: Provider test modal showing successful
       connection to Local Ollama
   :class: with-border with-shadow
   :zoom: lightbox

   Successful connection test for the Local Ollama
   provider.

.. _administration-providers-edit:

Editing and deleting providers
==============================

- Click a provider row to edit its settings.
- Use the :guilabel:`Delete` action to remove a
  provider. Models linked to a deleted provider
  become inactive.
