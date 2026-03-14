.. include:: /Includes.rst.txt

.. _testing-unit-testing:

============
Unit testing
============

.. _testing-running:

Running tests
=============

.. _testing-prerequisites:

Prerequisites
-------------

.. code-block:: bash
   :caption: Install development dependencies

   # Install development dependencies
   composer install --dev

.. _testing-unit:

Unit tests
----------

.. code-block:: bash
   :caption: Run unit tests

   # Recommended: Use runTests.sh (Docker-based, consistent environment)
   Build/Scripts/runTests.sh -s unit

   # With specific PHP version
   Build/Scripts/runTests.sh -s unit -p 8.3

   # Alternative: Via Composer script
   composer ci:test:php:unit

.. _testing-integration:

Integration tests
-----------------

.. code-block:: bash
   :caption: Run integration tests

   # Run integration tests (requires mock server or API keys)
   composer ci:test:php:integration

   # With real API (set environment variables first)
   OPENAI_API_KEY=sk-... Build/Scripts/runTests.sh -s unit

.. _testing-all:

All tests
---------

.. code-block:: bash
   :caption: Run complete test suite

   # Run all test suites via runTests.sh
   Build/Scripts/runTests.sh -s unit
   Build/Scripts/runTests.sh -s functional

   # Run code quality checks
   Build/Scripts/runTests.sh -s cgl
   Build/Scripts/runTests.sh -s phpstan

.. _testing-structure:

Test structure
==============

.. code-block:: text
   :caption: Test directory structure

   Tests/
   ├── Unit/
   │   ├── Domain/
   │   │   └── Model/
   │   │       ├── CompletionResponseTest.php
   │   │       ├── EmbeddingResponseTest.php
   │   │       └── UsageStatisticsTest.php
   │   ├── Provider/
   │   │   ├── OpenAiProviderTest.php
   │   │   ├── ClaudeProviderTest.php
   │   │   ├── GeminiProviderTest.php
   │   │   └── AbstractProviderTest.php
   │   └── Service/
   │       ├── LlmServiceManagerTest.php
   │       └── Feature/
   │           ├── CompletionServiceTest.php
   │           ├── EmbeddingServiceTest.php
   │           ├── VisionServiceTest.php
   │           └── TranslationServiceTest.php
   ├── Integration/
   │   ├── Provider/
   │   │   └── ProviderIntegrationTest.php
   │   └── Service/
   │       └── ServiceIntegrationTest.php
   ├── Functional/
   │   ├── Controller/
   │   │   └── BackendControllerTest.php
   │   └── Repository/
   │       └── PromptTemplateRepositoryTest.php
   └── E2E/
       └── WorkflowTest.php

.. _testing-writing:

Writing tests
=============

.. _testing-unit-example:

Unit test example
-----------------

.. code-block:: php
   :caption: Example: Unit test

   namespace Netresearch\NrLlm\Tests\Unit\Service;

   use Netresearch\NrLlm\Domain\Model\CompletionResponse;
   use Netresearch\NrLlm\Domain\Model\UsageStatistics;
   use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
   use Netresearch\NrLlm\Service\LlmServiceManager;
   use PHPUnit\Framework\TestCase;

   class LlmServiceManagerTest extends TestCase
   {
       private LlmServiceManager $subject;

       protected function setUp(): void
       {
           parent::setUp();

           $mockProvider = $this->createMock(ProviderInterface::class);
           $mockProvider->method('getIdentifier')->willReturn('test');
           $mockProvider->method('isConfigured')->willReturn(true);

           $this->subject = new LlmServiceManager(
               providers: [$mockProvider],
               defaultProvider: 'test'
           );
       }

       public function testChatReturnsCompletionResponse(): void
       {
           $provider = $this->createMock(ProviderInterface::class);
           $provider->method('chatCompletion')->willReturn(
               new CompletionResponse(
                   content: 'Hello!', model: 'test-model',
                   usage: new UsageStatistics(10, 5, 15),
                   finishReason: 'stop', provider: 'test'
               )
           );
           // ... test implementation
       }

       /**
        * @dataProvider invalidMessagesProvider
        */
       public function testChatThrowsOnInvalidMessages(array $messages): void
       {
           $this->expectException(\InvalidArgumentException::class);
           $this->subject->chat($messages);
       }

       public static function invalidMessagesProvider(): array
       {
           return [
               'empty messages' => [[]],
               'missing role' => [[['content' => 'test']]],
               'missing content' => [[['role' => 'user']]],
               'invalid role' => [[['role' => 'invalid', 'content' => 'test']]],
           ];
       }
   }

.. _testing-mocking:

Mocking providers
=================

.. _testing-mock-provider:

Using mock provider
-------------------

.. code-block:: php
   :caption: Example: Mock provider

   use Netresearch\NrLlm\Domain\Model\CompletionResponse;
   use Netresearch\NrLlm\Domain\Model\UsageStatistics;
   use Netresearch\NrLlm\Provider\Contract\ProviderInterface;

   $mockProvider = $this->createMock(ProviderInterface::class);
   $mockProvider
       ->method('chatCompletion')
       ->willReturn(new CompletionResponse(
           content: 'Mocked response',
           model: 'mock-model',
           usage: new UsageStatistics(100, 50, 150),
           finishReason: 'stop',
           provider: 'mock'
       ));
   $mockProvider->method('isConfigured')->willReturn(true);

.. _testing-http-mock:

Using HTTP mock
---------------

.. code-block:: php
   :caption: Example: HTTP mock

   use GuzzleHttp\Client;
   use GuzzleHttp\Handler\MockHandler;
   use GuzzleHttp\HandlerStack;
   use GuzzleHttp\Psr7\Response;

   $mock = new MockHandler([
       new Response(200, [], json_encode([
           'choices' => [
               [
                   'message' => ['content' => 'Test response'],
                   'finish_reason' => 'stop',
               ],
           ],
           'model' => 'gpt-5',
           'usage' => [
               'prompt_tokens' => 10,
               'completion_tokens' => 5,
               'total_tokens' => 15,
           ],
       ])),
   ]);

   $handlerStack = HandlerStack::create($mock);
   $client = new Client(['handler' => $handlerStack]);

   $provider = new OpenAiProvider(
       httpClient: $client,
       // ...
   );
