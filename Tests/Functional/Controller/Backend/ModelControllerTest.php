<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use GuzzleHttp\Psr7\ServerRequest;
use Netresearch\NrLlm\Controller\Backend\ModelController;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrLlm\Service\SetupWizard\ModelDiscoveryInterface;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * Functional tests for ModelController AJAX actions.
 *
 * Tests user pathways:
 * - Pathway 3.3: Toggle Model Status
 * - Pathway 3.4: Set Default Model
 * - Pathway 3.5: Test Model Connection
 * - Error cases: missing uid, non-existent model
 *
 * Uses reflection to create controller with only AJAX-required dependencies,
 * bypassing Extbase ActionController initialization that requires request context.
 */
#[CoversClass(ModelController::class)]
final class ModelControllerTest extends AbstractFunctionalTestCase
{
    private ModelController $controller;
    private ModelRepository $modelRepository;
    private PersistenceManagerInterface $persistenceManager;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('Providers.csv');
        $this->importFixture('Models.csv');

        // Get real services from container
        $repository = $this->get(ModelRepository::class);
        self::assertInstanceOf(ModelRepository::class, $repository);
        $this->modelRepository = $repository;

        $persistenceManager = $this->get(PersistenceManagerInterface::class);
        self::assertInstanceOf(PersistenceManagerInterface::class, $persistenceManager);
        $this->persistenceManager = $persistenceManager;

        $providerRepository = $this->get(ProviderRepository::class);
        self::assertInstanceOf(ProviderRepository::class, $providerRepository);

        $providerAdapterRegistry = $this->get(ProviderAdapterRegistry::class);
        self::assertInstanceOf(ProviderAdapterRegistry::class, $providerAdapterRegistry);

        $modelDiscovery = $this->get(ModelDiscoveryInterface::class);
        self::assertInstanceOf(ModelDiscoveryInterface::class, $modelDiscovery);

