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

.. _testing-functional-example:

Functional test example
=======================

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
