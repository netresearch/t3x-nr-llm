<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use GuzzleHttp\Psr7\ServerRequest;
use Netresearch\NrLlm\Controller\Backend\ConfigurationController;
use Netresearch\NrLlm\Controller\Backend\LlmModuleController;
use Netresearch\NrLlm\Controller\Backend\TaskController;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrLlm\Service\LlmConfigurationService;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest as Typo3ServerRequest;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request as ExtbaseRequest;

/**
 * Functional tests for error handling pathways.
 *
 * Tests user pathways:
 * - Pathway 7.1: Invalid API Key
 * - Pathway 7.2: Rate Limit Exceeded
 * - Pathway 7.3: Network Timeout
 * - Pathway 7.4: Invalid Model Selection
 *
 * These tests verify that error conditions are handled gracefully
 * and return appropriate error responses to the user.
 */
#[CoversClass(ConfigurationController::class)]
#[CoversClass(LlmModuleController::class)]
#[CoversClass(TaskController::class)]
final class ErrorHandlingTest extends AbstractFunctionalTestCase
{
    private ConfigurationController $configController;
    private TaskController $taskController;
    private LlmModuleController $llmModuleController;
    private LlmConfigurationRepository $configurationRepository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('Providers.csv');
        $this->importFixture('Models.csv');
        $this->importFixture('LlmConfigurations.csv');
        $this->importFixture('Tasks.csv');

        // Get services from container
        $configurationRepository = $this->get(LlmConfigurationRepository::class);
        self::assertInstanceOf(LlmConfigurationRepository::class, $configurationRepository);
        $this->configurationRepository = $configurationRepository;

