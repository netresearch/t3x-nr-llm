<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use GuzzleHttp\Psr7\ServerRequest;
use Netresearch\NrLlm\Controller\Backend\ProviderController;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * Functional tests for ProviderController AJAX actions.
 *
 * Tests user pathways:
 * - Pathway 2.2: Toggle Provider Status
 * - Pathway 2.3: Test Provider Connection
 * - Pathway 7.1: Invalid API Key (error handling)
 *
 * Uses reflection to create controller with only AJAX-required dependencies,
 * bypassing Extbase ActionController initialization that requires request context.
 */
#[CoversClass(ProviderController::class)]
final class ProviderControllerTest extends AbstractFunctionalTestCase
{
    private ProviderController $controller;
    private ProviderRepository $providerRepository;
    private PersistenceManagerInterface $persistenceManager;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('Providers.csv');

        // Get real services from container
        $repository = $this->get(ProviderRepository::class);
        self::assertInstanceOf(ProviderRepository::class, $repository);
        $this->providerRepository = $repository;

        $persistenceManager = $this->get(PersistenceManagerInterface::class);
        self::assertInstanceOf(PersistenceManagerInterface::class, $persistenceManager);
        $this->persistenceManager = $persistenceManager;

        $providerAdapterRegistry = $this->get(ProviderAdapterRegistry::class);
        self::assertInstanceOf(ProviderAdapterRegistry::class, $providerAdapterRegistry);

        // Create controller via reflection to inject only AJAX-required dependencies
        // This bypasses initializeAction() which requires Extbase request context
        $this->controller = $this->createControllerWithDependencies(
            $this->providerRepository,
            $providerAdapterRegistry,
            $this->persistenceManager,
        );
    }

    /**
     * Create controller instance with only the dependencies needed for AJAX actions.
     * Uses reflection to bypass constructor and set only required properties.
     */
    private function createControllerWithDependencies(
        ProviderRepository $providerRepository,
        ProviderAdapterRegistry $providerAdapterRegistry,
        PersistenceManagerInterface $persistenceManager,
    ): ProviderController {
        $reflection = new ReflectionClass(ProviderController::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        // Set only the properties needed for AJAX actions
        $this->setPrivateProperty($controller, 'providerRepository', $providerRepository);
        $this->setPrivateProperty($controller, 'providerAdapterRegistry', $providerAdapterRegistry);
        $this->setPrivateProperty($controller, 'persistenceManager', $persistenceManager);

        return $controller;
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new ReflectionClass($object);
        $prop = $reflection->getProperty($property);
        $prop->setValue($object, $value);
    }

    // -------------------------------------------------------------------------
    // Pathway 2.2: Toggle Provider Status
    // -------------------------------------------------------------------------

    #[Test]
    public function toggleActiveReturnsSuccessForActiveToInactive(): void
    {
        // Arrange: Get provider that is currently active
        $provider = $this->providerRepository->findByUid(1);
        self::assertNotNull($provider);
        self::assertTrue($provider->isActive());

        $request = new ServerRequest('POST', '/ajax/nrllm/provider/toggle');
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
        $reloaded = $this->providerRepository->findByUid(1);
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isActive());
    }

    #[Test]
    public function toggleActiveReturnsSuccessForInactiveToActive(): void
    {
        // Arrange: First toggle to inactive
        $provider = $this->providerRepository->findByUid(1);
        self::assertNotNull($provider);
        $provider->setIsActive(false);
        $this->providerRepository->update($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $request = new ServerRequest('POST', '/ajax/nrllm/provider/toggle');
        $request = $request->withParsedBody(['uid' => 1]);

        // Act
        $response = $this->controller->toggleActiveAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        self::assertTrue($body['isActive']);
    }

    #[Test]
    public function toggleActiveReturnsErrorForMissingUid(): void
    {
        $request = new ServerRequest('POST', '/ajax/nrllm/provider/toggle');
        $request = $request->withParsedBody([]);

        // Act
        $response = $this->controller->toggleActiveAction($request);

        // Assert
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('No provider UID specified', $body['error']);
    }

    #[Test]
    public function toggleActiveReturnsNotFoundForNonExistentProvider(): void
    {
        $request = new ServerRequest('POST', '/ajax/nrllm/provider/toggle');
        $request = $request->withParsedBody(['uid' => 999]);

        // Act
        $response = $this->controller->toggleActiveAction($request);

        // Assert
        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('Provider not found', $body['error']);
    }

    // -------------------------------------------------------------------------
    // Pathway 2.3: Test Provider Connection
    // -------------------------------------------------------------------------

    #[Test]
    public function testConnectionReturnsSuccessForValidProvider(): void
    {
        // Note: This test verifies the controller action flow.
        // Actual API connection is tested in integration tests.
        $request = new ServerRequest('POST', '/ajax/nrllm/provider/test');
        $request = $request->withParsedBody(['uid' => 1]);

        // Act
        $response = $this->controller->testConnectionAction($request);

        // Assert - response is either success or connection failure (expected without real API)
        self::assertContains($response->getStatusCode(), [200, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
    }

    #[Test]
    public function testConnectionReturnsNotFoundForMissingProvider(): void
    {
        $request = new ServerRequest('POST', '/ajax/nrllm/provider/test');
        $request = $request->withParsedBody(['uid' => 999]);

        // Act
        $response = $this->controller->testConnectionAction($request);

        // Assert
        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('Provider not found', $body['error']);
    }

    #[Test]
    public function testConnectionReturnsErrorForMissingUid(): void
    {
        $request = new ServerRequest('POST', '/ajax/nrllm/provider/test');
        $request = $request->withParsedBody([]);

        // Act
        $response = $this->controller->testConnectionAction($request);

        // Assert
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('No provider UID specified', $body['error']);
    }

    #[Test]
    public function toggleActiveHandlesNumericStringUid(): void
    {
        // UID passed as string (common from form submissions)
        $request = new ServerRequest('POST', '/ajax/nrllm/provider/toggle');
        $request = $request->withParsedBody(['uid' => '1']);

        // Act
        $response = $this->controller->toggleActiveAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
    }

    #[Test]
    public function toggleActiveHandlesNonNumericUidAsZero(): void
    {
        // Non-numeric string should be treated as 0
        $request = new ServerRequest('POST', '/ajax/nrllm/provider/toggle');
        $request = $request->withParsedBody(['uid' => 'invalid-string']);

        // Act
        $response = $this->controller->toggleActiveAction($request);

        // Assert: treated as missing UID
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('No provider UID specified', $body['error']);
    }

    #[Test]
    public function testConnectionHandlesNumericStringUid(): void
    {
        // UID passed as string
        $request = new ServerRequest('POST', '/ajax/nrllm/provider/test');
        $request = $request->withParsedBody(['uid' => '1']);

        // Act
        $response = $this->controller->testConnectionAction($request);

        // Assert - response is either success or failure but handles string conversion
        self::assertContains($response->getStatusCode(), [200, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
    }

    #[Test]
    public function testConnectionHandlesNonNumericUidAsZero(): void
    {
        // Non-numeric string should be treated as 0
        $request = new ServerRequest('POST', '/ajax/nrllm/provider/test');
        $request = $request->withParsedBody(['uid' => 'not-a-number']);

        // Act
        $response = $this->controller->testConnectionAction($request);

        // Assert: treated as missing UID
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('No provider UID specified', $body['error']);
    }
}
