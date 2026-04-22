.. include:: /Includes.rst.txt

.. _developer:

===============
Developer guide
===============

This guide covers technical details for developers integrating the LLM extension
into their TYPO3 projects.

.. _developer-core-concepts:

Core concepts
=============

.. _developer-architecture-overview:

Architecture overview
---------------------

The extension follows a layered architecture:

1. **Providers** - Handle direct API communication.
2. :php:`LlmServiceManager` - Orchestrates providers and provides unified API.
3. **Feature services** - High-level services for specific tasks.
4. **Domain models** - Response objects and value types.

.. code-block:: text
   :caption: Architecture overview

   ┌─────────────────────────────────────────┐
   │         Your Application Code           │
   └────────────────┬────────────────────────┘
                    │
   ┌────────────────▼────────────────────────┐
   │         Feature Services                │
   │  (Completion, Embedding, Vision, etc.)  │
   └────────────────┬────────────────────────┘
                    │
   ┌────────────────▼────────────────────────┐
   │         LlmServiceManager               │
   │    (Provider selection & routing)       │
   └────────────────┬────────────────────────┘
                    │
   ┌────────────────▼────────────────────────┐
   │           Providers                     │
   │    (OpenAI, Claude, Gemini, etc.)       │
   └─────────────────────────────────────────┘

.. _developer-dependency-injection:

Dependency injection
--------------------

All services are available via dependency injection:

.. code-block:: php
   :caption: Example: Injecting LLM services

   use Netresearch\NrLlm\Service\LlmServiceManager;
   use Netresearch\NrLlm\Service\Feature\CompletionService;
   use Netresearch\NrLlm\Service\Feature\EmbeddingService;
   use Netresearch\NrLlm\Service\Feature\VisionService;
   use Netresearch\NrLlm\Service\Feature\TranslationService;

   class MyController
   {
       public function __construct(
           private readonly LlmServiceManager $llmManager,
           private readonly CompletionService $completionService,
           private readonly EmbeddingService $embeddingService,
           private readonly VisionService $visionService,
           private readonly TranslationService $translationService,
       ) {}
   }

.. _developer-llm-service-manager:

Using LlmServiceManager
========================

.. _developer-basic-chat:

Basic chat
----------

.. code-block:: php
   :caption: Example: Basic chat request

   $messages = [
       ['role' => 'system', 'content' => 'You are a helpful assistant.'],
       ['role' => 'user', 'content' => 'What is TYPO3?'],
   ];

   $response = $this->llmManager->chat($messages);

   // Response properties
   $content = $response->content;           // string
   $model = $response->model;               // string
   $finishReason = $response->finishReason; // string
   $usage = $response->usage;               // UsageStatistics

.. _developer-chat-options:

Chat with options
-----------------

.. code-block:: php
   :caption: Example: Chat with configuration options

   use Netresearch\NrLlm\Service\Option\ChatOptions;

   // Using ChatOptions object
   $options = ChatOptions::creative()
       ->withMaxTokens(2000)
       ->withSystemPrompt('You are a creative writer.');

   $response = $this->llmManager->chat($messages, $options);

   // Or using array
   $response = $this->llmManager->chat($messages, [
       'provider' => 'claude',
       'model' => 'claude-sonnet-4-6',
       'temperature' => 1.2,
       'max_tokens' => 2000,
   ]);

.. _developer-simple-completion:

Simple completion
-----------------

.. code-block:: php
   :caption: Example: Quick completion from a prompt

   $response = $this->llmManager->complete('Explain recursion in programming');

.. _developer-embeddings:

Embeddings
----------

.. code-block:: php
   :caption: Example: Generating embeddings

   // Single text
   $response = $this->llmManager->embed('Hello, world!');
   $vector = $response->getVector(); // array<float>

   // Multiple texts
   $response = $this->llmManager->embed(['Text 1', 'Text 2', 'Text 3']);
   $vectors = $response->embeddings; // array<array<float>>

.. _developer-response-objects:

Response objects
================

See the :ref:`API reference <api-domain-models>` for the complete response
object documentation. Key classes:

.. _developer-completion-response:

- :php:`CompletionResponse` — content, model, usage, finishReason, toolCalls
- :php:`EmbeddingResponse` — embeddings, model, usage
- :php:`UsageStatistics` — promptTokens, completionTokens, totalTokens

.. _developer-embedding-response:
.. _developer-usage-statistics:

.. _developer-error-handling:

Error handling
==============

The extension throws specific exceptions:

.. code-block:: php
   :caption: Example: Error handling

   use Netresearch\NrLlm\Provider\Exception\ProviderException;
   use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;
   use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
   use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
   use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;
   use Netresearch\NrLlm\Exception\InvalidArgumentException;

   try {
       $response = $this->llmManager->chat($messages);
   } catch (ProviderConfigurationException $e) {
       // Invalid or missing provider configuration
   } catch (ProviderConnectionException $e) {
       // Connection to provider failed
   } catch (ProviderResponseException $e) {
       // Provider returned an error response
   } catch (UnsupportedFeatureException $e) {
       // Requested feature not supported by provider
   } catch (ProviderException $e) {
       // General provider error
   } catch (InvalidArgumentException $e) {
       // Invalid parameters
   }

.. _developer-events:

Events
======

.. note::

   PSR-14 events (``BeforeRequestEvent``, ``AfterResponseEvent``) are planned
   for a future release.

.. _developer-best-practices:

Best practices
==============

1. **Use feature services** for common tasks instead of
   raw :php:`LlmServiceManager`.
2. **Enable caching** for deterministic operations like embeddings.
3. **Handle errors** gracefully with proper try-catch blocks.
4. **Sanitize input** before sending to LLM providers.
5. **Validate output** and treat LLM responses as untrusted.
6. **Use streaming** for long responses to improve UX.
7. **Set reasonable timeouts** based on expected response times.
8. **Monitor usage** to control costs and prevent abuse.

.. toctree::
   :maxdepth: 2
   :hidden:

   Streaming
   ToolCalling
   CustomProviders
   ProviderRegistration
   FallbackChain
   CapabilityPermissions
   IntegrationGuide
   FeatureServices/Index
