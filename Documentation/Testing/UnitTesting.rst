.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

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

   # Install dependencies (dev deps included by default)
   composer install

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

   # Integration tests use mocked HTTP and need no provider credentials
   Build/Scripts/runTests.sh -s integration

.. _testing-all:

All tests
---------

.. code-block:: bash
   :caption: Run complete test suite

   # Run the PHP test suites and architecture rules
   Build/Scripts/runTests.sh -s unit
   Build/Scripts/runTests.sh -s integration
   Build/Scripts/runTests.sh -s fuzzy
   Build/Scripts/runTests.sh -s architecture
   Build/Scripts/runTests.sh -s functional -d sqlite

   # Run code quality checks
   Build/Scripts/runTests.sh -s cgl -n
   Build/Scripts/runTests.sh -s phpstan
   Build/Scripts/runTests.sh -s rector -n -p 8.2

The functional suite also discovers backend workflow and TCA suites from
:file:`Build/FunctionalTests.xml`. Some functional provider smoke tests make
network requests; inspect their prerequisites before running the whole suite.
Browser workflows require a running TYPO3 test instance; see
:ref:`testing-e2e`. The Python sidecars have separate unittest suites under
:file:`Build/decision` and :file:`Build/reranker`.

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
   │       └── ProviderRepositoryTest.php
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

   namespace Your\Extension\Tests\Unit;

   use Netresearch\NrLlm\Domain\Model\CompletionResponse;
   use Netresearch\NrLlm\Domain\Model\UsageStatistics;
   use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
   use Netresearch\NrLlm\Testing\FakeCompletionService;
   use PHPUnit\Framework\Attributes\Test;
   use PHPUnit\Framework\TestCase;

   final class SummaryService
   {
       public function __construct(
           private readonly CompletionServiceInterface $completion,
       ) {}

       public function summarize(string $text): string
       {
           return $this->completion->complete('Summarize: ' . $text)->content;
       }
   }

   final class SummaryServiceTest extends TestCase
   {
       #[Test]
       public function returnsTheSummaryAndSendsTheInput(): void
       {
           $completion = new FakeCompletionService();
           $completion->responses[] = new CompletionResponse(
               content: 'A short summary.',
               model: 'test-model',
               usage: new UsageStatistics(10, 5, 15),
           );
           $subject = new SummaryService($completion);

           self::assertSame('A short summary.', $subject->summarize('Long text'));
           self::assertSame(
               [['prompt' => 'Summarize: Long text', 'options' => null]],
               $completion->completeCalls,
           );
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

   // On an adapter constructed with its normal dependencies and configured
   // by the manager, replace the transport after registerProvider():
   $provider->setHttpClient($client);
