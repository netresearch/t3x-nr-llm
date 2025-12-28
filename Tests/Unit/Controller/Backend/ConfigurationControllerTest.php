<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\ConfigurationController;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Service\LlmConfigurationService;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
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
    private LlmConfigurationService&MockObject $configurationService;
    private LlmServiceManagerInterface&MockObject $llmServiceManager;
    private ConfigurationController $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurationRepository = $this->createMock(LlmConfigurationRepository::class);
        $this->configurationService = $this->createMock(LlmConfigurationService::class);
        $this->llmServiceManager = $this->createMock(LlmServiceManagerInterface::class);

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

        return $controller;
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new ReflectionClass($object);
        $prop = $reflection->getProperty($property);
        $prop->setValue($object, $value);
    }

    private function createRequest(array $body): ServerRequest
    {
        // TYPO3's ServerRequest has ($uri, $method) signature, not ($method, $uri)
        return (new ServerRequest('/ajax/test', 'POST'))
            ->withParsedBody($body);
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

        $data = json_decode((string)$response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['success']);
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

        $data = json_decode((string)$response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['success']);
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

        $data = json_decode((string)$response->getBody(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
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

        $data = json_decode((string)$response->getBody(), true);

        self::assertSame(404, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
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

        $data = json_decode((string)$response->getBody(), true);

        self::assertSame(500, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
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

        $data = json_decode((string)$response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
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

        $data = json_decode((string)$response->getBody(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
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

        $data = json_decode((string)$response->getBody(), true);

        self::assertSame(404, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
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

        $data = json_decode((string)$response->getBody(), true);

        self::assertSame(500, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
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

        $data = json_decode((string)$response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['success']);
        self::assertArrayHasKey('models', $data);
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

        $data = json_decode((string)$response->getBody(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
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

        $data = json_decode((string)$response->getBody(), true);

        self::assertSame(404, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
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

        $data = json_decode((string)$response->getBody(), true);

        self::assertSame(500, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertStringContainsString('Provider error', $data['error']);
    }

    // testConfigurationAction tests

    #[Test]
    public function testConfigurationActionReturnsSuccessForValidConfiguration(): void
    {
        $configuration = $this->createConfiguration(1, true);
        $configuration->setProvider('openai');
        $configuration->setModel('gpt-4');

        $usage = new UsageStatistics(10, 20, 30);
        $response = new CompletionResponse(
            content: 'Hello! How can I help you?',
            model: 'gpt-4',
            usage: $usage,
        );

        $this->configurationRepository
            ->expects(self::once())
            ->method('findByUid')
            ->with(1)
            ->willReturn($configuration);

        $this->llmServiceManager
            ->expects(self::once())
            ->method('complete')
            ->willReturn($response);

        $request = $this->createRequest(['uid' => 1]);
        $responseObj = $this->subject->testConfigurationAction($request);

        $data = json_decode((string)$responseObj->getBody(), true);

        self::assertSame(200, $responseObj->getStatusCode());
        self::assertTrue($data['success']);
        self::assertEquals('Hello! How can I help you?', $data['content']);
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

        $data = json_decode((string)$response->getBody(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
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

        $data = json_decode((string)$response->getBody(), true);

        self::assertSame(404, $response->getStatusCode());
        self::assertArrayHasKey('error', $data);
        self::assertStringContainsString('not found', $data['error']);
    }

    #[Test]
    public function testConfigurationActionReturnsErrorOnException(): void
    {
        $configuration = $this->createConfiguration(1, true);
        $configuration->setProvider('openai');
        $configuration->setModel('gpt-4');

        $this->configurationRepository
            ->method('findByUid')
            ->willReturn($configuration);

        $this->llmServiceManager
            ->method('complete')
            ->willThrowException(new RuntimeException('API error'));

        $request = $this->createRequest(['uid' => 1]);
        $response = $this->subject->testConfigurationAction($request);

        $data = json_decode((string)$response->getBody(), true);

        self::assertSame(500, $response->getStatusCode());
        self::assertFalse($data['success']);
        self::assertArrayHasKey('error', $data);
        self::assertStringContainsString('API error', $data['error']);
    }
}
