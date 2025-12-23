.. include:: /Includes.rst.txt

.. _testing:

=============
Testing Guide
=============

Comprehensive testing guide for the TYPO3 LLM Extension.

.. contents::
   :local:
   :depth: 2

.. _test-overview:

Overview
========

The extension includes a comprehensive test suite:

.. list-table::
   :header-rows: 1
   :widths: 25 15 60

   * - Test Type
     - Count
     - Purpose
   * - Unit Tests
     - 384
     - Individual class and method testing
   * - Integration Tests
     - 39
     - Service interaction and provider testing
   * - E2E Tests
     - 11
     - Full workflow testing with real APIs
   * - Functional Tests
     - 39
     - TYPO3 framework integration
   * - Property Tests
     - 25
     - Fuzzy/property-based testing

.. _running-tests:

Running Tests
=============

Prerequisites
-------------

.. code-block:: bash

   # Install development dependencies
   composer install --dev

   # Copy PHPUnit configuration
   cp phpunit.xml.dist phpunit.xml

Unit Tests
----------

.. code-block:: bash

   # Run all unit tests
   composer test:unit

   # Or directly with PHPUnit
   vendor/bin/phpunit -c phpunit.xml --testsuite unit

   # Run specific test class
   vendor/bin/phpunit Tests/Unit/Service/LlmServiceManagerTest.php

   # Run with coverage
   vendor/bin/phpunit --testsuite unit --coverage-html coverage/

Integration Tests
-----------------

.. code-block:: bash

   # Run integration tests (requires mock server or API keys)
   composer test:integration

   # With real API (set environment variables first)
   OPENAI_API_KEY=sk-... vendor/bin/phpunit --testsuite integration

Functional Tests
----------------

.. code-block:: bash

   # Run TYPO3 functional tests
   composer test:functional

   # Requires TYPO3 testing framework
   vendor/bin/phpunit --testsuite functional

All Tests
---------

.. code-block:: bash

   # Run complete test suite
   composer test

   # With coverage report
   composer test:coverage

.. _test-structure:

Test Structure
==============

.. code-block:: text

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

.. _writing-tests:

Writing Tests
=============

Unit Test Example
-----------------

.. code-block:: php

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

Integration Test Example
------------------------

.. code-block:: php

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

Functional Test Example
-----------------------

.. code-block:: php

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

.. _mocking-providers:

Mocking Providers
=================

Using Mock Provider
-------------------

.. code-block:: php

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

Using HTTP Mock
---------------

.. code-block:: php

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
           'model' => 'gpt-4',
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

.. _test-fixtures:

Test Fixtures
=============

CSV Fixtures
------------

.. code-block:: text
   :caption: Tests/Functional/Fixtures/prompt_templates.csv

   "tx_nrllm_prompt_template"
   "uid","pid","identifier","name","template","variables"
   1,0,"test-template","Test Template","Hello {name}!","name"

JSON Response Fixtures
----------------------

.. code-block:: json
   :caption: Tests/Fixtures/openai_chat_response.json

   {
     "id": "chatcmpl-123",
     "object": "chat.completion",
     "created": 1677652288,
     "model": "gpt-4",
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

.. _mutation-testing:

Mutation Testing
================

The extension uses Infection for mutation testing to ensure test quality.

Running Mutation Tests
----------------------

.. code-block:: bash

   # Install Infection
   composer require --dev infection/infection

   # Run mutation tests
   vendor/bin/infection --threads=4

   # With specific configuration
   vendor/bin/infection -c infection.json.dist

Interpreting Results
--------------------

- **MSI (Mutation Score Indicator)**: Percentage of mutations killed
- **Target**: >60% MSI indicates good test quality
- **Current**: 58% MSI (459 tests)

.. code-block:: text

   Mutation Score Indicator (MSI): 58%
   Mutation Code Coverage: 85%
   Covered Code MSI: 68%

.. _ci-integration:

CI/CD Integration
=================

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
           php: ['8.5']
           typo3: ['14.0']

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

GitLab CI
---------

.. code-block:: yaml
   :caption: .gitlab-ci.yml

   test:
     image: php:8.5
     script:
       - composer install
       - composer test
     coverage: '/^\s*Lines:\s*\d+.\d+\%/'

.. _test-best-practices:

Best Practices
==============

1. **Isolate Tests**: Each test should be independent
2. **Mock External APIs**: Never call real APIs in unit tests
3. **Use Data Providers**: For testing multiple scenarios
4. **Test Edge Cases**: Empty inputs, null values, boundaries
5. **Descriptive Names**: Test method names should describe behavior
6. **Arrange-Act-Assert**: Follow AAA pattern
7. **Fast Tests**: Unit tests should complete in milliseconds
8. **Coverage Goals**: Aim for >80% line coverage
