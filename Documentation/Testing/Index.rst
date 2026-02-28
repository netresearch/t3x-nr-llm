.. include:: /Includes.rst.txt

.. _testing:

=============
Testing guide
=============

Comprehensive testing guide for the TYPO3 LLM extension.

.. contents::
   :local:
   :depth: 2

.. _testing-overview:

Overview
========

The extension includes a comprehensive test suite:

.. list-table::
   :header-rows: 1
   :widths: 25 15 60

   * - Test Type
     - Count
     - Purpose
   * - Unit tests
     - 384
     - Individual class and method testing.
   * - Integration tests
     - 39
     - Service interaction and provider testing.
   * - E2E tests
     - 11
     - Full workflow testing with real APIs.
   * - Functional tests
     - 39
     - TYPO3 framework integration.
   * - Property tests
     - 25
     - Fuzzy/property-based testing.

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

   # Copy PHPUnit configuration
   cp phpunit.xml.dist phpunit.xml

.. _testing-unit:

Unit tests
----------

.. code-block:: bash
   :caption: Run unit tests

   # Run all unit tests
   composer test:unit

   # Or directly with PHPUnit
   vendor/bin/phpunit -c phpunit.xml --testsuite unit

   # Run specific test class
   vendor/bin/phpunit Tests/Unit/Service/LlmServiceManagerTest.php

   # Run with coverage
   vendor/bin/phpunit --testsuite unit --coverage-html coverage/

.. _testing-integration:

Integration tests
-----------------

.. code-block:: bash
   :caption: Run integration tests

   # Run integration tests (requires mock server or API keys)
   composer test:integration

   # With real API (set environment variables first)
   OPENAI_API_KEY=sk-... vendor/bin/phpunit --testsuite integration

.. _testing-functional:

Functional tests
----------------

.. code-block:: bash
   :caption: Run functional tests

   # Run TYPO3 functional tests
   composer test:functional

   # Requires TYPO3 testing framework
   vendor/bin/phpunit --testsuite functional

.. _testing-all:

All tests
---------

.. code-block:: bash
   :caption: Run complete test suite

   # Run complete test suite
   composer test

   # With coverage report
   composer test:coverage

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

   <?php

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
                   content: 'Hello!',
                   model: 'test-model',
                   usage: new UsageStatistics(10, 5, 15),
                   finishReason: 'stop',
                   provider: 'test'
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

.. _testing-integration-example:

Integration test example
------------------------

.. code-block:: php
   :caption: Example: Integration test

   <?php

   namespace Netresearch\NrLlm\Tests\Integration\Provider;

   use Netresearch\NrLlm\Provider\OpenAiProvider;
   use PHPUnit\Framework\TestCase;

   class OpenAiProviderIntegrationTest extends TestCase
   {
       private ?OpenAiProvider $provider = null;

       protected function setUp(): void
       {
           $apiKey = getenv('OPENAI_API_KEY');
           if (!$apiKey) {
               $this->markTestSkipped('OPENAI_API_KEY not set');
           }

           $this->provider = new OpenAiProvider(
               httpClient: new \GuzzleHttp\Client(),
               requestFactory: new \GuzzleHttp\Psr7\HttpFactory(),
               streamFactory: new \GuzzleHttp\Psr7\HttpFactory(),
               apiKey: $apiKey
           );
       }

       public function testChatCompletionWithRealApi(): void
       {
           $response = $this->provider->chatCompletion([
               ['role' => 'user', 'content' => 'Say "test" and nothing else.'],
           ], [
               'max_tokens' => 10,
           ]);

           $this->assertStringContainsStringIgnoringCase('test', $response->content);
           $this->assertGreaterThan(0, $response->usage->totalTokens);
       }
   }

.. _testing-functional-example:

Functional test example
-----------------------

