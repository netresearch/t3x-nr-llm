.. include:: /Includes.rst.txt

.. _developer:

===============
Developer Guide
===============

This guide covers technical details for developers integrating the LLM extension
into their TYPO3 projects.

.. contents::
   :local:
   :depth: 2

.. _core-concepts:

Core Concepts
=============

Architecture Overview
---------------------

The extension follows a layered architecture:

1. **Providers** - Handle direct API communication
2. **LlmServiceManager** - Orchestrates providers and provides unified API
3. **Feature Services** - High-level services for specific tasks
4. **Domain Models** - Response objects and value types

.. code-block:: text

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

Dependency Injection
--------------------

All services are available via dependency injection:

.. code-block:: php

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

.. _using-llm-service-manager:

Using LlmServiceManager
=======================

Basic Chat
----------

.. code-block:: php

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

   // UsageStatistics
   $promptTokens = $usage->promptTokens;
   $completionTokens = $usage->completionTokens;
   $totalTokens = $usage->totalTokens;

Chat with Options
-----------------

.. code-block:: php

   use Netresearch\NrLlm\Service\Option\ChatOptions;

   // Using ChatOptions object
   $options = ChatOptions::creative()
       ->withMaxTokens(2000)
       ->withSystemPrompt('You are a creative writer.');

   $response = $this->llmManager->chat($messages, $options);

   // Or using array
   $response = $this->llmManager->chat($messages, [
       'provider' => 'claude',
       'model' => 'claude-opus-4-20250514',
       'temperature' => 1.2,
       'max_tokens' => 2000,
       'top_p' => 0.9,
       'frequency_penalty' => 0.5,
       'presence_penalty' => 0.5,
   ]);

Simple Completion
-----------------

.. code-block:: php

   // Quick completion from a prompt
   $response = $this->llmManager->complete('Explain recursion in programming');

Embeddings
----------

.. code-block:: php

   // Single text
   $response = $this->llmManager->embed('Hello, world!');
   $vector = $response->getVector(); // array<float>

   // Multiple texts
   $response = $this->llmManager->embed(['Text 1', 'Text 2', 'Text 3']);
   $vectors = $response->embeddings; // array<array<float>>

Streaming
---------

.. code-block:: php

   $stream = $this->llmManager->streamChat($messages);

   foreach ($stream as $chunk) {
       echo $chunk;
       ob_flush();
       flush();
   }

Tool/Function Calling
---------------------

.. code-block:: php

   $tools = [
       [
           'type' => 'function',
           'function' => [
               'name' => 'get_weather',
               'description' => 'Get current weather for a location',
               'parameters' => [
                   'type' => 'object',
                   'properties' => [
                       'location' => [
                           'type' => 'string',
                           'description' => 'City name',
                       ],
                       'unit' => [
                           'type' => 'string',
                           'enum' => ['celsius', 'fahrenheit'],
                       ],
                   ],
                   'required' => ['location'],
               ],
           ],
       ],
   ];

   $response = $this->llmManager->chatWithTools($messages, $tools);

   if ($response->hasToolCalls()) {
       foreach ($response->toolCalls as $toolCall) {
           $functionName = $toolCall['function']['name'];
           $arguments = json_decode($toolCall['function']['arguments'], true);

           // Execute your function
           $result = match ($functionName) {
               'get_weather' => $this->getWeather($arguments['location']),
               default => throw new \RuntimeException("Unknown function: {$functionName}"),
           };

           // Continue conversation with result
           $messages[] = [
               'role' => 'assistant',
               'content' => null,
               'tool_calls' => [$toolCall],
           ];
           $messages[] = [
               'role' => 'tool',
               'tool_call_id' => $toolCall['id'],
               'content' => json_encode($result),
           ];

           $response = $this->llmManager->chat($messages);
       }
   }

.. toctree::
   :maxdepth: 2
   :hidden:

   FeatureServices/Index

.. _response-objects:

Response Objects
================

CompletionResponse
------------------

.. code-block:: php

   namespace Netresearch\NrLlm\Domain\Model;

   final class CompletionResponse
   {
       public readonly string $content;
       public readonly string $model;
       public readonly UsageStatistics $usage;
       public readonly string $finishReason;
       public readonly string $provider;
       public readonly ?array $toolCalls;

       public function isComplete(): bool;      // finished normally
       public function wasTruncated(): bool;    // hit max_tokens
       public function wasFiltered(): bool;     // content filtered
       public function hasToolCalls(): bool;    // has tool calls
       public function getText(): string;       // alias for content
   }

EmbeddingResponse
-----------------

.. code-block:: php

   namespace Netresearch\NrLlm\Domain\Model;

   final class EmbeddingResponse
   {
       /** @var array<int, array<int, float>> */
       public readonly array $embeddings;
       public readonly string $model;
       public readonly UsageStatistics $usage;
       public readonly string $provider;

       public function getVector(): array;   // First embedding
       public static function cosineSimilarity(array $a, array $b): float;
   }

