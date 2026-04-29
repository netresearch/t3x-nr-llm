<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\ConfigurationController;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\LlmConfigurationServiceInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\TestPromptResolverInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use ReflectionClass;
use RuntimeException;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * Unit tests for ConfigurationController AJAX actions.
 *
 * Tests the PSR-7 AJAX handlers directly without Extbase initialization.
 * Uses reflection to create controller with only the required dependencies.
 */
#[AllowMockObjectsWithoutExpectations]
final class ConfigurationControllerTest extends TestCase
{
    private LlmConfigurationRepository&MockObject $configurationRepository;
    private LlmConfigurationServiceInterface&MockObject $configurationService;
    private LlmServiceManagerInterface&MockObject $llmServiceManager;
    private ProviderAdapterRegistryInterface&MockObject $providerAdapterRegistry;
    private ModelRepository&MockObject $modelRepository;
    private TestPromptResolverInterface&MockObject $testPromptResolver;
    private ConfigurationController $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurationRepository = $this->createMock(LlmConfigurationRepository::class);
        $this->configurationService = $this->createMock(LlmConfigurationServiceInterface::class);
        $this->llmServiceManager = $this->createMock(LlmServiceManagerInterface::class);
        $this->providerAdapterRegistry = $this->createMock(ProviderAdapterRegistryInterface::class);
        $this->modelRepository = $this->createMock(ModelRepository::class);
        $this->testPromptResolver = $this->createMock(TestPromptResolverInterface::class);
        $this->testPromptResolver->method('resolve')->willReturn('Hello, test prompt');