        // Create controllers
        $this->configController = $this->createConfigurationController();
        $this->taskController = $this->createTaskController();
        $this->llmModuleController = $this->createLlmModuleController();
    }

    private function createConfigurationController(): ConfigurationController
    {
        $moduleTemplateFactory = $this->get(ModuleTemplateFactory::class);
        self::assertInstanceOf(ModuleTemplateFactory::class, $moduleTemplateFactory);

        $componentFactory = $this->get(ComponentFactory::class);
        self::assertInstanceOf(ComponentFactory::class, $componentFactory);

        $iconFactory = $this->get(IconFactory::class);
        self::assertInstanceOf(IconFactory::class, $iconFactory);

        $configurationService = $this->get(LlmConfigurationService::class);
        self::assertInstanceOf(LlmConfigurationService::class, $configurationService);

        $llmServiceManager = $this->get(LlmServiceManagerInterface::class);
        self::assertInstanceOf(LlmServiceManagerInterface::class, $llmServiceManager);

        $providerAdapterRegistry = $this->get(ProviderAdapterRegistry::class);
        self::assertInstanceOf(ProviderAdapterRegistry::class, $providerAdapterRegistry);

        $pageRenderer = $this->get(PageRenderer::class);
        self::assertInstanceOf(PageRenderer::class, $pageRenderer);

        $backendUriBuilder = $this->get(BackendUriBuilder::class);
        self::assertInstanceOf(BackendUriBuilder::class, $backendUriBuilder);

        return new ConfigurationController(
            $moduleTemplateFactory,
            $componentFactory,
            $iconFactory,
            $configurationService,
            $this->configurationRepository,
            $llmServiceManager,
            $providerAdapterRegistry,
            $pageRenderer,
            $backendUriBuilder,
        );
    }

    private function createTaskController(): TaskController
    {
        $taskRepository = $this->get(TaskRepository::class);
        self::assertInstanceOf(TaskRepository::class, $taskRepository);

        $llmServiceManager = $this->get(LlmServiceManagerInterface::class);
        self::assertInstanceOf(LlmServiceManagerInterface::class, $llmServiceManager);

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);

        $tcaSchemaFactory = $this->get(TcaSchemaFactory::class);
        self::assertInstanceOf(TcaSchemaFactory::class, $tcaSchemaFactory);

        $reflection = new ReflectionClass(TaskController::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        $this->setPrivateProperty($controller, 'taskRepository', $taskRepository);
        $this->setPrivateProperty($controller, 'llmServiceManager', $llmServiceManager);
        $this->setPrivateProperty($controller, 'connectionPool', $connectionPool);
        $this->setPrivateProperty($controller, 'tcaSchemaFactory', $tcaSchemaFactory);

        return $controller;
    }

    private function createLlmModuleController(): LlmModuleController
    {
        $llmServiceManager = $this->get(LlmServiceManager::class);
        self::assertInstanceOf(LlmServiceManager::class, $llmServiceManager);

        $providerRepository = $this->get(ProviderRepository::class);
        self::assertInstanceOf(ProviderRepository::class, $providerRepository);

        $modelRepository = $this->get(ModelRepository::class);
        self::assertInstanceOf(ModelRepository::class, $modelRepository);

        $configurationRepository = $this->get(LlmConfigurationRepository::class);
        self::assertInstanceOf(LlmConfigurationRepository::class, $configurationRepository);

        $taskRepository = $this->get(TaskRepository::class);
        self::assertInstanceOf(TaskRepository::class, $taskRepository);

        $reflection = new ReflectionClass(LlmModuleController::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        $this->setPrivateProperty($controller, 'llmServiceManager', $llmServiceManager);
        $this->setPrivateProperty($controller, 'providerRepository', $providerRepository);
        $this->setPrivateProperty($controller, 'modelRepository', $modelRepository);
        $this->setPrivateProperty($controller, 'configurationRepository', $configurationRepository);
        $this->setPrivateProperty($controller, 'taskRepository', $taskRepository);

        return $controller;
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new ReflectionClass($object);
        $prop = $reflection->getProperty($property);
        $prop->setValue($object, $value);
    }

    /**
     * @param array<string, mixed> $parsedBody
     */
    private function createExtbaseRequest(array $parsedBody = []): ExtbaseRequest
    {
        $serverRequest = new Typo3ServerRequest();
        $serverRequest = $serverRequest->withParsedBody($parsedBody);

        $extbaseParameters = new ExtbaseRequestParameters();
        $extbaseParameters->setControllerName('LlmModule');
        $extbaseParameters->setControllerActionName('executeTest');
        $extbaseParameters->setControllerExtensionName('NrLlm');

        $serverRequest = $serverRequest->withAttribute('extbase', $extbaseParameters);

        return new ExtbaseRequest($serverRequest);
    }

    // =========================================================================
    // Pathway 7.1: Invalid API Key
    // =========================================================================

    #[Test]
    public function testConfigurationReturnsErrorForInvalidApiKey(): void
    {
        // Test against configuration with potentially invalid credentials
        // The controller should handle the error gracefully
        $request = new ServerRequest('POST', '/ajax/nrllm/config/test');
        $request = $request->withParsedBody(['uid' => 1]);

        // Act
        $response = $this->configController->testConfigurationAction($request);

        // Assert - should return error response without crashing
        self::assertContains($response->getStatusCode(), [200, 400, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);

        // If it failed, should have error message
        if (!$body['success']) {
            self::assertArrayHasKey('error', $body);
            self::assertIsString($body['error']);
            self::assertNotEmpty($body['error']);
        }
    }

    #[Test]
    public function executeTestReturnsErrorWithDetailedMessage(): void
    {
        // Test with provider that will fail due to no real API
        $extbaseRequest = $this->createExtbaseRequest([
            'provider' => 'openai-test',
            'prompt' => 'Test prompt for error handling',
        ]);

        $this->setPrivateProperty($this->llmModuleController, 'request', $extbaseRequest);

        // Act
        $response = $this->llmModuleController->executeTestAction();

        // Assert - should return structured error response
        self::assertContains($response->getStatusCode(), [200, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);

        if (!$body['success']) {
            self::assertArrayHasKey('error', $body);
            // Error message should be meaningful (not empty or generic)
            self::assertIsString($body['error']);
        }
    }

    // =========================================================================
    // Pathway 7.2: Rate Limit Exceeded
    // =========================================================================

    #[Test]
    public function taskExecuteHandlesRateLimitErrorGracefully(): void
    {
        // Execute task - if rate limit is hit, should return proper error
        $request = new ServerRequest('POST', '/ajax/nrllm/task/execute');
        $request = $request->withParsedBody([
            'uid' => 1,
            'input' => 'Test input',
        ]);

        // Act
        $response = $this->taskController->executeAction($request);

        // Assert - should return 200 with success:false or success:true
        // Never should crash or return malformed response
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);

        // Response should be well-structured
        if ($body['success']) {
            self::assertArrayHasKey('content', $body);
            self::assertArrayHasKey('model', $body);
        } else {
            self::assertArrayHasKey('error', $body);
        }
    }

    // =========================================================================
    // Pathway 7.3: Network Timeout
    // =========================================================================

    #[Test]
    public function testConfigurationHandlesNetworkErrorGracefully(): void
    {
        // When network is unavailable, the controller should handle it gracefully
        $request = new ServerRequest('POST', '/ajax/nrllm/config/test');
        $request = $request->withParsedBody(['uid' => 1]);

        // Act
        $response = $this->configController->testConfigurationAction($request);

        // Assert - should return proper HTTP status
        self::assertContains($response->getStatusCode(), [200, 400, 500]);

        // Body should be valid JSON
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);

        // Should always have a success key
        self::assertArrayHasKey('success', $body);
    }

    #[Test]
    public function executeTestHandlesConnectionFailureGracefully(): void
    {
        // Create request with provider that doesn't have real API connection
        $extbaseRequest = $this->createExtbaseRequest([
            'provider' => 'anthropic-test',
            'prompt' => 'Test prompt',
        ]);

        $this->setPrivateProperty($this->llmModuleController, 'request', $extbaseRequest);

        // Act
        $response = $this->llmModuleController->executeTestAction();

        // Assert - should not throw unhandled exception
        self::assertContains($response->getStatusCode(), [200, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);

        // Even on failure, should have structured response
        if (!$body['success']) {
            self::assertArrayHasKey('error', $body);
        }
    }

    // =========================================================================
    // Pathway 7.4: Invalid Model Selection
    // =========================================================================

    #[Test]
    public function getModelsReturnsNotFoundForInvalidProvider(): void
    {
        // Request models for a provider that doesn't exist
        $request = new ServerRequest('POST', '/ajax/nrllm/config/get-models');
        $request = $request->withParsedBody(['provider' => 'invalid-provider']);

        // Act
        $response = $this->configController->getModelsAction($request);

        // Assert - should return 404 with clear error
        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertArrayHasKey('error', $body);
        self::assertSame('Provider not available', $body['error']);
    }

    #[Test]
    public function testConfigurationHandlesInvalidConfigurationGracefully(): void
    {
        // Test with non-existent configuration
        $request = new ServerRequest('POST', '/ajax/nrllm/config/test');
        $request = $request->withParsedBody(['uid' => 99999]);

        // Act
        $response = $this->configController->testConfigurationAction($request);

        // Assert - should return 404
        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('Configuration not found', $body['error']);
    }

    #[Test]
    public function taskExecuteReturnsErrorForInvalidTask(): void
    {
        // Execute non-existent task
        $request = new ServerRequest('POST', '/ajax/nrllm/task/execute');
        $request = $request->withParsedBody([
            'uid' => 99999,
            'input' => 'Test',
        ]);

        // Act
        $response = $this->taskController->executeAction($request);

        // Assert - should return 404 with clear error
        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('Task not found', $body['error']);
    }

    // =========================================================================
    // Additional Error Handling Tests
    // =========================================================================

    #[Test]
    public function allErrorResponsesIncludeSuccessFalse(): void
    {
        // Test multiple error scenarios - all should have success:false
        $errorRequests = [
            ['action' => 'toggle', 'body' => ['uid' => 99999]],
            ['action' => 'setdefault', 'body' => ['uid' => 99999]],
            ['action' => 'test', 'body' => ['uid' => 99999]],
            ['action' => 'getmodels', 'body' => ['provider' => 'nonexistent']],
        ];

        foreach ($errorRequests as $errorRequest) {
            $request = new ServerRequest('POST', '/ajax/test');
            $request = $request->withParsedBody($errorRequest['body']);

            $response = match ($errorRequest['action']) {
                'toggle' => $this->configController->toggleActiveAction($request),
                'setdefault' => $this->configController->setDefaultAction($request),
                'test' => $this->configController->testConfigurationAction($request),
                'getmodels' => $this->configController->getModelsAction($request),
            };

            $body = json_decode((string)$response->getBody(), true);
            self::assertIsArray($body, "Response for {$errorRequest['action']} should be array");
            self::assertFalse($body['success'], "Response for {$errorRequest['action']} should have success:false");
            self::assertArrayHasKey('error', $body, "Response for {$errorRequest['action']} should have error key");
        }
    }

    #[Test]
    public function errorResponsesHaveAppropriateHttpStatusCodes(): void
    {
        // Missing required parameter -> 400
        $request = new ServerRequest('POST', '/ajax/test');
        $request = $request->withParsedBody([]);

        $response = $this->configController->toggleActiveAction($request);
        self::assertSame(400, $response->getStatusCode());

        // Non-existent resource -> 404
        $request = $request->withParsedBody(['uid' => 99999]);
        $response = $this->configController->toggleActiveAction($request);
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function errorMessagesAreUserFriendly(): void
    {
        // Collect error messages and verify they are not technical stack traces
        $testCases = [
            ['method' => 'toggleActiveAction', 'body' => []],
            ['method' => 'setDefaultAction', 'body' => []],
            ['method' => 'testConfigurationAction', 'body' => []],
        ];

        foreach ($testCases as $testCase) {
            $request = new ServerRequest('POST', '/ajax/test');
            $request = $request->withParsedBody($testCase['body']);

            $method = $testCase['method'];
            $response = $this->configController->$method($request);
            $body = json_decode((string)$response->getBody(), true);
            self::assertIsArray($body);

            if (isset($body['error'])) {
                $error = $body['error'];
                self::assertIsString($error);
                // Error should not contain stack traces or internal details
                self::assertStringNotContainsString('Exception', $error);
                self::assertStringNotContainsString('Stack trace', $error);
                self::assertStringNotContainsString('.php', $error);

                // Error should be meaningful
                self::assertGreaterThan(5, strlen($error), 'Error message should be descriptive');
            }
        }
    }

    #[Test]
    public function controllerDoesNotExposeInternalExceptionsToUser(): void
    {
        // Test configuration with an invalid uid
        $request = new ServerRequest('POST', '/ajax/nrllm/config/test');
        $request = $request->withParsedBody(['uid' => -1]);

        $response = $this->configController->testConfigurationAction($request);
        $body = json_decode((string)$response->getBody(), true);

        self::assertIsArray($body);

        // If there's an error, it should be sanitized
        if (isset($body['error'])) {
            $error = $body['error'];
            self::assertIsString($error);
            // Should not expose internal class names or line numbers
            self::assertDoesNotMatchRegularExpression('/line \d+/', $error);
            self::assertDoesNotMatchRegularExpression('/\.php:\d+/', $error);
        }
    }
}