UsageStatistics
---------------

.. code-block:: php

   namespace Netresearch\NrLlm\Domain\Model;

   final readonly class UsageStatistics
   {
       public int $promptTokens;
       public int $completionTokens;
       public int $totalTokens;
       public ?float $estimatedCost;
   }

.. _creating-custom-providers:

Creating Custom Providers
=========================

Implement a custom provider by extending ``AbstractProvider``:

.. code-block:: php

   <?php

   namespace MyVendor\MyExtension\Provider;

   use Netresearch\NrLlm\Provider\AbstractProvider;
   use Netresearch\NrLlm\Provider\Contract\ProviderInterface;

   class MyCustomProvider extends AbstractProvider implements ProviderInterface
   {
       protected string $baseUrl = 'https://api.example.com/v1';

       public function getName(): string
       {
           return 'My Custom Provider';
       }

       public function getIdentifier(): string
       {
           return 'custom';
       }

       public function isConfigured(): bool
       {
           return !empty($this->apiKey);
       }

       public function chatCompletion(array $messages, array $options = []): CompletionResponse
       {
           $payload = $this->buildChatPayload($messages, $options);
           $response = $this->sendRequest('chat', $payload);

           return new CompletionResponse(
               content: $response['choices'][0]['message']['content'],
               model: $response['model'],
               usage: $this->parseUsage($response['usage']),
               finishReason: $response['choices'][0]['finish_reason'],
               provider: $this->getIdentifier(),
           );
       }

       // Implement other required methods...
   }

Register your provider in ``Services.yaml``:

.. code-block:: yaml

   MyVendor\MyExtension\Provider\MyCustomProvider:
     arguments:
       $httpClient: '@Psr\Http\Client\ClientInterface'
       $requestFactory: '@Psr\Http\Message\RequestFactoryInterface'
       $streamFactory: '@Psr\Http\Message\StreamFactoryInterface'
       $logger: '@Psr\Log\LoggerInterface'
     tags:
       - name: nr_llm.provider
         priority: 50

.. _error-handling:

Error Handling
==============

The extension throws specific exceptions:

.. code-block:: php

   use Netresearch\NrLlm\Provider\Exception\ProviderException;
   use Netresearch\NrLlm\Provider\Exception\AuthenticationException;
   use Netresearch\NrLlm\Provider\Exception\RateLimitException;
   use Netresearch\NrLlm\Exception\InvalidArgumentException;

   try {
       $response = $this->llmManager->chat($messages);
   } catch (AuthenticationException $e) {
       // Invalid or missing API key
       $this->logger->error('Authentication failed: ' . $e->getMessage());
   } catch (RateLimitException $e) {
       // Rate limit exceeded
       $retryAfter = $e->getRetryAfter(); // seconds to wait
       $this->logger->warning("Rate limited. Retry after {$retryAfter}s");
   } catch (ProviderException $e) {
       // General provider error
       $this->logger->error('Provider error: ' . $e->getMessage());
   } catch (InvalidArgumentException $e) {
       // Invalid parameters
       $this->logger->error('Invalid argument: ' . $e->getMessage());
   }

.. _events:

Events
======

The extension dispatches PSR-14 events:

.. code-block:: php

   use Netresearch\NrLlm\Event\BeforeRequestEvent;
   use Netresearch\NrLlm\Event\AfterResponseEvent;

   class MyEventListener
   {
       public function beforeRequest(BeforeRequestEvent $event): void
       {
           $messages = $event->getMessages();
           $options = $event->getOptions();
           $provider = $event->getProvider();

           // Modify options
           $event->setOptions(array_merge($options, ['my_option' => 'value']));
       }

       public function afterResponse(AfterResponseEvent $event): void
       {
           $response = $event->getResponse();
           $usage = $response->usage;

           // Log usage, track costs, etc.
       }
   }

Register in ``Services.yaml``:

.. code-block:: yaml

   MyVendor\MyExtension\EventListener\MyEventListener:
     tags:
       - name: event.listener
         identifier: 'myextension/before-request'
         method: 'beforeRequest'
         event: Netresearch\NrLlm\Event\BeforeRequestEvent

.. _best-practices:

Best Practices
==============

1. **Use Feature Services** for common tasks instead of raw ``LlmServiceManager``

2. **Enable Caching** for deterministic operations like embeddings

3. **Handle Errors** gracefully with proper try-catch blocks

4. **Sanitize Input** before sending to LLM providers

5. **Validate Output** and treat LLM responses as untrusted

6. **Use Streaming** for long responses to improve UX

7. **Set Reasonable Timeouts** based on expected response times

8. **Monitor Usage** to control costs and prevent abuse
