<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Controller\Backend;

use LogicException;
use Netresearch\NrLlm\Controller\Backend\ModelDiscoveryController;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DiscoveredModel;
use Netresearch\NrLlm\Service\SetupWizard\ModelDiscoveryInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * Unit tests for provider-side model discovery.
 *
 * Moved with the actions out of ModelControllerTest; the PSR-7 handlers are
 * driven directly, without Extbase initialization.
 */
#[AllowMockObjectsWithoutExpectations]
final class ModelDiscoveryControllerTest extends TestCase
{
    private ProviderRepository&MockObject $providerRepository;
    private ModelDiscoveryInterface&MockObject $modelDiscovery;
    private ModelDiscoveryController $subject;
    private mixed $previousBeUser;

    protected function setUp(): void
    {
        parent::setUp();

        // The actions are guarded by RequiresBackendAdminTrait (ADR-037);
        // provide an admin so the tests reach the action body.
        $this->previousBeUser = $GLOBALS['BE_USER'] ?? null;
        $backendUser = new BackendUserAuthentication();
        $backendUser->user = ['uid' => 1, 'admin' => 1];
        $GLOBALS['BE_USER'] = $backendUser;

        $this->providerRepository = $this->createMock(ProviderRepository::class);
        $this->modelDiscovery = $this->createMock(ModelDiscoveryInterface::class);

        $reflection = new ReflectionClass(ModelDiscoveryController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $this->setPrivateProperty($controller, 'providerRepository', $this->providerRepository);
        $this->setPrivateProperty($controller, 'modelDiscovery', $this->modelDiscovery);
        $this->setPrivateProperty($controller, 'logger', new NullLogger());
        $this->subject = $controller;
    }

    protected function tearDown(): void
    {
        if ($this->previousBeUser === null) {
            unset($GLOBALS['BE_USER']);
        } else {
            $GLOBALS['BE_USER'] = $this->previousBeUser;
        }
        parent::tearDown();
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new ReflectionClass($object);
        $prop = $reflection->getProperty($property);
        $prop->setValue($object, $value);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function createRequest(array $body): ServerRequest
    {
        return (new ServerRequest('/ajax/test', 'POST'))->withParsedBody($body);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonResponse(ResponseInterface $response): array
    {
        $decoded = json_decode((string)$response->getBody(), true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    #[Test]
    public function fetchAvailableModelsActionReturnsErrorForMissingProviderUid(): void
    {
        $this->providerRepository
            ->expects(self::never())
            ->method('findByUid');

        $request = $this->createRequest([]);
        $response = $this->subject->fetchAvailableModelsAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertIsString($data['error']);
        self::assertStringContainsString('No provider UID', $data['error']);
    }

    #[Test]
    public function fetchAvailableModelsActionReturnsErrorForNonexistentProvider(): void
    {
        $this->providerRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(99999)
            ->willReturn(null);

        $request = $this->createRequest(['providerUid' => 99999]);
        $response = $this->subject->fetchAvailableModelsAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(404, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertIsString($data['error']);
        self::assertStringContainsString('Provider not found', $data['error']);
    }

    #[Test]
    public function fetchAvailableModelsActionReturnsModelsFromProvider(): void
    {
        $provider = $this->createProvider(1);

        $this->providerRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(1)
            ->willReturn($provider);

        $discoveredModels = [
            new DiscoveredModel(
                modelId: 'gpt-4o',
                name: 'GPT-4o',
                description: 'Flagship model',
                capabilities: ['chat', 'vision', 'tools'],
                contextLength: 128000,
                maxOutputTokens: 16384,
            ),
            new DiscoveredModel(
                modelId: 'gpt-4o-mini',
                name: 'GPT-4o Mini',
                description: 'Fast and cheap',
                capabilities: ['chat', 'tools'],
                contextLength: 128000,
                maxOutputTokens: 16384,
            ),
        ];

        $this->modelDiscovery
            ->expects(self::once())
            ->method('discover')
            ->willReturn($discoveredModels);
        $this->modelDiscovery
            ->method('wasLastDiscoveryFromFallback')
            ->willReturn(false);

        $request = $this->createRequest(['providerUid' => 1]);
        $response = $this->subject->fetchAvailableModelsAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['success']);
        self::assertSame('live', $data['source']);
        self::assertIsArray($data['models']);
        self::assertCount(2, $data['models']);
        self::assertIsArray($data['models'][0]);
        self::assertSame('gpt-4o', $data['models'][0]['id']);
        self::assertSame('GPT-4o', $data['models'][0]['name']);
        self::assertSame(128000, $data['models'][0]['contextLength']);
        self::assertIsArray($data['models'][0]['capabilities']);
        self::assertContains('vision', $data['models'][0]['capabilities']);
    }

    #[Test]
    public function fetchAvailableModelsActionMarksFallbackSource(): void
    {
        $provider = $this->createProvider(1);

        $this->providerRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(1)
            ->willReturn($provider);

        $this->modelDiscovery
            ->expects(self::once())
            ->method('discover')
            ->willReturn([
                new DiscoveredModel(modelId: 'gpt-5.5', name: 'GPT-5.5'),
            ]);
        $this->modelDiscovery
            ->method('wasLastDiscoveryFromFallback')
            ->willReturn(true);

        $request = $this->createRequest(['providerUid' => 1]);
        $response = $this->subject->fetchAvailableModelsAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['success']);
        self::assertSame('fallback', $data['source']);
    }

    #[Test]
    public function fetchAvailableModelsActionReturnsErrorOnDiscoveryException(): void
    {
        $provider = $this->createProvider(1);

        $this->providerRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(1)
            ->willReturn($provider);

        $this->modelDiscovery
            ->method('discover')
            ->willThrowException(new LogicException('API unavailable'));

        $request = $this->createRequest(['providerUid' => 1]);
        $response = $this->subject->fetchAvailableModelsAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(500, $response->getStatusCode());
        self::assertFalse($data['success']);
        self::assertIsString($data['error']);
        self::assertStringContainsString('See system log', $data['error']);
    }

    #[Test]
    public function fetchAvailableModelsActionWithNonArrayBodyReturnsError(): void
    {
        $request = (new ServerRequest('/ajax/test', 'POST'))
            // @phpstan-ignore-next-line Intentionally passing invalid type to test error handling
            ->withParsedBody('not an array');

        $response = $this->subject->fetchAvailableModelsAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
    }

    #[Test]
    public function detectLimitsActionReturnsErrorForMissingProviderUid(): void
    {
        $request = $this->createRequest(['modelId' => 'gpt-4o']);
        $response = $this->subject->detectLimitsAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertIsString($data['error']);
        self::assertStringContainsString('No provider UID', $data['error']);
    }

    #[Test]
    public function detectLimitsActionReturnsErrorForMissingModelId(): void
    {
        $request = $this->createRequest(['providerUid' => 1]);
        $response = $this->subject->detectLimitsAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertIsString($data['error']);
        self::assertStringContainsString('No model ID', $data['error']);
    }

    #[Test]
    public function detectLimitsActionReturnsErrorForNonexistentProvider(): void
    {
        $this->providerRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(99999)
            ->willReturn(null);

        $request = $this->createRequest(['providerUid' => 99999, 'modelId' => 'gpt-4o']);
        $response = $this->subject->detectLimitsAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(404, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertIsString($data['error']);
        self::assertStringContainsString('Provider not found', $data['error']);
    }

    #[Test]
    public function detectLimitsActionReturnsModelLimits(): void
    {
        $provider = $this->createProvider(1);

        $this->providerRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(1)
            ->willReturn($provider);

        $discoveredModels = [
            new DiscoveredModel(
                modelId: 'gpt-4o',
                name: 'GPT-4o',
                description: 'Flagship model',
                capabilities: ['chat', 'vision', 'tools'],
                contextLength: 128000,
                maxOutputTokens: 16384,
            ),
        ];

        $this->modelDiscovery
            ->expects(self::once())
            ->method('discover')
            ->willReturn($discoveredModels);

        $request = $this->createRequest(['providerUid' => 1, 'modelId' => 'gpt-4o']);
        $response = $this->subject->detectLimitsAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['success']);
        self::assertSame(128000, $data['contextLength']);
        self::assertSame(16384, $data['maxOutputTokens']);
        self::assertIsArray($data['capabilities']);
        self::assertContains('vision', $data['capabilities']);
    }

    #[Test]
    public function detectLimitsActionReturnsErrorWhenModelNotFound(): void
    {
        $provider = $this->createProvider(1);

        $this->providerRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(1)
            ->willReturn($provider);

        $discoveredModels = [
            new DiscoveredModel(
                modelId: 'gpt-4o-mini',
                name: 'GPT-4o Mini',
            ),
        ];

        $this->modelDiscovery
            ->expects(self::once())
            ->method('discover')
            ->willReturn($discoveredModels);

        $request = $this->createRequest(['providerUid' => 1, 'modelId' => 'gpt-4o']);
        $response = $this->subject->detectLimitsAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(404, $response->getStatusCode());
        self::assertFalse($data['success']);
        self::assertIsString($data['error']);
        self::assertStringContainsString('not found', $data['error']);
    }

    #[Test]
    public function detectLimitsActionReturnsErrorOnDiscoveryException(): void
    {
        $provider = $this->createProvider(1);

        $this->providerRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(1)
            ->willReturn($provider);

        $this->modelDiscovery
            ->method('discover')
            ->willThrowException(new LogicException('API unavailable'));

        $request = $this->createRequest(['providerUid' => 1, 'modelId' => 'gpt-4o']);
        $response = $this->subject->detectLimitsAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(500, $response->getStatusCode());
        self::assertFalse($data['success']);
        self::assertIsString($data['error']);
        self::assertStringContainsString('See system log', $data['error']);
    }

    #[Test]
    public function detectLimitsActionWithNonArrayBodyReturnsError(): void
    {
        $request = (new ServerRequest('/ajax/test', 'POST'))
            // @phpstan-ignore-next-line Intentionally passing invalid type to test error handling
            ->withParsedBody('not an array');

        $response = $this->subject->detectLimitsAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
    }

    private function createProvider(int $uid): Provider
    {
        $provider = new Provider();
        $reflection = new ReflectionClass($provider);
        $uidProperty = $reflection->getProperty('uid');
        $uidProperty->setValue($provider, $uid);
        $provider->setName('Test Provider');
        $provider->setAdapterType('openai');
        $provider->setApiKey('0190a5e0-7a1c-7b2d-8f3e-4a5b6c7d8e9f');
        return $provider;
    }

}