        // Create controller using reflection to inject only required dependencies
        $this->subject = $this->createControllerWithDependencies();
    }

    /**
     * Create controller instance with only the dependencies needed for AJAX actions.
     * Uses reflection to bypass constructor and set only required properties.
     */
    private function createControllerWithDependencies(): ConfigurationController
    {
        $reflection = new ReflectionClass(ConfigurationController::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        // Set only the properties needed for AJAX actions
        $this->setPrivateProperty($controller, 'configurationRepository', $this->configurationRepository);
        $this->setPrivateProperty($controller, 'configurationService', $this->configurationService);
        $this->setPrivateProperty($controller, 'llmServiceManager', $this->llmServiceManager);
        $this->setPrivateProperty($controller, 'providerAdapterRegistry', $this->providerAdapterRegistry);
        $this->setPrivateProperty($controller, 'modelRepository', $this->modelRepository);
        $this->setPrivateProperty($controller, 'testPromptResolver', $this->testPromptResolver);

        return $controller;
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
        // TYPO3's ServerRequest has ($uri, $method) signature, not ($method, $uri)
        return (new ServerRequest('/ajax/test', 'POST'))
            ->withParsedBody($body);
    }

    /**
     * Decode JSON response body and return as typed array.
     *
     * @return array<string, mixed>
     */
    private function decodeJsonResponse(ResponseInterface $response): array
    {
        $data = json_decode((string)$response->getBody(), true);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    private function createConfiguration(int $uid, bool $isActive, bool $isDefault = false): LlmConfiguration
    {
        $configuration = new LlmConfiguration();
        $reflection = new ReflectionClass($configuration);
        $uidProperty = $reflection->getProperty('uid');
        $uidProperty->setValue($configuration, $uid);
        $configuration->setIsActive($isActive);
        $configuration->setIsDefault($isDefault);
        return $configuration;
    }

    private function createConfigurationWithModel(int $uid, bool $isActive, string $modelId = 'gpt-4o'): LlmConfiguration
    {
        $configuration = $this->createConfiguration($uid, $isActive);

        $provider = new Provider();
        $providerReflection = new ReflectionClass($provider);
        $providerReflection->getProperty('uid')->setValue($provider, 1);
        $provider->setAdapterType('openai');
        $provider->setName('OpenAI');

        $model = new Model();
        $modelReflection = new ReflectionClass($model);
        $modelReflection->getProperty('uid')->setValue($model, 1);
        $model->setModelId($modelId);
        $model->setProvider($provider);

        $configuration->setLlmModel($model);

        return $configuration;
    }

    // toggleActiveAction tests

    #[Test]
    public function toggleActiveActionActivatesInactiveConfiguration(): void
    {
        $configuration = $this->createConfiguration(1, false);

        $this->configurationRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(1)
            ->willReturn($configuration);

        $this->configurationService
            ->expects(self::once())
            ->method('toggleActive')
            ->with($configuration)
            ->willReturnCallback(function (LlmConfiguration $config): void {
                $config->setIsActive(!$config->isActive());
            });

        $request = $this->createRequest(['uid' => 1]);
        $response = $this->subject->toggleActiveAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsBool($data['success']);
        self::assertTrue($data['success']);
        self::assertIsBool($data['isActive']);
        self::assertTrue($data['isActive']);
    }

    #[Test]
    public function toggleActiveActionDeactivatesActiveConfiguration(): void
    {
        $configuration = $this->createConfiguration(1, true);

        $this->configurationRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(1)
            ->willReturn($configuration);

        $this->configurationService
            ->expects(self::once())
            ->method('toggleActive')
            ->with($configuration)
            ->willReturnCallback(function (LlmConfiguration $config): void {
                $config->setIsActive(!$config->isActive());
            });

        $request = $this->createRequest(['uid' => 1]);
        $response = $this->subject->toggleActiveAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsBool($data['success']);
        self::assertTrue($data['success']);
        self::assertIsBool($data['isActive']);
        self::assertFalse($data['isActive']);
    }

    #[Test]
    public function toggleActiveActionReturnsErrorForMissingUid(): void
    {
        $this->configurationRepository
            ->expects(self::never())
            ->method('findByUid');

        $request = $this->createRequest([]);
        $response = $this->subject->toggleActiveAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertIsString($data['error']);
        self::assertStringContainsString('No configuration UID', $data['error']);
    }

    #[Test]
    public function toggleActiveActionReturnsErrorForNonexistentConfiguration(): void
    {
        $this->configurationRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(99999)
            ->willReturn(null);

        $request = $this->createRequest(['uid' => 99999]);
        $response = $this->subject->toggleActiveAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(404, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertIsString($data['error']);
        self::assertStringContainsString('not found', $data['error']);
    }

    #[Test]
    public function toggleActiveActionReturnsErrorOnException(): void
    {
        $configuration = $this->createConfiguration(1, true);

        $this->configurationRepository
            ->method('findByUid')
            ->willReturn($configuration);

        $this->configurationService
            ->method('toggleActive')
            ->willThrowException(new RuntimeException('Database error'));

        $request = $this->createRequest(['uid' => 1]);
        $response = $this->subject->toggleActiveAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(500, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertIsString($data['error']);
        self::assertStringContainsString('Database error', $data['error']);
    }

    // setDefaultAction tests

    #[Test]
    public function setDefaultActionSetsConfigurationAsDefault(): void
    {
        $configuration = $this->createConfiguration(1, true, false);

        $this->configurationRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(1)
            ->willReturn($configuration);

        $this->configurationService
            ->expects(self::once())
            ->method('setAsDefault')
            ->with($configuration);

        $request = $this->createRequest(['uid' => 1]);
        $response = $this->subject->setDefaultAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsBool($data['success']);
        self::assertTrue($data['success']);
    }

    #[Test]
    public function setDefaultActionReturnsErrorForMissingUid(): void
    {
        $this->configurationRepository
            ->expects(self::never())
            ->method('findByUid');

        $request = $this->createRequest([]);
        $response = $this->subject->setDefaultAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertIsString($data['error']);
        self::assertStringContainsString('No configuration UID', $data['error']);
    }

    #[Test]
    public function setDefaultActionReturnsErrorForNonexistentConfiguration(): void
    {
        $this->configurationRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(99999)
            ->willReturn(null);

        $request = $this->createRequest(['uid' => 99999]);
        $response = $this->subject->setDefaultAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(404, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertIsString($data['error']);
        self::assertStringContainsString('not found', $data['error']);
    }

    #[Test]
    public function setDefaultActionReturnsErrorOnException(): void
    {
        $configuration = $this->createConfiguration(1, true);

        $this->configurationRepository
            ->method('findByUid')
            ->willReturn($configuration);

        $this->configurationService
            ->method('setAsDefault')
            ->willThrowException(new RuntimeException('Database error'));

        $request = $this->createRequest(['uid' => 1]);
        $response = $this->subject->setDefaultAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(500, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertIsString($data['error']);
        self::assertStringContainsString('Database error', $data['error']);
    }

    // getModelsAction tests

    #[Test]
    public function getModelsActionReturnsModelsForProvider(): void
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('getAvailableModels')
            ->willReturn(['gpt-4' => 'GPT-4', 'gpt-3.5' => 'GPT-3.5']);
        $provider->method('getDefaultModel')
            ->willReturn('gpt-4');

        $this->llmServiceManager
            ->expects(self::once())
            ->method('getAvailableProviders')
            ->willReturn(['openai' => $provider]);

        $request = $this->createRequest(['provider' => 'openai']);
        $response = $this->subject->getModelsAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsBool($data['success']);
        self::assertTrue($data['success']);
        self::assertArrayHasKey('models', $data);
        self::assertIsString($data['defaultModel']);
        self::assertEquals('gpt-4', $data['defaultModel']);
    }

    #[Test]
    public function getModelsActionReturnsErrorForMissingProvider(): void
    {
        $this->llmServiceManager
            ->expects(self::never())
            ->method('getAvailableProviders');

        $request = $this->createRequest([]);
        $response = $this->subject->getModelsAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertIsString($data['error']);
        self::assertStringContainsString('No provider', $data['error']);
    }

    #[Test]
    public function getModelsActionReturnsErrorForNonexistentProvider(): void
    {
        $this->llmServiceManager
            ->expects(self::once())
            ->method('getAvailableProviders')
            ->willReturn([]);

        $request = $this->createRequest(['provider' => 'nonexistent']);
        $response = $this->subject->getModelsAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(404, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertIsString($data['error']);
        self::assertStringContainsString('not available', $data['error']);
    }

    #[Test]
    public function getModelsActionReturnsErrorOnException(): void
    {
        $this->llmServiceManager
            ->method('getAvailableProviders')
            ->willThrowException(new RuntimeException('Provider error'));

        $request = $this->createRequest(['provider' => 'openai']);
        $response = $this->subject->getModelsAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(500, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertIsString($data['error']);
        self::assertStringContainsString('Provider error', $data['error']);
    }

    // testConfigurationAction tests

    #[Test]
    public function testConfigurationActionReturnsSuccessForValidConfiguration(): void
    {
        $configuration = $this->createConfigurationWithModel(1, true, 'gpt-4');

        $usage = new UsageStatistics(10, 20, 30);
        $completionResponse = new CompletionResponse(
            content: 'Hello! How can I help you?',
            model: 'gpt-4',
            usage: $usage,
        );

        $this->configurationRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(1)
            ->willReturn($configuration);

        $adapter = $this->createMock(ProviderInterface::class);
        $adapter
            ->expects(self::once())
            ->method('complete')
            ->willReturn($completionResponse);

        $this->providerAdapterRegistry
            ->expects(self::once())
            ->method('createAdapterFromModel')
            ->willReturn($adapter);

        $request = $this->createRequest(['uid' => 1]);
        $responseObj = $this->subject->testConfigurationAction($request);

        $data = $this->decodeJsonResponse($responseObj);

        self::assertSame(200, $responseObj->getStatusCode());
        self::assertIsBool($data['success']);
        self::assertTrue($data['success']);
        self::assertIsString($data['content']);
        self::assertEquals('Hello! How can I help you?', $data['content']);
        self::assertIsString($data['model']);
        self::assertEquals('gpt-4', $data['model']);
        self::assertArrayHasKey('usage', $data);
    }

    #[Test]
    public function testConfigurationActionReturnsErrorForMissingUid(): void
    {
        $this->configurationRepository
            ->expects(self::never())
            ->method('findByUid');

        $request = $this->createRequest([]);
        $response = $this->subject->testConfigurationAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertIsString($data['error']);
        self::assertStringContainsString('No configuration UID', $data['error']);
    }

    #[Test]
    public function testConfigurationActionReturnsErrorForNonexistentConfiguration(): void
    {
        $this->configurationRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(99999)
            ->willReturn(null);

        $request = $this->createRequest(['uid' => 99999]);
        $response = $this->subject->testConfigurationAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(404, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertIsString($data['error']);
        self::assertStringContainsString('not found', $data['error']);
    }

    #[Test]
    public function testConfigurationActionReturnsErrorOnException(): void
    {
        $configuration = $this->createConfigurationWithModel(1, true, 'gpt-4');

        $this->configurationRepository
            ->method('findByUid')
            ->willReturn($configuration);

        $adapter = $this->createMock(ProviderInterface::class);
        $adapter
            ->method('complete')
            ->willThrowException(new RuntimeException('API error'));

        $this->providerAdapterRegistry
            ->method('createAdapterFromModel')
            ->willReturn($adapter);

        $request = $this->createRequest(['uid' => 1]);
        $response = $this->subject->testConfigurationAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(500, $response->getStatusCode());
        self::assertIsBool($data['success']);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('error', $data);
        self::assertIsString($data['error']);
        self::assertStringContainsString('API error', $data['error']);
    }

    #[Test]
    public function testConfigurationActionReturnsErrorWhenNoModelAssigned(): void
    {
        // Create configuration without a model
        $configuration = $this->createConfiguration(1, true);

        $this->configurationRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(1)
            ->willReturn($configuration);

        $request = $this->createRequest(['uid' => 1]);
        $response = $this->subject->testConfigurationAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertIsString($data['error']);
        self::assertStringContainsString('no model assigned', $data['error']);
    }

    #[Test]
    public function toggleActiveActionWithNonArrayBodyReturnsError(): void
    {
        $request = (new ServerRequest('/ajax/test', 'POST'))
            // @phpstan-ignore-next-line Intentionally passing invalid type to test error handling
            ->withParsedBody('not an array');

        $response = $this->subject->toggleActiveAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
    }

    #[Test]
    public function toggleActiveActionWithNonNumericUidReturnsError(): void
    {
        $request = $this->createRequest(['uid' => 'not-a-number']);

        $response = $this->subject->toggleActiveAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
    }

    #[Test]
    public function setDefaultActionWithNonArrayBodyReturnsError(): void
    {
        $request = (new ServerRequest('/ajax/test', 'POST'))
            // @phpstan-ignore-next-line Intentionally passing invalid type to test error handling
            ->withParsedBody('not an array');

        $response = $this->subject->setDefaultAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
    }

    #[Test]
    public function getModelsActionWithNonArrayBodyReturnsError(): void
    {
        $request = (new ServerRequest('/ajax/test', 'POST'))
            // @phpstan-ignore-next-line Intentionally passing invalid type to test error handling
            ->withParsedBody('not an array');

        $response = $this->subject->getModelsAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
    }

    #[Test]
    public function testConfigurationActionWithNonArrayBodyReturnsError(): void
    {
        $request = (new ServerRequest('/ajax/test', 'POST'))
            // @phpstan-ignore-next-line Intentionally passing invalid type to test error handling
            ->withParsedBody('not an array');

        $response = $this->subject->testConfigurationAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
    }

    #[Test]
    public function getModelsActionWithNumericProviderKeyWorks(): void
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('getAvailableModels')
            ->willReturn(['gpt-4' => 'GPT-4']);
        $provider->method('getDefaultModel')
            ->willReturn('gpt-4');

        $this->llmServiceManager
            ->expects(self::once())
            ->method('getAvailableProviders')
            ->willReturn(['123' => $provider]);

        // Provider key sent as numeric value (from form)
        $request = $this->createRequest(['provider' => 123]);
        $response = $this->subject->getModelsAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['success']);
    }

    // Constraint method tests

    /**
     * Invoke a private method on the controller via reflection.
     *
     * @return array<string, array<string, mixed>>
     */
    private function invokeConstraintMethod(string $methodName): array
    {
        $reflection = new ReflectionClass(ConfigurationController::class);
        $method = $reflection->getMethod($methodName);
        $result = $method->invoke($this->subject);
        self::assertIsArray($result);

        /** @var array<string, array<string, mixed>> $result */
        return $result;
    }

    /**
     * Assert that a constraints array has the expected parameter keys and structure.
     *
     * @param array<string, mixed> $constraints
     */
    private function assertConstraintShape(array $constraints): void
    {
        $expectedKeys = ['temperature', 'top_p', 'frequency_penalty', 'presence_penalty'];
        foreach ($expectedKeys as $key) {
            self::assertArrayHasKey($key, $constraints, "Missing constraint key: {$key}");
            self::assertIsArray($constraints[$key]);
            self::assertArrayHasKey('supported', $constraints[$key], "Missing 'supported' in {$key}");
            self::assertIsBool($constraints[$key]['supported']);
        }
    }

    #[Test]
    public function testGetOpenAIChatConstraintsReturnsExpectedShape(): void
    {
        $constraints = $this->invokeConstraintMethod('getOpenAIChatConstraints');

        $this->assertConstraintShape($constraints);

        // OpenAI chat: all supported with standard ranges
        self::assertTrue($constraints['temperature']['supported']);
        self::assertSame(0.0, $constraints['temperature']['min']);
        self::assertEquals(2.0, $constraints['temperature']['max']);

        self::assertTrue($constraints['top_p']['supported']);
        self::assertSame(0.0, $constraints['top_p']['min']);
        self::assertSame(1.0, $constraints['top_p']['max']);

        self::assertTrue($constraints['frequency_penalty']['supported']);
        self::assertSame(-2.0, $constraints['frequency_penalty']['min']);
        self::assertSame(2.0, $constraints['frequency_penalty']['max']);

        self::assertTrue($constraints['presence_penalty']['supported']);
        self::assertSame(-2.0, $constraints['presence_penalty']['min']);
        self::assertSame(2.0, $constraints['presence_penalty']['max']);
    }

    #[Test]
    public function testGetAnthropicConstraintsReturnsExpectedShape(): void
    {
        $constraints = $this->invokeConstraintMethod('getAnthropicConstraints');

        $this->assertConstraintShape($constraints);

        // Anthropic: temperature 0-1, top_p supported, no frequency/presence penalty
        self::assertTrue($constraints['temperature']['supported']);
        self::assertSame(0.0, $constraints['temperature']['min']);
        self::assertEquals(1.0, $constraints['temperature']['max']);

        self::assertTrue($constraints['top_p']['supported']);
        self::assertArrayHasKey('hint', $constraints['top_p']);

        self::assertFalse($constraints['frequency_penalty']['supported']);
        self::assertArrayHasKey('hint', $constraints['frequency_penalty']);

        self::assertFalse($constraints['presence_penalty']['supported']);
        self::assertArrayHasKey('hint', $constraints['presence_penalty']);
    }

    #[Test]
    public function testGetGoogleConstraintsReturnsExpectedShape(): void
    {
        $constraints = $this->invokeConstraintMethod('getGeminiConstraints');

        $this->assertConstraintShape($constraints);

        // Gemini: temperature 0-2, top_p supported, no frequency/presence penalty
        self::assertTrue($constraints['temperature']['supported']);
        self::assertSame(0.0, $constraints['temperature']['min']);
        self::assertEquals(2.0, $constraints['temperature']['max']);

        self::assertTrue($constraints['top_p']['supported']);

        self::assertFalse($constraints['frequency_penalty']['supported']);
        self::assertFalse($constraints['presence_penalty']['supported']);
    }

    #[Test]
    public function testGetDefaultConstraintsReturnsExpectedShape(): void
    {
        $constraints = $this->invokeConstraintMethod('getDefaultConstraints');

        $this->assertConstraintShape($constraints);

        // Default: all supported with standard ranges
        self::assertTrue($constraints['temperature']['supported']);
        self::assertSame(0.0, $constraints['temperature']['min']);
        self::assertEquals(2.0, $constraints['temperature']['max']);

        self::assertTrue($constraints['top_p']['supported']);
        self::assertSame(0.0, $constraints['top_p']['min']);
        self::assertSame(1.0, $constraints['top_p']['max']);

        self::assertTrue($constraints['frequency_penalty']['supported']);
        self::assertSame(-2.0, $constraints['frequency_penalty']['min']);
        self::assertSame(2.0, $constraints['frequency_penalty']['max']);

        self::assertTrue($constraints['presence_penalty']['supported']);
        self::assertSame(-2.0, $constraints['presence_penalty']['min']);
        self::assertSame(2.0, $constraints['presence_penalty']['max']);
    }

    // getModelConstraintsAction tests

    /**
     * Extract and type-assert constraints from a getModelConstraintsAction response.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, array<string, mixed>>
     */
    private function extractConstraints(array $data): array
    {
        self::assertArrayHasKey('constraints', $data);
        self::assertIsArray($data['constraints']);
        /** @var array<string, array<string, mixed>> $constraints */
        $constraints = $data['constraints'];
        return $constraints;
    }

    /**
     * Create a Model with the given adapter type and model ID.
     */
    private function createModelWithProvider(string $adapterType, string $modelId, int $uid = 1): Model
    {
        $provider = new Provider();
        $providerReflection = new ReflectionClass($provider);
        $providerReflection->getProperty('uid')->setValue($provider, 1);
        $provider->setAdapterType($adapterType);
        $provider->setName('Test Provider');

        $model = new Model();
        $modelReflection = new ReflectionClass($model);
        $modelReflection->getProperty('uid')->setValue($model, $uid);
        $model->setModelId($modelId);
        $model->setProvider($provider);

        return $model;
    }

    #[Test]
    public function getModelConstraintsActionReturnsDefaultConstraintsWhenModelUidIsZero(): void
    {
        $this->modelRepository
            ->expects(self::never())
            ->method('findByUid');

        $request = $this->createRequest(['modelUid' => 0]);
        $response = $this->subject->getModelConstraintsAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['success']);
        self::assertIsArray($data['constraints']);
        // Default constraints have all 4 parameters supported
        self::assertArrayHasKey('temperature', $data['constraints']);
        self::assertArrayHasKey('top_p', $data['constraints']);
        self::assertArrayHasKey('frequency_penalty', $data['constraints']);
        self::assertArrayHasKey('presence_penalty', $data['constraints']);
    }

    #[Test]
    public function getModelConstraintsActionReturnsDefaultConstraintsWhenModelUidMissing(): void
    {
        $this->modelRepository
            ->expects(self::never())
            ->method('findByUid');

        $request = $this->createRequest([]);
        $response = $this->subject->getModelConstraintsAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['success']);
        self::assertIsArray($data['constraints']);
    }

    #[Test]
    public function getModelConstraintsActionReturnsDefaultConstraintsWhenModelNotFound(): void
    {
        $this->modelRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(999)
            ->willReturn(null);

        $request = $this->createRequest(['modelUid' => 999]);
        $response = $this->subject->getModelConstraintsAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['success']);
        $constraints = $this->extractConstraints($data);
        // Default constraints: temperature max is 2.0
        self::assertEquals(2.0, $constraints['temperature']['max']);
        self::assertTrue($constraints['frequency_penalty']['supported']);
    }

    #[Test]
    public function getModelConstraintsActionReturnsAnthropicConstraintsForAnthropicModel(): void
    {
        $model = $this->createModelWithProvider('anthropic', 'claude-opus-4-5-20251101');

        $this->modelRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(1)
            ->willReturn($model);

        $request = $this->createRequest(['modelUid' => 1]);
        $response = $this->subject->getModelConstraintsAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['success']);
        $constraints = $this->extractConstraints($data);
        // Anthropic: temperature max is 1.0 (not 2.0), frequency_penalty unsupported
        self::assertEquals(1.0, $constraints['temperature']['max']);
        self::assertFalse($constraints['frequency_penalty']['supported']);
        self::assertFalse($constraints['presence_penalty']['supported']);
    }

    #[Test]
    public function getModelConstraintsActionReturnsGeminiConstraintsForGeminiModel(): void
    {
        $model = $this->createModelWithProvider('gemini', 'gemini-2.5-flash', 2);

        $this->modelRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(2)
            ->willReturn($model);

        $request = $this->createRequest(['modelUid' => 2]);
        $response = $this->subject->getModelConstraintsAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['success']);
        $constraints = $this->extractConstraints($data);
        // Gemini: temperature max is 2.0, frequency_penalty unsupported
        self::assertEquals(2.0, $constraints['temperature']['max']);
        self::assertFalse($constraints['frequency_penalty']['supported']);
        self::assertFalse($constraints['presence_penalty']['supported']);
    }

    #[Test]
    public function getModelConstraintsActionReturnsOllamaConstraintsForOllamaModel(): void
    {
        $model = $this->createModelWithProvider('ollama', 'llama3.2', 3);

        $this->modelRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(3)
            ->willReturn($model);

        $request = $this->createRequest(['modelUid' => 3]);
        $response = $this->subject->getModelConstraintsAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['success']);
        $constraints = $this->extractConstraints($data);
        // Ollama: all supported including frequency/presence penalty
        self::assertTrue($constraints['frequency_penalty']['supported']);
        self::assertTrue($constraints['presence_penalty']['supported']);
    }

    #[Test]
    public function getModelConstraintsActionReturnsOpenAIChatConstraintsForRegularOpenAIModel(): void
    {
        $model = $this->createModelWithProvider('openai', 'gpt-4o', 4);

        $this->modelRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(4)
            ->willReturn($model);

        $request = $this->createRequest(['modelUid' => 4]);
        $response = $this->subject->getModelConstraintsAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['success']);
        $constraints = $this->extractConstraints($data);
        // OpenAI chat (non-reasoning): all parameters supported, temperature max 2.0
        self::assertEquals(2.0, $constraints['temperature']['max']);
        self::assertTrue($constraints['frequency_penalty']['supported']);
        self::assertTrue($constraints['presence_penalty']['supported']);
        // No 'fixed' key for non-reasoning models
        self::assertArrayNotHasKey('fixed', $constraints['temperature']);
    }

    #[Test]
    public function getModelConstraintsActionReturnsReasoningConstraintsForOpenAIReasoningModel(): void
    {
        // o-series models are reasoning models
        $model = $this->createModelWithProvider('openai', 'o3', 5);

        $this->modelRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(5)
            ->willReturn($model);

        $request = $this->createRequest(['modelUid' => 5]);
        $response = $this->subject->getModelConstraintsAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['success']);
        $constraints = $this->extractConstraints($data);
        // Reasoning model: temperature and top_p fixed at 1.0, frequency/presence unsupported
        self::assertEquals(1.0, $constraints['temperature']['fixed']);
        self::assertEquals(1.0, $constraints['top_p']['fixed']);
        self::assertFalse($constraints['frequency_penalty']['supported']);
        self::assertFalse($constraints['presence_penalty']['supported']);
    }

    #[Test]
    public function getModelConstraintsActionReturnsReasoningConstraintsForGpt5Model(): void
    {
        // gpt-5 prefix models are reasoning models
        $model = $this->createModelWithProvider('openai', 'gpt-5.2', 6);

        $this->modelRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(6)
            ->willReturn($model);

        $request = $this->createRequest(['modelUid' => 6]);
        $response = $this->subject->getModelConstraintsAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['success']);
        $constraints = $this->extractConstraints($data);
        // gpt-5.x is a reasoning model
        self::assertEquals(1.0, $constraints['temperature']['fixed']);
        self::assertFalse($constraints['frequency_penalty']['supported']);
    }

    #[Test]
    public function getModelConstraintsActionReturnsReasoningConstraintsForAzureReasoningModel(): void
    {
        // azure_openai with o-series model is also a reasoning model
        $model = $this->createModelWithProvider('azure_openai', 'o1-mini', 7);

        $this->modelRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(7)
            ->willReturn($model);

        $request = $this->createRequest(['modelUid' => 7]);
        $response = $this->subject->getModelConstraintsAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['success']);
        $constraints = $this->extractConstraints($data);
        self::assertEquals(1.0, $constraints['temperature']['fixed']);
        self::assertFalse($constraints['frequency_penalty']['supported']);
    }

    #[Test]
    public function getModelConstraintsActionReturnsDefaultConstraintsForUnknownAdapterType(): void
    {
        // Unknown adapter type with non-reasoning model ID → default constraints
        $model = $this->createModelWithProvider('custom_unknown', 'my-custom-model', 8);

        $this->modelRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(8)
            ->willReturn($model);

        $request = $this->createRequest(['modelUid' => 8]);
        $response = $this->subject->getModelConstraintsAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['success']);
        $constraints = $this->extractConstraints($data);
        // Unknown adapter: default constraints (all supported)
        self::assertTrue($constraints['frequency_penalty']['supported']);
        self::assertTrue($constraints['presence_penalty']['supported']);
        self::assertArrayNotHasKey('fixed', $constraints['temperature']);
    }

    #[Test]
    public function getModelConstraintsActionReturnsReasoningConstraintsForOpenRouterReasoningModel(): void
    {
        // openrouter adapter type with o-series model → reasoning constraints
        $model = $this->createModelWithProvider('openrouter', 'o3-mini', 9);

        $this->modelRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(9)
            ->willReturn($model);

        $request = $this->createRequest(['modelUid' => 9]);
        $response = $this->subject->getModelConstraintsAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['success']);
        $constraints = $this->extractConstraints($data);
        // openrouter with o-series: isReasoningModel=true → getOpenAIReasoningConstraints
        // But buildConstraints default branch uses isReasoning ? reasoning : default
        // openrouter is not in openai/azure_openai match arm, falls to default branch
        self::assertEquals(1.0, $constraints['temperature']['fixed']);
    }

    #[Test]
    public function getModelConstraintsActionReturnsDefaultConstraintsForModelWithoutProvider(): void
    {
        // Model with no provider: adapterType defaults to empty string
        $model = new Model();
        $modelReflection = new ReflectionClass($model);
        $modelReflection->getProperty('uid')->setValue($model, 10);
        $model->setModelId('orphan-model');
        // No provider set → getProvider() returns null

        $this->modelRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(10)
            ->willReturn($model);

        $request = $this->createRequest(['modelUid' => 10]);
        $response = $this->subject->getModelConstraintsAction($request);

        $data = $this->decodeJsonResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['success']);
        $constraints = $this->extractConstraints($data);
        // No provider → adapterType = '' → default constraints
        self::assertTrue($constraints['frequency_penalty']['supported']);
        self::assertArrayNotHasKey('fixed', $constraints['temperature']);
    }
}
