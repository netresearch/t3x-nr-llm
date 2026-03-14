.. include:: /Includes.rst.txt

.. _configuration-model:

============
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
========

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
========

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
