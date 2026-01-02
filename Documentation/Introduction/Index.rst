.. include:: /Includes.rst.txt

.. _introduction:

============
Introduction
============

.. _what-it-does:

What does it do?
================

The TYPO3 LLM Extension provides a unified abstraction layer for integrating
Large Language Models (LLMs) into TYPO3 applications. It enables developers to:

- **Access multiple AI providers** through a single, consistent API
- **Switch providers transparently** without code changes
- **Leverage specialized services** for common AI tasks
- **Cache responses** to reduce API costs and improve performance
- **Stream responses** for real-time user experiences

Supported Providers
-------------------

.. list-table::
   :header-rows: 1
   :widths: 20 30 50

   * - Provider
     - Models
     - Capabilities
   * - OpenAI
     - GPT-5.x series, o-series reasoning models
     - Chat, Completions, Embeddings, Vision, Streaming, Tools
   * - Anthropic Claude
     - Claude Opus 4.5, Claude Sonnet 4.5, Claude Haiku 4.5
     - Chat, Completions, Vision, Streaming, Tools
   * - Google Gemini
     - Gemini 3 Pro, Gemini 3 Flash, Gemini 2.5 series
     - Chat, Completions, Embeddings, Vision, Streaming, Tools
   * - Ollama
     - Local models (Llama, Mistral, etc.)
     - Chat, Embeddings, Streaming (local)
   * - OpenRouter
     - Multi-provider access
     - Chat, Vision, Streaming, Tools
   * - Mistral
     - Mistral models
     - Chat, Embeddings, Streaming
   * - Groq
     - Fast inference models
     - Chat, Streaming (fast inference)
   * - Azure OpenAI
     - Same as OpenAI
     - Same as OpenAI
   * - Custom
     - OpenAI-compatible endpoints
     - Varies by endpoint

Key Features
============

Unified Provider API
--------------------

All providers implement a common interface, allowing you to:

- Switch between providers with a single configuration change
- Test with different models without modifying application code
- Implement provider fallbacks for increased reliability

.. code-block:: php

   // Use database configurations for consistent settings
   $config = $configRepository->findByIdentifier('blog-summarizer');
   $adapter = $adapterRegistry->createAdapterFromModel($config->getModel());
   $response = $adapter->chatCompletion($messages, $config->toOptions());

   // Or use inline provider selection
   $response = $llmManager->chat($messages, ['provider' => 'openai']);
   $response = $llmManager->chat($messages, ['provider' => 'claude']);

Specialized Feature Services
----------------------------

High-level services for common AI tasks:

**CompletionService**
   Text generation with format control (JSON, Markdown) and creativity presets

**EmbeddingService**
   Text-to-vector conversion with caching and similarity calculations

**VisionService**
   Image analysis with specialized prompts for alt-text, titles, descriptions

**TranslationService**
   Language translation with formality control, domain-specific terminology, and glossaries

**PromptTemplateService**
   Centralized prompt management with variable substitution and versioning

Streaming Support
-----------------

Real-time response streaming for better user experience:

.. code-block:: php

   foreach ($llmManager->streamChat($messages) as $chunk) {
       echo $chunk;
       flush();
   }

Tool/Function Calling
---------------------

Execute custom functions based on AI decisions:

.. code-block:: php

   $response = $llmManager->chatWithTools($messages, $tools);
   if ($response->hasToolCalls()) {
       // Process tool calls
   }

Intelligent Caching
-------------------

- Automatic response caching using TYPO3's caching framework
- Deterministic embedding caching (24-hour default TTL)
- Configurable cache lifetimes per operation type

.. _use-cases:

Use Cases
=========

Content Generation
------------------

- Generate product descriptions
- Create meta descriptions and SEO content
- Draft blog posts and articles
- Summarize long-form content

Translation
-----------

- Translate website content
- Maintain consistent terminology with glossaries
- Preserve formatting in technical documents

Image Processing
----------------

- Generate accessibility-compliant alt-text
- Create SEO-optimized image titles
- Analyze and categorize image content

Search & Discovery
------------------

- Semantic search using embeddings
- Content similarity detection
- Recommendation systems

Chatbots & Assistants
---------------------

- Customer support chatbots
- FAQ answering systems
- Guided navigation assistants

.. _requirements:

Requirements
============

- **PHP**: 8.5 or higher
- **TYPO3**: v14.0 or higher
- **HTTP Client**: PSR-18 compatible (e.g., guzzlehttp/guzzle)

Provider Requirements
---------------------

To use specific providers, you need:

- **OpenAI**: API key from https://platform.openai.com
- **Anthropic Claude**: API key from https://console.anthropic.com
- **Google Gemini**: API key from https://aistudio.google.com
- **Ollama**: Local installation from https://ollama.ai (no API key required)
- **OpenRouter**: API key from https://openrouter.ai
- **Mistral**: API key from https://console.mistral.ai
- **Groq**: API key from https://console.groq.com

.. _credits:

Credits
=======

This extension is developed and maintained by:

**Netresearch DTT GmbH**
   https://www.netresearch.de

Built with the assistance of modern AI development tools and following TYPO3
coding standards and best practices.
