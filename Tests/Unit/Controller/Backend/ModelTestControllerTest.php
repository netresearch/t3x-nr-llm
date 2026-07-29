<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Controller\Backend;

use LogicException;
use Netresearch\NrLlm\Controller\Backend\ModelTestController;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\TestPromptResolverInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * Unit tests for the model connection/capability probe.
 *
 * Moved with the action out of ModelControllerTest; the PSR-7 handler is
 * driven directly, without Extbase initialization.
 */
#[AllowMockObjectsWithoutExpectations]
final class ModelTestControllerTest extends TestCase
{
    private ModelRepository&MockObject $modelRepository;
    private ProviderAdapterRegistryInterface&MockObject $providerAdapterRegistry;
    private TestPromptResolverInterface&MockObject $testPromptResolver;
    private ModelTestController $subject;
    private mixed $previousBeUser;

    protected function setUp(): void
    {
        parent::setUp();

        // The action is guarded by RequiresBackendAdminTrait (ADR-037);
        // provide an admin so the tests reach the action body.
        $this->previousBeUser = $GLOBALS['BE_USER'] ?? null;
        $backendUser = new BackendUserAuthentication();
        $backendUser->user = ['uid' => 1, 'admin' => 1];
        $GLOBALS['BE_USER'] = $backendUser;

        $this->modelRepository = $this->createMock(ModelRepository::class);
        $this->providerAdapterRegistry = $this->createMock(ProviderAdapterRegistryInterface::class);
        $this->testPromptResolver = $this->createMock(TestPromptResolverInterface::class);
        $this->testPromptResolver->method('resolve')->willReturn('Hello, test prompt');

        $reflection = new ReflectionClass(ModelTestController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $this->setPrivateProperty($controller, 'modelRepository', $this->modelRepository);
        $this->setPrivateProperty($controller, 'providerAdapterRegistry', $this->providerAdapterRegistry);
        $this->setPrivateProperty($controller, 'testPromptResolver', $this->testPromptResolver);
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
    public function testModelActionReturnsErrorForMissingUid(): void
    {
        $this->modelRepository
            ->expects(self::never())
            ->method('findByUid');

        $request = $this->createRequest([]);
        $response = $this->subject->testModelAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertIsString($data['error']);
        self::assertStringContainsString('No model UID', $data['error']);
    }

    #[Test]
    public function testModelActionReturnsErrorForNonexistentModel(): void
    {
        $this->modelRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(99999)
            ->willReturn(null);

        $request = $this->createRequest(['uid' => 99999]);
        $response = $this->subject->testModelAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(404, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertIsString($data['error']);
        self::assertStringContainsString('not found', $data['error']);
    }

    #[Test]
    public function testModelActionReturnsErrorForModelWithoutProvider(): void
    {
        $model = $this->createModel(1, true);
        // Model has no provider set (null)

        $this->modelRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(1)
            ->willReturn($model);

        $request = $this->createRequest(['uid' => 1]);
        $response = $this->subject->testModelAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertIsString($data['error']);
        self::assertStringContainsString('no provider', $data['error']);
    }

    #[Test]
    public function testModelActionReturnsSuccessOnSuccessfulTest(): void
    {
        $model = $this->createModel(1, true);
        $model->setName('GPT-4');
        $model->setModelId('gpt-4');

        $provider = new Provider();
        $providerReflection = new ReflectionClass($provider);
        $providerUidProp = $providerReflection->getProperty('uid');
        $providerUidProp->setValue($provider, 1);
        $provider->setName('OpenAI');
        $provider->setAdapterType('openai');
        $model->setProvider($provider);

        $this->modelRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(1)
            ->willReturn($model);

        $adapter = $this->createMock(ProviderInterface::class);
        $usage = new UsageStatistics(10, 5, 15);
        $completionResponse = new CompletionResponse(
            content: 'OK',
            model: 'gpt-4',
            usage: $usage,
        );

        $adapter
            ->expects(self::once())
            ->method('complete')
            ->willReturn($completionResponse);

        $this->providerAdapterRegistry
            ->expects(self::once())
            ->method('createAdapterFromModel')
            ->with($model)
            ->willReturn($adapter);

        $request = $this->createRequest(['uid' => 1]);
        $response = $this->subject->testModelAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['success']);
        self::assertIsString($data['message']);
        self::assertStringContainsString('GPT-4', $data['message']);
        self::assertStringContainsString('OK', $data['message']);
    }

    #[Test]
    public function testModelActionReturnsErrorOnAdapterException(): void
    {
        $model = $this->createModel(1, true);
        $model->setName('GPT-4');
        $model->setModelId('gpt-4');

        $provider = new Provider();
        $providerReflection = new ReflectionClass($provider);
        $providerUidProp = $providerReflection->getProperty('uid');
        $providerUidProp->setValue($provider, 1);
        $provider->setName('OpenAI');
        $provider->setAdapterType('openai');
        $model->setProvider($provider);

        $this->modelRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(1)
            ->willReturn($model);

        $adapter = $this->createMock(ProviderInterface::class);
        $adapter
            ->method('complete')
            ->willThrowException(new LogicException('API connection failed'));

        $this->providerAdapterRegistry
            ->expects(self::once())
            ->method('createAdapterFromModel')
            ->with($model)
            ->willReturn($adapter);

        $request = $this->createRequest(['uid' => 1]);
        $response = $this->subject->testModelAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($data['success']);
        self::assertIsString($data['message']);
        self::assertStringContainsString('See system log', $data['message']);
    }

    #[Test]
    public function testModelActionWithNonArrayBodyReturnsError(): void
    {
        $request = (new ServerRequest('/ajax/test', 'POST'))
            // @phpstan-ignore-next-line Intentionally passing invalid type to test error handling
            ->withParsedBody('not an array');

        $response = $this->subject->testModelAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
    }

    #[Test]
    public function anImageOnlyModelIsNotSentAChatPrompt(): void
    {
        $provider = new Provider();
        $provider->setName('OpenAI');

        $model = new Model();
        $model->setName('dall-e-3');
        $model->setProvider($provider);
        $model->setCapabilities('image');

        $this->modelRepository->method('findByUid')->willReturn($model);
        // The whole point: no adapter is built, so nothing is sent upstream.
        $this->providerAdapterRegistry->expects(self::never())->method('createAdapterFromModel');

        $body = $this->decodeJsonResponse($this->subject->testModelAction($this->createRequest(['uid' => 7])));

        self::assertFalse($body['success']);
        self::assertIsString($body['message']);
        self::assertStringContainsString('dall-e-3', $body['message']);
        self::assertStringContainsString('Extension Configuration', $body['message']);
    }

    #[Test]
    public function anEmbeddingModelIsProbedWithAnEmbeddingCall(): void
    {
        $provider = new Provider();
        $provider->setName('OpenAI');

        $model = new Model();
        $model->setName('text-embedding-3-small');
        $model->setProvider($provider);
        $model->setCapabilities('embeddings');

        $this->modelRepository->method('findByUid')->willReturn($model);

        $adapter = $this->createMock(ProviderInterface::class);
        $adapter->expects(self::once())->method('embeddings')->willReturn(
            new EmbeddingResponse([[0.1, 0.2, 0.3]], 'text-embedding-3-small', new UsageStatistics(4, 0, 4)),
        );
        $adapter->expects(self::never())->method('complete');
        $this->providerAdapterRegistry->method('createAdapterFromModel')->willReturn($adapter);

        $body = $this->decodeJsonResponse($this->subject->testModelAction($this->createRequest(['uid' => 8])));

        self::assertTrue($body['success']);
        self::assertIsString($body['message']);
        self::assertStringContainsString('3-dimension', $body['message']);
    }

    #[Test]
    public function aModelWithoutDeclaredCapabilitiesKeepsTheChatProbe(): void
    {
        $provider = new Provider();
        $provider->setName('OpenAI');

        $model = new Model();
        $model->setName('unlabelled');
        $model->setProvider($provider);
        // Capabilities are optional in TCA and routinely left empty; refusing
        // to test that case would be a regression.

        $this->modelRepository->method('findByUid')->willReturn($model);

        $adapter = $this->createMock(ProviderInterface::class);
        $adapter->expects(self::once())->method('complete')->willReturn(
            new CompletionResponse('hi', 'unlabelled', new UsageStatistics(3, 1, 4)),
        );
        $this->providerAdapterRegistry->method('createAdapterFromModel')->willReturn($adapter);

        $body = $this->decodeJsonResponse($this->subject->testModelAction($this->createRequest(['uid' => 9])));

        self::assertTrue($body['success']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function chatShapedCapabilityProvider(): array
    {
        return [
            'chat' => ['chat'],
            'completion' => ['completion'],
            'vision' => ['vision'],
            'tools' => ['tools'],
            'streaming' => ['streaming'],
            'json_mode' => ['json_mode'],
            // Audio is a chat-completions modality here — there is no audio
            // service and no separate credential.
            'audio' => ['audio'],
        ];
    }

    #[Test]
    #[DataProvider('chatShapedCapabilityProvider')]
    public function everyChatShapedCapabilityKeepsTheChatProbe(string $capability): void
    {
        $provider = new Provider();
        $provider->setName('OpenAI');

        $model = new Model();
        $model->setName('some-model');
        $model->setProvider($provider);
        $model->setCapabilities($capability);

        $this->modelRepository->method('findByUid')->willReturn($model);

        $adapter = $this->createMock(ProviderInterface::class);
        $adapter->expects(self::once())->method('complete')->willReturn(
            new CompletionResponse('hi', 'some-model', new UsageStatistics(3, 1, 4)),
        );
        $this->providerAdapterRegistry->method('createAdapterFromModel')->willReturn($adapter);

        $body = $this->decodeJsonResponse($this->subject->testModelAction($this->createRequest(['uid' => 11])));

        self::assertTrue($body['success']);
    }

    #[Test]
    public function anEmbeddingProbeThatReturnsNoVectorIsNotASuccess(): void
    {
        $provider = new Provider();
        $provider->setName('OpenAI');

        $model = new Model();
        $model->setName('text-embedding-3-small');
        $model->setProvider($provider);
        $model->setCapabilities('embeddings');

        $this->modelRepository->method('findByUid')->willReturn($model);

        $adapter = $this->createMock(ProviderInterface::class);
        // A 2xx whose body carries no vector: nothing was verified.
        $adapter->method('embeddings')->willReturn(
            new EmbeddingResponse([], 'text-embedding-3-small', new UsageStatistics(4, 0, 4)),
        );
        $this->providerAdapterRegistry->method('createAdapterFromModel')->willReturn($adapter);

        $body = $this->decodeJsonResponse($this->subject->testModelAction($this->createRequest(['uid' => 12])));

        self::assertFalse($body['success']);
    }

    private function createModel(int $uid, bool $isActive, bool $isDefault = false): Model
    {
        $model = new Model();
        $reflection = new ReflectionClass($model);
        $uidProperty = $reflection->getProperty('uid');
        $uidProperty->setValue($model, $uid);
        $model->setIsActive($isActive);
        $model->setIsDefault($isDefault);
        return $model;
    }
}
