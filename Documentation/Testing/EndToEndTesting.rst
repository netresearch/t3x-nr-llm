.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _testing-e2e:

===========
E2E testing
===========

.. _testing-e2e-overview:

Overview
========

E2E tests verify complete workflows from service entry
point through to response handling. They use mocked HTTP
clients to simulate external API interactions without
requiring real API keys.

Tests are located in :path:`Tests/E2E/` and include:

- **Workflow tests** — full chat completion, embedding,
  and TCA field completion flows
- **Backend module tests** — provider, model,
  configuration, and task management
- **Playwright tests** — browser-based UI tests for
  the backend module

.. _testing-e2e-running:

Running E2E tests
==================

.. code-block:: bash
   :caption: Run E2E tests

   # Workflow tests with mocked HTTP, included in the unit suite
   Build/Scripts/runTests.sh -s unit -- \
       Tests/E2E/ChatCompletionWorkflowTest.php \
       Tests/E2E/EmbeddingWorkflowTest.php

   # Backend workflows and TCA contracts use the functional configuration
   Build/Scripts/runTests.sh -s functional -d sqlite -- \
       --testsuite=e2e-backend,e2e-tca

   # Playwright browser E2E tests
   TYPO3_BASE_URL=https://your-test-instance.example \
       Build/Scripts/runTests.sh -s e2e

.. _testing-e2e-example:

E2E test example
================

.. code-block:: php
   :caption: Example: E2E workflow test

   namespace Netresearch\NrLlm\Tests\E2E;

   use Netresearch\NrLlm\Domain\Model\CompletionResponse;
   use Netresearch\NrLlm\Provider\OpenAiProvider;
   use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
   use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
   use Netresearch\NrLlm\Service\CacheManagerInterface;
   use Netresearch\NrLlm\Service\Feature\CompletionService;
   use Netresearch\NrLlm\Service\Option\ChatOptions;
   use Netresearch\NrLlm\Tests\LlmServiceManagerTestFactory;
   use Psr\Log\NullLogger;
   use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

   class ChatWorkflowTest extends AbstractE2ETestCase
   {
       use LlmServiceManagerTestFactory;

       public function testCompleteWorkflow(): void
       {
           $responseData = $this->createOpenAiChatResponse(
               content: 'Hello!',
               model: 'gpt-4o',
           );
           $httpClient = $this->createMockHttpClient([
               $this->createJsonResponse($responseData),
           ]);

           $provider = new OpenAiProvider(
               $this->requestFactory,
               $this->streamFactory,
               $this->logger,
               $this->createVaultServiceMock(),
               $this->createSecureHttpClientFactoryMock(),
           );

           $extConfig = self::createStub(
               ExtensionConfiguration::class
           );
           $extConfig->method('get')->willReturn([
               'providers' => ['openai' => ['apiKeyIdentifier' => 'sk-test']],
           ]);

           $registry = self::createStub(
               ProviderAdapterRegistryInterface::class
           );
           $manager = $this->createLlmServiceManager(
               $extConfig,
               new NullLogger(),
               $registry,
               new MiddlewarePipeline([]),
               self::createStub(CacheManagerInterface::class),
           );
           $manager->registerProvider($provider);
           $provider->setHttpClient($httpClient);

           $service = new CompletionService($manager);
           $result = $service->complete(
               'Hello!',
               new ChatOptions(provider: 'openai'),
           );

           self::assertInstanceOf(
               CompletionResponse::class,
               $result,
           );
           self::assertSame(
               'Hello!',
               $result->content,
           );
       }
   }