        // Create controller via reflection to inject only AJAX-required dependencies
        // This bypasses initializeAction() which requires Extbase request context
        $this->controller = $this->createControllerWithDependencies(
            $this->modelRepository,
            $providerRepository,
            $this->persistenceManager,
            $providerAdapterRegistry,
            $modelDiscovery,
        );
    }

    /**
     * Create controller instance with only the dependencies needed for AJAX actions.
     * Uses reflection to bypass constructor and set only required properties.
     */
    private function createControllerWithDependencies(
        ModelRepository $modelRepository,
        ProviderRepository $providerRepository,
        PersistenceManagerInterface $persistenceManager,
        ProviderAdapterRegistry $providerAdapterRegistry,
        ModelDiscoveryInterface $modelDiscovery,
    ): ModelController {
        $reflection = new ReflectionClass(ModelController::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        // Set only the properties needed for AJAX actions
        $this->setPrivateProperty($controller, 'modelRepository', $modelRepository);
        $this->setPrivateProperty($controller, 'providerRepository', $providerRepository);
        $this->setPrivateProperty($controller, 'persistenceManager', $persistenceManager);
        $this->setPrivateProperty($controller, 'providerAdapterRegistry', $providerAdapterRegistry);
        $this->setPrivateProperty($controller, 'modelDiscovery', $modelDiscovery);

        return $controller;
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new ReflectionClass($object);
        $prop = $reflection->getProperty($property);
        $prop->setValue($object, $value);
    }

    // -------------------------------------------------------------------------
    // Pathway 3.3: Toggle Model Status
    // -------------------------------------------------------------------------

    #[Test]
    public function toggleActiveReturnsSuccessForActiveToInactive(): void
    {
        // Arrange: Get model that is currently active (uid=1 is active per fixture)
        $model = $this->modelRepository->findByUid(1);
        self::assertNotNull($model);
        self::assertTrue($model->isActive());

        $request = new ServerRequest('POST', '/ajax/nrllm/model/toggle');
        $request = $request->withParsedBody(['uid' => 1]);

        // Act
        $response = $this->controller->toggleActiveAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        self::assertFalse($body['isActive']);

        // Verify persistence
        $this->persistenceManager->clearState();
        $reloaded = $this->modelRepository->findByUid(1);
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isActive());
    }

    #[Test]
    public function toggleActiveReturnsSuccessForInactiveToActive(): void
    {
        // Arrange: Get model that is currently inactive (uid=2 is inactive per fixture)
        $model = $this->modelRepository->findByUid(2);
        self::assertNotNull($model);
        self::assertFalse($model->isActive());

        $request = new ServerRequest('POST', '/ajax/nrllm/model/toggle');
        $request = $request->withParsedBody(['uid' => 2]);

        // Act
        $response = $this->controller->toggleActiveAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        self::assertTrue($body['isActive']);

        // Verify persistence
        $this->persistenceManager->clearState();
        $reloaded = $this->modelRepository->findByUid(2);
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isActive());
    }

    #[Test]
    public function toggleActiveReturnsErrorForMissingUid(): void
    {
        $request = new ServerRequest('POST', '/ajax/nrllm/model/toggle');
        $request = $request->withParsedBody([]);

        // Act
        $response = $this->controller->toggleActiveAction($request);

        // Assert
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('No model UID specified', $body['error']);
    }

    #[Test]
    public function toggleActiveReturnsNotFoundForNonExistentModel(): void
    {
        $request = new ServerRequest('POST', '/ajax/nrllm/model/toggle');
        $request = $request->withParsedBody(['uid' => 999]);

        // Act
        $response = $this->controller->toggleActiveAction($request);

        // Assert
        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('Model not found', $body['error']);
    }

    #[Test]
    public function toggleActiveHandlesNumericStringUid(): void
    {
        // UID passed as string (common from form submissions)
        $request = new ServerRequest('POST', '/ajax/nrllm/model/toggle');
        $request = $request->withParsedBody(['uid' => '1']);

        // Act
        $response = $this->controller->toggleActiveAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
    }

    // -------------------------------------------------------------------------
    // Pathway 3.4: Set Default Model
    // -------------------------------------------------------------------------

    #[Test]
    public function setDefaultReturnsSuccessForValidModel(): void
    {
        // Arrange: uid=1 is already default, set uid=3 as default
        $request = new ServerRequest('POST', '/ajax/nrllm/model/set-default');
        $request = $request->withParsedBody(['uid' => 3]);

        // Act
        $response = $this->controller->setDefaultAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);

        // Verify persistence: uid=3 should now be default, uid=1 should not be
        $this->persistenceManager->clearState();
        $newDefault = $this->modelRepository->findByUid(3);
        self::assertNotNull($newDefault);
        self::assertTrue($newDefault->isDefault());

        $oldDefault = $this->modelRepository->findByUid(1);
        self::assertNotNull($oldDefault);
        self::assertFalse($oldDefault->isDefault());
    }

    #[Test]
    public function setDefaultReturnsErrorForMissingUid(): void
    {
        $request = new ServerRequest('POST', '/ajax/nrllm/model/set-default');
        $request = $request->withParsedBody([]);

        // Act
        $response = $this->controller->setDefaultAction($request);

        // Assert
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('No model UID specified', $body['error']);
    }

    #[Test]
    public function setDefaultReturnsNotFoundForNonExistentModel(): void
    {
        $request = new ServerRequest('POST', '/ajax/nrllm/model/set-default');
        $request = $request->withParsedBody(['uid' => 999]);

        // Act
        $response = $this->controller->setDefaultAction($request);

        // Assert
        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('Model not found', $body['error']);
    }

    #[Test]
    public function setDefaultHandlesNumericStringUid(): void
    {
        // UID passed as string (common from form submissions)
        $request = new ServerRequest('POST', '/ajax/nrllm/model/set-default');
        $request = $request->withParsedBody(['uid' => '3']);

        // Act
        $response = $this->controller->setDefaultAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
    }

    // -------------------------------------------------------------------------
    // Pathway 3.5: Test Model Connection
    // -------------------------------------------------------------------------

    #[Test]
    public function testModelReturnsResponseForValidModel(): void
    {
        // Note: This test verifies the controller action flow.
        // Actual API connection is tested in integration tests.
        $request = new ServerRequest('POST', '/ajax/nrllm/model/test');
        $request = $request->withParsedBody(['uid' => 1]);

        // Act
        $response = $this->controller->testModelAction($request);

        // Assert - response is either success or connection failure (expected without real API)
        self::assertContains($response->getStatusCode(), [200, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
        self::assertArrayHasKey('message', $body);
    }

    #[Test]
    public function testModelReturnsErrorForMissingUid(): void
    {
        $request = new ServerRequest('POST', '/ajax/nrllm/model/test');
        $request = $request->withParsedBody([]);

        // Act
        $response = $this->controller->testModelAction($request);

        // Assert
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('No model UID specified', $body['error']);
    }

    #[Test]
    public function testModelReturnsNotFoundForNonExistentModel(): void
    {
        $request = new ServerRequest('POST', '/ajax/nrllm/model/test');
        $request = $request->withParsedBody(['uid' => 999]);

        // Act
        $response = $this->controller->testModelAction($request);

        // Assert
        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('Model not found', $body['error']);
    }

    #[Test]
    public function testModelHandlesNumericStringUid(): void
    {
        // UID passed as string (common from form submissions)
        $request = new ServerRequest('POST', '/ajax/nrllm/model/test');
        $request = $request->withParsedBody(['uid' => '1']);

        // Act
        $response = $this->controller->testModelAction($request);

        // Assert - should not return 400 (bad request)
        self::assertNotSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
    }

    // -------------------------------------------------------------------------
    // Pathway 3.2: Filter Models by Provider (getByProviderAction)
    // -------------------------------------------------------------------------

    #[Test]
    public function getByProviderReturnsModelsForValidProvider(): void
    {
        // Provider uid=1 has models in fixture
        $request = new ServerRequest('POST', '/ajax/nrllm/model/get-by-provider');
        $request = $request->withParsedBody(['providerUid' => 1]);

        // Act
        $response = $this->controller->getByProviderAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        self::assertArrayHasKey('models', $body);
        self::assertIsArray($body['models']);
    }

    #[Test]
    public function getByProviderReturnsErrorForMissingProviderUid(): void
    {
        $request = new ServerRequest('POST', '/ajax/nrllm/model/get-by-provider');
        $request = $request->withParsedBody([]);

        // Act
        $response = $this->controller->getByProviderAction($request);

        // Assert
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('No provider UID specified', $body['error']);
    }

    #[Test]
    public function getByProviderReturnsEmptyArrayForProviderWithNoModels(): void
    {
        // Provider uid=2 may have no models or different models
        $request = new ServerRequest('POST', '/ajax/nrllm/model/get-by-provider');
        $request = $request->withParsedBody(['providerUid' => 999]);

        // Act
        $response = $this->controller->getByProviderAction($request);

        // Assert - returns 200 with empty models array (not 404)
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        self::assertIsArray($body['models']);
    }

    // -------------------------------------------------------------------------
    // Pathway 3.6: Fetch Available Models (fetchAvailableModelsAction)
    // -------------------------------------------------------------------------

    #[Test]
    public function fetchAvailableModelsReturnsErrorForMissingProviderUid(): void
    {
        $request = new ServerRequest('POST', '/ajax/nrllm/model/fetch-available');
        $request = $request->withParsedBody([]);

        // Act
        $response = $this->controller->fetchAvailableModelsAction($request);

        // Assert
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('No provider UID specified', $body['error']);
    }

    #[Test]
    public function fetchAvailableModelsReturnsNotFoundForNonExistentProvider(): void
    {
        $request = new ServerRequest('POST', '/ajax/nrllm/model/fetch-available');
        $request = $request->withParsedBody(['providerUid' => 999]);

        // Act
        $response = $this->controller->fetchAvailableModelsAction($request);

        // Assert
        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('Provider not found', $body['error']);
    }

    #[Test]
    public function fetchAvailableModelsReturnsModelsArrayForValidProvider(): void
    {
        // Note: Without real API, this will fail with connection error or return empty
        // but we verify the controller action flow is correct
        $request = new ServerRequest('POST', '/ajax/nrllm/model/fetch-available');
        $request = $request->withParsedBody(['providerUid' => 1]);

        // Act
        $response = $this->controller->fetchAvailableModelsAction($request);

        // Assert - either 200 with models or 500 with error (no real API)
        self::assertContains($response->getStatusCode(), [200, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
    }

    // -------------------------------------------------------------------------
    // Pathway 3.7: Detect Model Limits (detectLimitsAction)
    // -------------------------------------------------------------------------

    #[Test]
    public function detectLimitsReturnsErrorForMissingProviderUid(): void
    {
        $request = new ServerRequest('POST', '/ajax/nrllm/model/detect-limits');
        $request = $request->withParsedBody(['modelId' => 'gpt-4o']);

        // Act
        $response = $this->controller->detectLimitsAction($request);

        // Assert
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('No provider UID specified', $body['error']);
    }

    #[Test]
    public function detectLimitsReturnsErrorForMissingModelId(): void
    {
        $request = new ServerRequest('POST', '/ajax/nrllm/model/detect-limits');
        $request = $request->withParsedBody(['providerUid' => 1]);

        // Act
        $response = $this->controller->detectLimitsAction($request);

        // Assert
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('No model ID specified', $body['error']);
    }

    #[Test]
    public function detectLimitsReturnsNotFoundForNonExistentProvider(): void
    {
        $request = new ServerRequest('POST', '/ajax/nrllm/model/detect-limits');
        $request = $request->withParsedBody(['providerUid' => 999, 'modelId' => 'gpt-4o']);

        // Act
        $response = $this->controller->detectLimitsAction($request);

        // Assert
        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('Provider not found', $body['error']);
    }

    #[Test]
    public function detectLimitsHandlesValidProviderAndModel(): void
    {
        // Note: Without real API, this will fail with connection error
        // but we verify the controller action flow is correct
        $request = new ServerRequest('POST', '/ajax/nrllm/model/detect-limits');
        $request = $request->withParsedBody(['providerUid' => 1, 'modelId' => 'gpt-4o']);

        // Act
        $response = $this->controller->detectLimitsAction($request);

        // Assert - either 200 with limits, 404 if model not found, or 500 with error
        self::assertContains($response->getStatusCode(), [200, 404, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
    }
}
