.. include:: /Includes.rst.txt

.. _administration-models:

===============
Managing models
===============

Models represent specific LLM models available
through a provider (e.g., ``gpt-5``,
``claude-sonnet-4-6``, ``llama-3``).

.. figure:: /Images/backend-models.png
   :alt: Model list showing capabilities, context
       length, pricing, and default status
   :class: with-border with-shadow
   :zoom: lightbox

   The model list with capability badges, context
   length, and cost-per-token columns.

.. _administration-models-add:

Adding a model manually
-----------------------

1. Navigate to :guilabel:`Admin Tools > LLM >
   Models`.
2. Click :guilabel:`Add Model`.
3. Fill in the required fields:

   :guilabel:`Identifier`
      Unique slug (e.g., ``gpt-5``,
      ``claude-sonnet``).

   :guilabel:`Name`
      Display name (e.g., ``GPT-5 (128K)``).

   :guilabel:`Provider`
      Select the parent provider.

   :guilabel:`Model ID`
      The API model identifier as the provider
      expects it (e.g., ``gpt-5.3-instant``,
      ``claude-sonnet-4-6``).

4. Optionally set capabilities (``chat``,
   ``completion``, ``embeddings``, ``vision``,
   ``streaming``, ``tools``), context length,
   max output tokens, and pricing.
5. Click :guilabel:`Save`.

.. _administration-models-fetch:

Fetching models from a provider
-------------------------------

Instead of adding models manually, use the
:guilabel:`Fetch Models` action to query the
provider API and auto-populate the model list:

1. Ensure the provider is saved and the connection
   test passes.
2. On the model list or model edit form, click
   :guilabel:`Fetch Models`.
3. The extension queries the provider API and
   creates model records with capabilities and
   metadata pre-filled.

This is the recommended approach — it ensures model
IDs match the provider exactly and keeps your
catalogue current as providers release new models.
