.. include:: /Includes.rst.txt

.. _introduction:

============
Introduction
============

.. _introduction-what-it-does:

What does it do?
================

The TYPO3 LLM extension provides a unified abstraction layer for integrating
Large Language Models (LLMs) into TYPO3 applications. It enables developers to:

- **Access multiple AI providers** through a single, consistent API.
- **Switch providers transparently** without code changes.
- **Leverage specialized services** for common AI tasks.
- **Cache responses** to reduce API costs and improve performance.
- **Stream responses** for real-time user experiences.

.. _introduction-supported-providers:

Supported providers
-------------------

.. list-table::
   :header-rows: 1
   :widths: 20 30 50

   * - Provider
     - Models
     - Capabilities
   * - OpenAI
     - GPT-5.x series, o-series reasoning models
     - Chat, completions, embeddings, vision, streaming, tools.
   * - Anthropic Claude
     - Claude Opus 4.5, Claude Sonnet 4.5, Claude Haiku 4.5
     - Chat, completions, vision, streaming, tools.
   * - Google Gemini
     - Gemini 3 Pro, Gemini 3 Flash, Gemini 2.5 series
     - Chat, completions, embeddings, vision, streaming, tools.
   * - Ollama
     - Local models (Llama, Mistral, etc.)
     - Chat, embeddings, streaming (local).
   * - OpenRouter
     - Multi-provider access
     - Chat, vision, streaming, tools.
   * - Mistral
     - Mistral models
     - Chat, embeddings, streaming.
   * - Groq
     - Fast inference models
     - Chat, streaming (fast inference).
   * - Azure OpenAI
     - Same as OpenAI
     - Same as OpenAI.
   * - Custom
     - OpenAI-compatible endpoints
     - Varies by endpoint.

.. _introduction-key-features:

Key features
============

.. _introduction-unified-provider-api:

Unified provider API
--------------------

All providers implement a common interface, allowing you to:

- Switch between providers with a single configuration change.
- Test with different models without modifying application code.
- Implement provider fallbacks for increased reliability.

.. code-block:: php
   :caption: Example: Using the provider abstraction layer

   // Use database configurations for consistent settings
   $config = $configRepository->findByIdentifier('blog-summarizer');
   $adapter = $adapterRegistry->createAdapterFromModel($config->getModel());
   $response = $adapter->chatCompletion($messages, $config->toOptions());

   // Or use inline provider selection
   $response = $llmManager->chat($messages, ['provider' => 'openai']);
   $response = $llmManager->chat($messages, ['provider' => 'claude']);

.. _introduction-feature-services:

Specialized feature services
----------------------------

High-level services for common AI tasks:

:php:`CompletionService`
   Text generation with format control (JSON, Markdown) and creativity presets.

:php:`EmbeddingService`
   Text-to-vector conversion with caching and similarity calculations.

:php:`VisionService`
   Image analysis with specialized prompts for alt-text, titles, descriptions.

:php:`TranslationService`
   Language translation with formality control, domain-specific terminology, and glossaries.

:php:`PromptTemplateService`
   Centralized prompt management with variable substitution and versioning.

.. _introduction-streaming-support:

Streaming support
-----------------

Real-time response streaming for better user experience:

.. code-block:: php
   :caption: Example: Streaming chat responses

   foreach ($llmManager->streamChat($messages) as $chunk) {
       echo $chunk;
       flush();
   }

.. _introduction-tool-calling:

Tool/function calling
---------------------

Execute custom functions based on AI decisions:

.. code-block:: php
   :caption: Example: Tool/function calling

   $response = $llmManager->chatWithTools($messages, $tools);
   if ($response->hasToolCalls()) {
       // Process tool calls
   }

.. _introduction-caching:

Intelligent caching
-------------------

- Automatic response caching using TYPO3's caching framework.
- Deterministic embedding caching (24-hour default TTL).
- Configurable cache lifetimes per operation type.

.. _introduction-use-cases:

Use cases
=========

.. _introduction-use-cases-content:

Content generation
------------------

- Generate product descriptions.
- Create meta descriptions and SEO content.
- Draft blog posts and articles.
- Summarize long-form content.

.. _introduction-use-cases-translation:

Translation
-----------

- Translate website content.
- Maintain consistent terminology with glossaries.
- Preserve formatting in technical documents.

.. _introduction-use-cases-images:

Image processing
----------------

- Generate accessibility-compliant alt-text.
- Create SEO-optimized image titles.
- Analyze and categorize image content.

.. _introduction-use-cases-search:

Search and discovery
--------------------

- Semantic search using embeddings.
- Content similarity detection.
- Recommendation systems.

.. _introduction-use-cases-chatbots:

Chatbots and assistants
-----------------------

- Customer support chatbots.
- FAQ answering systems.
- Guided navigation assistants.

.. _introduction-requirements:

Requirements
============

- **PHP**: 8.5 or higher.
- **TYPO3**: v14.0 or higher.
- **HTTP client**: PSR-18 compatible (e.g., :composer:`guzzlehttp/guzzle`).

.. _introduction-provider-requirements:

Provider requirements
---------------------

To use specific providers, you need:

- **OpenAI**: API key from https://platform.openai.com.
- **Anthropic Claude**: API key from https://console.anthropic.com.
- **Google Gemini**: API key from https://aistudio.google.com.
- **Ollama**: Local installation from https://ollama.ai (no API key required).
- **OpenRouter**: API key from https://openrouter.ai.
- **Mistral**: API key from https://console.mistral.ai.
- **Groq**: API key from https://console.groq.com.

.. _introduction-credits:

Credits
=======

This extension is developed and maintained by:

**Netresearch DTT GmbH**
   https://www.netresearch.de

Built with the assistance of modern AI development tools and following TYPO3
coding standards and best practices.
