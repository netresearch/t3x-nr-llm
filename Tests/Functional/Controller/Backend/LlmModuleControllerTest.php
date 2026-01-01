<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use GuzzleHttp\Psr7\ServerRequest;
use Netresearch\NrLlm\Controller\Backend\LlmModuleController;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use TYPO3\CMS\Core\Http\ServerRequest as Typo3ServerRequest;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request as ExtbaseRequest;

/**
 * Functional tests for LlmModuleController (Dashboard).
 *
 * Tests user pathways:
 * - Pathway 6.2: Quick Test Completion (executeTestAction)
 *
 * Note: Pathway 6.1 (View Dashboard) requires full Extbase/ModuleTemplate
 * infrastructure which is tested at the integration level.
 *
 * Uses reflection to create controller with only AJAX-required dependencies,
 * bypassing Extbase ActionController initialization that requires request context.
 */
#[CoversClass(LlmModuleController::class)]
final class LlmModuleControllerTest extends AbstractFunctionalTestCase
{
    private LlmModuleController $controller;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('Providers.csv');
        $this->importFixture('Models.csv');
        $this->importFixture('LlmConfigurations.csv');
        $this->importFixture('Tasks.csv');

        // Get real services from container
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

        // Create controller via reflection to inject only required dependencies
        $this->controller = $this->createControllerWithDependencies(
            $llmServiceManager,
            $providerRepository,
            $modelRepository,
            $configurationRepository,
            $taskRepository,
        );
    }

    /**
     * Create controller instance with only the dependencies needed for AJAX actions.
     * Uses reflection to bypass constructor and set only required properties.
     */
    private function createControllerWithDependencies(
        LlmServiceManager $llmServiceManager,
        ProviderRepository $providerRepository,
        ModelRepository $modelRepository,
        LlmConfigurationRepository $configurationRepository,
        TaskRepository $taskRepository,
    ): LlmModuleController {
        $reflection = new ReflectionClass(LlmModuleController::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        // Set only the properties needed for executeTestAction
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
     * Create an Extbase request for actions that need $this->request.
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

    // -------------------------------------------------------------------------
    // Pathway 6.2: Quick Test Completion (executeTestAction)
    // -------------------------------------------------------------------------

    #[Test]
    public function executeTestReturnsErrorForMissingProvider(): void
    {
        // Create request with empty provider
        $extbaseRequest = $this->createExtbaseRequest(['prompt' => 'Hello']);

        // Inject the request into the controller
        $this->setPrivateProperty($this->controller, 'request', $extbaseRequest);

        // Act
        $response = $this->controller->executeTestAction();

        // Assert
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('No provider specified', $body['error']);
    }

    #[Test]
    public function executeTestReturnsErrorForEmptyProvider(): void
    {
        // Create request with empty provider string
        $extbaseRequest = $this->createExtbaseRequest([
            'provider' => '',
            'prompt' => 'Hello',
        ]);

        // Inject the request into the controller
        $this->setPrivateProperty($this->controller, 'request', $extbaseRequest);

        // Act
        $response = $this->controller->executeTestAction();

        // Assert
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('No provider specified', $body['error']);
    }

    #[Test]
    public function executeTestHandlesValidProviderRequest(): void
    {
        // Create request with valid provider
        // Note: Without real API, this will fail with connection error
        // but we verify the controller action flow is correct
        $extbaseRequest = $this->createExtbaseRequest([
            'provider' => 'openai-test',
            'prompt' => 'Hello, please respond with a brief greeting.',
        ]);

        // Inject the request into the controller
        $this->setPrivateProperty($this->controller, 'request', $extbaseRequest);

        // Act
        $response = $this->controller->executeTestAction();

        // Assert - either 200 with success or 500 with error (no real API)
        self::assertContains($response->getStatusCode(), [200, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);

        if ($response->getStatusCode() === 200) {
            self::assertTrue($body['success']);
            self::assertArrayHasKey('content', $body);
            self::assertArrayHasKey('model', $body);
            self::assertArrayHasKey('usage', $body);
        } else {
            self::assertFalse($body['success']);
            self::assertArrayHasKey('error', $body);
        }
    }

    #[Test]
    public function executeTestUsesDefaultPromptWhenNotProvided(): void
    {
        // Create request without prompt - should use default
        $extbaseRequest = $this->createExtbaseRequest([
            'provider' => 'openai-test',
        ]);

        // Inject the request into the controller
        $this->setPrivateProperty($this->controller, 'request', $extbaseRequest);

        // Act
        $response = $this->controller->executeTestAction();

        // Assert - either 200 or 500, but not 400 (bad request)
        self::assertContains($response->getStatusCode(), [200, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        // The action should proceed (not return bad request for missing prompt)
        self::assertNotSame(400, $response->getStatusCode());
    }

    #[Test]
    public function executeTestReturnsUsageStatisticsOnSuccess(): void
    {
        // This test documents the expected response structure
        // With a real API, it would return actual usage stats
        $extbaseRequest = $this->createExtbaseRequest([
            'provider' => 'openai-test',
            'prompt' => 'Say hello',
        ]);

        $this->setPrivateProperty($this->controller, 'request', $extbaseRequest);

        // Act
        $response = $this->controller->executeTestAction();

        // Assert response structure (if successful)
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);

        if ($response->getStatusCode() === 200 && ($body['success'] ?? false)) {
            self::assertArrayHasKey('usage', $body);
            self::assertArrayHasKey('promptTokens', $body['usage']);
            self::assertArrayHasKey('completionTokens', $body['usage']);
            self::assertArrayHasKey('totalTokens', $body['usage']);
        }
    }
}