.. code-block:: php
   :caption: Example: Functional test

   <?php

   namespace Netresearch\NrLlm\Tests\Functional\Repository;

   use Netresearch\NrLlm\Domain\Model\PromptTemplate;
   use Netresearch\NrLlm\Domain\Repository\PromptTemplateRepository;
   use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

   class PromptTemplateRepositoryTest extends FunctionalTestCase
   {
       protected array $testExtensionsToLoad = [
           'netresearch/nr-llm',
       ];

       private PromptTemplateRepository $repository;

       protected function setUp(): void
       {
           parent::setUp();
           $this->repository = $this->get(PromptTemplateRepository::class);
       }

       public function testFindByIdentifierReturnsTemplate(): void
       {
           $this->importCSVDataSet(__DIR__ . '/Fixtures/prompt_templates.csv');

           $template = $this->repository->findByIdentifier('test-template');

           $this->assertInstanceOf(PromptTemplate::class, $template);
           $this->assertEquals('Test Template', $template->getName());
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

   <?php

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

   $mockProvider
       ->method('isConfigured')
       ->willReturn(true);

.. _testing-http-mock:

Using HTTP mock
---------------

.. code-block:: php
   :caption: Example: HTTP mock

   <?php

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

.. _testing-fixtures:

Test fixtures
=============

.. _testing-csv-fixtures:

CSV fixtures
------------

.. code-block:: text
   :caption: Tests/Functional/Fixtures/prompt_templates.csv

   "tx_nrllm_prompt_template"
   "uid","pid","identifier","name","template","variables"
   1,0,"test-template","Test Template","Hello {name}!","name"

.. _testing-json-fixtures:

JSON response fixtures
----------------------

.. code-block:: json
   :caption: Tests/Fixtures/openai_chat_response.json

   {
     "id": "chatcmpl-123",
     "object": "chat.completion",
     "created": 1677652288,
     "model": "gpt-5",
     "choices": [
       {
         "index": 0,
         "message": {
           "role": "assistant",
           "content": "Test response"
         },
         "finish_reason": "stop"
       }
     ],
     "usage": {
       "prompt_tokens": 10,
       "completion_tokens": 5,
       "total_tokens": 15
     }
   }

.. _testing-mutation:

Mutation testing
================

The extension uses Infection for mutation testing to ensure test quality.

.. _testing-mutation-running:

Running mutation tests
----------------------

.. code-block:: bash
   :caption: Run mutation tests

   # Install Infection
   composer require --dev infection/infection

   # Run mutation tests
   vendor/bin/infection --threads=4

   # With specific configuration
   vendor/bin/infection -c infection.json.dist

.. _testing-mutation-results:

Interpreting results
--------------------

- **MSI (Mutation Score Indicator)**: Percentage of mutations killed.
- **Target**: >60% MSI indicates good test quality.
- **Current**: 58% MSI (459 tests).

.. code-block:: text

   Mutation Score Indicator (MSI): 58%
   Mutation Code Coverage: 85%
   Covered Code MSI: 68%

.. _testing-ci:

CI/CD integration
=================

.. _testing-ci-github:

GitHub Actions
--------------

.. code-block:: yaml
   :caption: .github/workflows/tests.yml

   name: Tests

   on: [push, pull_request]

   jobs:
     test:
       runs-on: ubuntu-latest

       strategy:
         matrix:
           php: ['8.2', '8.3', '8.4', '8.5']
           typo3: ['13.4', '14.0']

       steps:
         - uses: actions/checkout@v4

         - name: Setup PHP
           uses: shivammathur/setup-php@v2
           with:
             php-version: ${{ matrix.php }}
             coverage: xdebug

         - name: Install dependencies
           run: composer install --prefer-dist

         - name: Run tests
           run: composer test

         - name: Upload coverage
           uses: codecov/codecov-action@v3
           with:
             files: coverage/clover.xml

.. _testing-ci-gitlab:

GitLab CI/CD
------------

.. code-block:: yaml
   :caption: .gitlab-ci.yml

   test:
     image: php:8.2
     script:
       - composer install
       - composer test
     coverage: '/^\s*Lines:\s*\d+.\d+\%/'

.. _testing-best-practices:

Best practices
==============

1. **Isolate tests**: Each test should be independent.
2. **Mock external APIs**: Never call real APIs in unit tests.
3. **Use data providers**: For testing multiple scenarios.
4. **Test edge cases**: Empty inputs, null values, boundaries.
5. **Descriptive names**: Test method names should describe behavior.
6. **Arrange-Act-Assert**: Follow AAA pattern.
7. **Fast tests**: Unit tests should complete in milliseconds.
8. **Coverage goals**: Aim for >80% line coverage.
