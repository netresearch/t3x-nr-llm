.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _configuration-adapters:

=================
Adapter catalogue
=================

These are the values of :php:`Domain\Model\AdapterType` in this revision.
An adapter's presence does not mean every model or operation supports every
feature. Discovery, declared model capabilities and the selected operation
must agree. Specialized image, speech and translator services have separate
interfaces and credentials.

.. list-table:: Protocol selection
   :header-rows: 1

   * - Stored value
     - Adapter
   * - ``openai``
     - OpenAI
   * - ``anthropic``
     - Anthropic Claude
   * - ``gemini``
     - Google Gemini
   * - ``openrouter``
     - OpenRouter
   * - ``mistral``
     - Mistral AI
   * - ``groq``
     - Groq
   * - ``together``
     - Together AI (OpenAI-compatible)
   * - ``fireworks``
     - Fireworks AI (OpenAI-compatible)
   * - ``perplexity``
     - Perplexity (OpenAI-compatible)
   * - ``ollama``
     - Ollama; no API key required by this adapter
   * - ``azure_openai``
     - Azure OpenAI; requires an endpoint and deployment/model setup
   * - ``custom``
     - Custom OpenAI-compatible endpoint
   * - ``typesafe``
     - TypeSafe; typed decisions, no text generation
   * - ``decision_sidecar``
     - Local decision sidecar; typed decisions, no text generation or API key

The last two are decision adapters, consumed through
:ref:`api-decision-service`. They are not substitutes for a chat or embedding
provider. See :ref:`configuration-provider` for connection fields.
