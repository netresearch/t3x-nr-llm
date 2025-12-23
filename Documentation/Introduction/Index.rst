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
     - GPT-4o, GPT-4o-mini, GPT-4-turbo, o1-preview
     - Chat, Completions, Embeddings, Vision, Streaming, Tools
   * - Anthropic Claude
     - Claude Opus 4, Claude Sonnet 4, Claude 3.5 Sonnet/Haiku
     - Chat, Completions, Vision, Streaming, Tools
   * - Google Gemini
     - Gemini 2.0 Flash, Gemini 1.5 Pro/Flash
     - Chat, Completions, Embeddings, Vision, Streaming, Tools

Key Features
============

Unified Provider API
--------------------

All providers implement a common interface, allowing you to:

- Switch between providers with a single configuration change
- Test with different models without modifying application code
- Implement provider fallbacks for increased reliability

.. code-block:: php

   // Use any provider through the same interface
   $response = $llmManager->chat($messages, ['provider' => 'openai']);
   $response = $llmManager->chat($messages, ['provider' => 'claude']);
   $response = $llmManager->chat($messages, ['provider' => 'gemini']);

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

.. _credits:

Credits
=======

This extension is developed and maintained by:

**Netresearch DTT GmbH**
   https://www.netresearch.de

Built with the assistance of modern AI development tools and following TYPO3
coding standards and best practices.
