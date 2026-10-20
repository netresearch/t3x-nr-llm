.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _testing-functional-testing:

==================
Functional testing
==================

.. _testing-functional:

Running functional tests
========================

.. code-block:: bash
   :caption: Run functional tests

   # Run TYPO3 functional tests
   Build/Scripts/runTests.sh -s functional

   # Alternative: Via Composer script
   composer ci:test:php:functional

With the database configured, unexpected TYPO3 initialization failures and
teardown integrity failures fail the suite. The shared
:file:`Tests/Functional/AbstractFunctionalTestCase.php` skips only when its
database-configuration check finds no configuration; a failed bootstrap is
not a successful test run. Parent teardown runs after setup completes and its
failures remain visible.

The Unit suite checks this behavior using bounded child-PHPUnit executions:
an invalid package fails initialization, a leaked error-reporting setting
fails teardown, a clean lifecycle executes its assertion, and absent database
configuration produces the deliberate skip. The deliberately failing child
fixtures live outside the normal Functional discovery directory. Each child
uses an owned instance identifier through the testing framework's protected
instance seam. Concurrent success and failed-bootstrap children are checked
together; the parent stops any unfinished child and removes only its owned
instance directories, including after a child fails.

.. _testing-functional-example:

Functional test example
=======================

.. code-block:: php
   :caption: Example: Functional test

   <?php

   namespace Netresearch\NrLlm\Tests\Functional\Repository;

   use Netresearch\NrLlm\Domain\Model\Provider;
   use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
   use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

   class ProviderRepositoryTest extends FunctionalTestCase
   {
       protected array $testExtensionsToLoad = [
           'netresearch/nr-vault',
           'netresearch/nr-llm',
       ];

       private ProviderRepository $repository;

       protected function setUp(): void
       {
           parent::setUp();
           $this->repository = $this->get(ProviderRepository::class);
       }

       public function testFindOneByIdentifierReturnsProvider(): void
       {
           $this->importCSVDataSet(__DIR__ . '/Fixtures/providers.csv');

           $provider = $this->repository->findOneByIdentifier('openai-test');

           $this->assertInstanceOf(Provider::class, $provider);
           $this->assertEquals('OpenAI Test', $provider->getName());
       }
   }

.. _testing-fixtures:

Test fixtures
=============

.. _testing-csv-fixtures:

CSV fixtures
------------

.. code-block:: text
   :caption: Tests/Functional/Fixtures/providers.csv

   "tx_nrllm_provider"
   "uid","pid","identifier","name","adapter_type","is_active"
   1,0,"openai-test","OpenAI Test","openai",1

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

   # Run mutation tests via runTests.sh
   Build/Scripts/runTests.sh -s mutation

   # Alternative: Via Composer script
   composer ci:test:php:mutation

.. _testing-mutation-results:

Interpreting results
--------------------

- **MSI (Mutation Score Indicator)**: Percentage of mutations killed.
- **Target**: >60% MSI indicates good test quality.
- **Current**: 58% MSI (459 tests).

.. code-block:: text
   :caption: Mutation testing results

   Mutation Score Indicator (MSI): 58%
   Mutation Code Coverage: 85%
   Covered Code MSI: 68%

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
