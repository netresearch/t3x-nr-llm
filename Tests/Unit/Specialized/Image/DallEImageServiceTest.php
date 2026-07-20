<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Image;

use Closure;
use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderMiddlewareInterface;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\Guardrail\InputGuardrailScreener;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Specialized\Exception\ServiceConfigurationException;
use Netresearch\NrLlm\Specialized\Exception\ServiceQuotaExceededException;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Image\DallEImageService;
use Netresearch\NrLlm\Specialized\Image\ImageGenerationResult;
use Netresearch\NrLlm\Specialized\Option\ImageGenerationOptions;
use Netresearch\NrLlm\Specialized\Pricing\SpecializedCostCalculator;
use Netresearch\NrLlm\Specialized\Pricing\SpecializedCostCalculatorInterface;
use Netresearch\NrLlm\Tests\Fixture\AllowingBudgetService;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Support\InMemoryQueryResult;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(DallEImageService::class)]
class DallEImageServiceTest extends AbstractUnitTestCase
{
    private ClientInterface&Stub $httpClientStub;
    private RequestFactoryInterface&Stub $requestFactoryStub;
    private StreamFactoryInterface&Stub $streamFactoryStub;
    private ExtensionConfiguration&MockObject $extensionConfigMock;
    private UsageTrackerServiceInterface&Stub $usageTrackerStub;
    private LoggerInterface&Stub $loggerStub;
    private VaultServiceInterface $vaultStub;
    private SpecializedCostCalculatorInterface $costCalculator;
    private ?string $tempFile = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClientStub = self::createStub(ClientInterface::class);
        $this->requestFactoryStub = self::createStub(RequestFactoryInterface::class);
        $this->streamFactoryStub = self::createStub(StreamFactoryInterface::class);
        $this->extensionConfigMock = $this->createMock(ExtensionConfiguration::class);
        $this->usageTrackerStub = self::createStub(UsageTrackerServiceInterface::class);
        $this->loggerStub = self::createStub(LoggerInterface::class);
        $this->vaultStub = $this->createVaultServiceMock();

        // Real calculator over a model-less repository: catalog prices apply,
        // so the cost assertions below exercise the real pricing math.
        $modelRepositoryStub = self::createStub(ModelRepository::class);
        $modelRepositoryStub->method('findOneByIdentifier')->willReturn(null);
        $this->costCalculator = new SpecializedCostCalculator($modelRepositoryStub);
    }

    /**
     * Build a DallEImageService wired to the vault mock, then inject the given
     * plain HTTP client through the test seam (bypasses the vault secure client
     * so request/response assertions can read the request the service built).
     */
    #[Test]
    public function aGenerationIsRoutedThroughThePipelineWithAnImageServiceContext(): void
    {
        $captured   = null;
        $middleware = new class (static function (ProviderCallContext $context) use (&$captured): void {
            $captured = $context;
        }) implements ProviderMiddlewareInterface {
            /**
             * @param Closure(ProviderCallContext): void $onHandle
             */
            public function __construct(private readonly Closure $onHandle) {}

            public function handle(ProviderCallContext $context, callable $next): mixed
            {
                ($this->onHandle)($context);

                return $next($context);
            }
        };

        $config = [
            'providers' => ['openai' => ['apiKeyIdentifier' => 'test-api-key']],
        ];
        $this->extensionConfigMock->expects(self::once())->method('get')->with('nr_llm')->willReturn($config);

        $service = $this->buildService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $this->usageTrackerStub,
            $this->loggerStub,
            ['pipeline' => new MiddlewarePipeline([$middleware])],
        );
        $this->setupSuccessfulRequest(['data' => [['url' => 'https://example.org/i.png']]]);

        $service->generate('a cat', ['model' => 'dall-e-3']);

        self::assertInstanceOf(ProviderCallContext::class, $captured);
        // The dispatch now carries the shared lifecycle: a labelled operation,
        // the provider and model for telemetry, and a correlation id (ADR-097).
        self::assertSame(ProviderOperation::ImageGeneration, $captured->operation);
        self::assertSame('dall-e', $captured->telemetryProvider());
        self::assertSame('dall-e-3', $captured->telemetryModel());
        self::assertNotSame('', $captured->correlationId);
    }

    /**
     * @param array{model?: ModelRepository|null, configuration?: LlmConfigurationRepository|null, budget?: BudgetServiceInterface|null, pipeline?: MiddlewarePipeline} $repositories
     */
    private function buildService(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        ExtensionConfiguration $extensionConfiguration,
        UsageTrackerServiceInterface $usageTracker,
        LoggerInterface $logger,
        array $repositories = [],
    ): DallEImageService {
        $service = new DallEImageService(
            $this->vaultStub,
            $requestFactory,
            $streamFactory,
            $extensionConfiguration,
            $usageTracker,
            $logger,
            $this->costCalculator,
            $repositories['budget'] ?? new AllowingBudgetService(),
            $repositories['pipeline'] ?? new MiddlewarePipeline([]),
            $repositories['screener'] ?? new InputGuardrailScreener([]),
            $repositories['model'] ?? null,
            $repositories['configuration'] ?? null,
        );
        $service->setHttpClient($httpClient);

        return $service;
    }

    protected function tearDown(): void
    {
        if ($this->tempFile !== null && file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createSubject(
        array $config = [],
        ?ModelRepository $modelRepository = null,
        ?LlmConfigurationRepository $configurationRepository = null,
        ?BudgetServiceInterface $budgetService = null,
    ): DallEImageService {
        $defaultConfig = [
            'providers' => [
                'openai' => [
                    'apiKeyIdentifier' => 'test-api-key',
                ],
            ],
        ];

        $this->extensionConfigMock
            ->expects(self::once())->method('get')
            ->with('nr_llm')
            ->willReturn(array_merge($defaultConfig, $config));

        return $this->buildService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $this->usageTrackerStub,
            $this->loggerStub,
            ['model' => $modelRepository, 'configuration' => $configurationRepository, 'budget' => $budgetService],
        );
    }

    #[Test]
    public function generateEnforcesTheBudgetBeforeAnyHttpCall(): void
    {
        $budget = self::createStub(BudgetServiceInterface::class);
        $budget->method('check')->willReturn(BudgetCheckResult::denied('cost_per_day', 5.0, 5.0, 'exhausted'));

        $subject = $this->createSubject(budgetService: $budget);

        // The gate short-circuits with BudgetExceededException; a removed gate
        // would instead proceed into the (stubbed) HTTP path and fail otherwise.
        $this->expectException(BudgetExceededException::class);

        $subject->generate('A cat', new ImageGenerationOptions(beUserUid: 5, plannedCost: 10.0));
    }

    private function createSubjectWithoutApiKey(): DallEImageService
    {
        $this->extensionConfigMock
            ->expects(self::once())->method('get')
            ->with('nr_llm')
            ->willReturn([
                'providers' => [],
            ]);

        return $this->buildService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $this->usageTrackerStub,
            $this->loggerStub,
        );
    }

    private function createTestImageFile(): string
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'dalle_test_') . '.png';
        // Create minimal PNG file (8x8 transparent)
        $pngContent = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAYAAADED76LAAAADklEQVQI12NgGAWjYGgAAAIIAAFcCg/wAAAAAElFTkSuQmCC');
        file_put_contents($this->tempFile, $pngContent);
        return $this->tempFile;
    }

    /**
     * @param array<string, mixed> $responseData
     */
    private function setupSuccessfulRequest(array $responseData): void
    {
        $requestStub = self::createStub(RequestInterface::class);
        $requestStub->method('withHeader')->willReturnSelf();
        $requestStub->method('withBody')->willReturnSelf();

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($requestStub);

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($streamStub);

        $responseBodyStub = self::createStub(StreamInterface::class);
        $responseBodyStub->method('__toString')->willReturn(json_encode($responseData));

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn(200);
        $responseStub->method('getBody')->willReturn($responseBodyStub);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($responseStub);
    }

    private function setupFailedRequest(int $statusCode, string $errorMessage = 'API Error'): void
    {
        $requestStub = self::createStub(RequestInterface::class);
        $requestStub->method('withHeader')->willReturnSelf();
        $requestStub->method('withBody')->willReturnSelf();

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($requestStub);

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($streamStub);

        $responseBodyStub = self::createStub(StreamInterface::class);
        $responseBodyStub->method('__toString')->willReturn(json_encode([
            'error' => ['message' => $errorMessage],
        ]));

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn($statusCode);
        $responseStub->method('getBody')->willReturn($responseBodyStub);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($responseStub);
    }

    // ==================== isAvailable tests ====================

    #[Test]
    public function isAvailableReturnsTrueWithApiKey(): void
    {
        $subject = $this->createSubject();

        self::assertTrue($subject->isAvailable());
    }

    #[Test]
    public function isAvailableReturnsFalseWithoutApiKey(): void
    {
        $subject = $this->createSubjectWithoutApiKey();

        self::assertFalse($subject->isAvailable());
    }

    // ==================== getters tests ====================

    #[Test]
    public function getAvailableModelsReturnsModelCapabilities(): void
    {
        $subject = $this->createSubject();

        $models = $subject->getAvailableModels();

        self::assertArrayHasKey('dall-e-2', $models);
        self::assertArrayHasKey('dall-e-3', $models);
        self::assertArrayHasKey('sizes', $models['dall-e-3']);
    }

    #[Test]
    public function getSupportedSizesReturnsCorrectSizesForDallE3(): void
    {
        $subject = $this->createSubject();

        $sizes = $subject->getSupportedSizes('dall-e-3');

        self::assertContains('1024x1024', $sizes);
        self::assertContains('1792x1024', $sizes);
        self::assertContains('1024x1792', $sizes);
    }

    #[Test]
    public function getSupportedSizesReturnsCorrectSizesForDallE2(): void
    {
        $subject = $this->createSubject();

        $sizes = $subject->getSupportedSizes('dall-e-2');

        self::assertContains('256x256', $sizes);
        self::assertContains('512x512', $sizes);
        self::assertContains('1024x1024', $sizes);
    }

    #[Test]
    #[DataProvider('gptImageVariantProvider')]
    public function getSupportedSizesResolvesGptImageFamilyToSharedCapabilities(string $model): void
    {
        $subject = $this->createSubject();

        // Every gpt-image-* variant shares the gpt-image-1 capability profile rather than
        // silently falling back to the DALL·E default size set.
        $sizes = $subject->getSupportedSizes($model);

        self::assertContains('1536x1024', $sizes);
        self::assertContains('1024x1536', $sizes);
        self::assertNotContains('1792x1024', $sizes);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function gptImageVariantProvider(): array
    {
        return [
            'gpt-image-1' => ['gpt-image-1'],
            'gpt-image-1-mini' => ['gpt-image-1-mini'],
            'gpt-image-2' => ['gpt-image-2'],
        ];
    }

    #[Test]
    public function getSupportedSizesReturnsDefaultForUnknownModel(): void
    {
        $subject = $this->createSubject();

        $sizes = $subject->getSupportedSizes('unknown-model');

        self::assertEquals(['1024x1024'], $sizes);
    }

    // ==================== generate tests ====================

    #[Test]
    public function generateReturnsImageGenerationResult(): void
    {
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest([
            'data' => [
                [
                    'url' => 'https://example.com/image.png',
                    'revised_prompt' => 'A beautiful cat sitting',
                ],
            ],
        ]);

        $result = $subject->generate('A cat');

        self::assertInstanceOf(ImageGenerationResult::class, $result);
        self::assertEquals('https://example.com/image.png', $result->url);
        self::assertEquals('A cat', $result->prompt);
        self::assertEquals('A beautiful cat sitting', $result->revisedPrompt);
        self::assertEquals('dall-e-3', $result->model);
        self::assertEquals('1024x1024', $result->size);
    }

    #[Test]
    public function generateDegradesGracefullyWhenDataElementIsScalar(): void
    {
        $subject = $this->createSubject();
        // Untrusted 2xx body whose data[] element is a scalar, not an object:
        // must degrade to an empty url rather than crash with a TypeError.
        $this->setupSuccessfulRequest(['data' => ['not-an-object']]);

        $result = $subject->generate('A cat');

        self::assertInstanceOf(ImageGenerationResult::class, $result);
        self::assertSame('', $result->url);
        self::assertNull($result->base64);
        self::assertNull($result->revisedPrompt);
    }

    #[Test]
    public function generateIgnoresNonStringFieldsInDataElement(): void
    {
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest(['data' => [['url' => 12345, 'b64_json' => ['nested']]]]);

        $result = $subject->generate('A cat');

        self::assertSame('', $result->url);
        self::assertNull($result->base64);
    }

    #[Test]
    public function generateThrowsWhenServiceUnavailable(): void
    {
        $subject = $this->createSubjectWithoutApiKey();

        $this->expectException(ServiceUnavailableException::class);

        $subject->generate('A cat');
    }

    #[Test]
    public function generateThrowsOnEmptyPrompt(): void
    {
        $subject = $this->createSubject();

        $this->expectException(ServiceUnavailableException::class);

        $subject->generate('   ');
    }

    #[Test]
    public function generateThrowsOnPromptTooLong(): void
    {
        $subject = $this->createSubject();
        $longPrompt = str_repeat('a', 4001);

        $this->expectException(ServiceUnavailableException::class);

        $subject->generate($longPrompt);
    }

    #[Test]
    public function generateTracksUsage(): void
    {
        $this->setupSuccessfulRequest([
            'data' => [['url' => 'https://example.com/image.png']],
        ]);

        $this->extensionConfigMock
            ->expects(self::once())->method('get')
            ->with('nr_llm')
            ->willReturn([
                'providers' => [
                    'openai' => [
                        'apiKeyIdentifier' => 'test-api-key',
                    ],
                ],
            ]);

        $usageTrackerMock = $this->createMock(UsageTrackerServiceInterface::class);
        $usageTrackerMock
            ->expects(self::once())
            ->method('trackUsage')
            ->with(
                'image',
                'dall-e',
                self::callback(
                    // dall-e-3 responses carry no usage object: token metrics
                    // are omitted, the cost falls back to the per-image list
                    // price (standard 1024x1024 = $0.040).
                    fn(array $metrics): bool => $metrics['images'] === 1
                        && !isset($metrics['tokens'])
                        && is_float($metrics['cost']) && abs($metrics['cost'] - 0.040) < 1e-9,
                ),
                null,
                0,
                'dall-e-3',
                0,
                // Ambient fallback: no beUserUid option was passed (ADR-057).
                null,
            );

        $subject = $this->buildService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $usageTrackerMock,
            $this->loggerStub,
        );

        $subject->generate('A cat');
    }

    #[Test]
    public function generateAttributesUsageToOptionUid(): void
    {
        // ADR-057: a caller-supplied beUserUid in the options reaches the
        // usage row instead of the ambient fallback.
        $this->setupSuccessfulRequest([
            'data' => [['url' => 'https://example.com/image.png']],
        ]);

        $this->extensionConfigMock
            ->expects(self::once())->method('get')
            ->with('nr_llm')
            ->willReturn(['providers' => ['openai' => ['apiKeyIdentifier' => 'test-api-key']]]);

        $usageTrackerMock = $this->createMock(UsageTrackerServiceInterface::class);
        $usageTrackerMock
            ->expects(self::once())
            ->method('trackUsage')
            ->with(
                'image',
                'dall-e',
                self::callback(static fn(array $metrics): bool => $metrics['images'] === 1),
                null,
                0,
                'dall-e-3',
                0,
                42,
            );

        $subject = $this->buildService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $usageTrackerMock,
            $this->loggerStub,
        );

        $subject->generate('A cat', new ImageGenerationOptions(beUserUid: 42));
    }

    #[Test]
    public function generateLinksUsageRowToRegistryRecordUid(): void
    {
        // When the used model id matches a tx_nrllm_model record, the
        // usage row carries that record's uid so the Analytics model
        // breakdowns link back to the registry.
        $record = new Model();
        $record->setModelId('dall-e-3');
        $record->_setProperty('uid', 42);

        $modelRepository = $this->createMock(ModelRepository::class);
        $modelRepository->expects(self::once())->method('findOneByModelId')->with('dall-e-3')->willReturn($record);

        $usageTrackerMock = $this->createMock(UsageTrackerServiceInterface::class);
        $usageTrackerMock
            ->expects(self::once())
            ->method('trackUsage')
            ->with(
                'image',
                'dall-e',
                self::callback(static fn(array $metrics): bool => $metrics['images'] === 1),
                null,
                42,
                'dall-e-3',
            );

        $this->extensionConfigMock
            ->expects(self::once())->method('get')
            ->with('nr_llm')
            ->willReturn(['providers' => ['openai' => ['apiKeyIdentifier' => 'test-api-key']]]);
        $this->setupSuccessfulRequest([
            'data' => [['url' => 'https://example.com/image.png']],
        ]);

        $subject = $this->buildService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $usageTrackerMock,
            $this->loggerStub,
            ['model' => $modelRepository],
        );

        $subject->generate('A cat');
    }

    #[Test]
    public function resolveDefaultModelPrefersDefaultFlaggedImageRecord(): void
    {
        $regular = new Model();
        $regular->setModelId('gpt-image-1');
        $default = new Model();
        $default->setModelId('gpt-image-2');
        $default->setIsDefault(true);

        $modelRepository = $this->createMock(ModelRepository::class);
        $modelRepository->expects(self::once())
            ->method('findByCapability')
            ->with('image')
            ->willReturn(new InMemoryQueryResult([$regular, $default]));

        $subject = $this->createSubject(modelRepository: $modelRepository);

        self::assertSame('gpt-image-2', $subject->resolveDefaultModel('dall-e-3'));
    }

    #[Test]
    public function resolveDefaultModelSkipsForeignProviderModelIds(): void
    {
        // The IMAGE capability is shared across providers: a FAL record — even
        // default-flagged — must never be sent to the OpenAI images endpoint.
        $foreignDefault = new Model();
        $foreignDefault->setModelId('flux-schnell');
        $foreignDefault->setIsDefault(true);
        $acceptable = new Model();
        $acceptable->setModelId('gpt-image-2');

        $modelRepository = $this->createMock(ModelRepository::class);
        $modelRepository->method('findByCapability')
            ->willReturn(new InMemoryQueryResult([$foreignDefault, $acceptable]));

        $subject = $this->createSubject(modelRepository: $modelRepository);

        self::assertSame('gpt-image-2', $subject->resolveDefaultModel('dall-e-3'));
    }

    #[Test]
    public function resolveDefaultModelReturnsFallbackWhenNoImageRecordExists(): void
    {
        $modelRepository = $this->createMock(ModelRepository::class);
        $modelRepository->method('findByCapability')->willReturn(new InMemoryQueryResult([]));

        $subject = $this->createSubject(modelRepository: $modelRepository);

        self::assertSame('dall-e-3', $subject->resolveDefaultModel('dall-e-3'));
    }

    #[Test]
    public function resolveDefaultModelReturnsFallbackWhenRepositoryThrows(): void
    {
        $modelRepository = $this->createMock(ModelRepository::class);
        $modelRepository->method('findByCapability')
            ->willThrowException(new RuntimeException('persistence unavailable'));

        $subject = $this->createSubject(modelRepository: $modelRepository);

        self::assertSame('dall-e-3', $subject->resolveDefaultModel('dall-e-3'));
    }

    #[Test]
    public function resolveModelForConfigurationUsesConfiguredModel(): void
    {
        $model = new Model();
        $model->setModelId('gpt-image-2');

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('alt-text-images');
        $configuration->setLlmModel($model);

        $configurationRepository = $this->createMock(LlmConfigurationRepository::class);
        $configurationRepository->expects(self::once())
            ->method('findOneByIdentifier')
            ->with('alt-text-images')
            ->willReturn($configuration);

        $subject = $this->createSubject(configurationRepository: $configurationRepository);

        self::assertSame('gpt-image-2', $subject->resolveModelForConfiguration('alt-text-images', 'dall-e-3'));
    }

    #[Test]
    public function resolveModelForConfigurationFallsBackToImageCapabilityDefault(): void
    {
        // Unknown configuration identifier: the capability-based registry
        // default applies — for this service the `image` capability.
        $configurationRepository = self::createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findOneByIdentifier')->willReturn(null);

        $registryDefault = new Model();
        $registryDefault->setModelId('gpt-image-2');

        $modelRepository = $this->createMock(ModelRepository::class);
        $modelRepository->expects(self::once())
            ->method('findByCapability')
            ->with('image')
            ->willReturn(new InMemoryQueryResult([$registryDefault]));

        $subject = $this->createSubject(
            modelRepository: $modelRepository,
            configurationRepository: $configurationRepository,
        );

        self::assertSame('gpt-image-2', $subject->resolveModelForConfiguration('unknown', 'dall-e-3'));
    }

    #[Test]
    public function resolveModelForConfigurationReturnsFallbackWithoutRepositories(): void
    {
        $subject = $this->createSubject();

        self::assertSame('dall-e-3', $subject->resolveModelForConfiguration('alt-text-images', 'dall-e-3'));
    }

    #[Test]
    public function getConfigurationSystemPromptReturnsPromptOfActiveConfiguration(): void
    {
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('alt-text-images');
        $configuration->setSystemPrompt('Generate decorative, brand-neutral imagery.');

        $configurationRepository = self::createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findOneByIdentifier')->willReturn($configuration);

        $subject = $this->createSubject(configurationRepository: $configurationRepository);

        self::assertSame(
            'Generate decorative, brand-neutral imagery.',
            $subject->getConfigurationSystemPrompt('alt-text-images'),
        );
    }

    #[Test]
    public function generateLinksUsageRowToConfigurationUid(): void
    {
        // When the options carry an LlmConfiguration identifier, the usage
        // row links to that configuration record so the Analytics module
        // can aggregate image spend per configuration.
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('alt-text-images');
        $configuration->_setProperty('uid', 23);

        $configurationRepository = $this->createMock(LlmConfigurationRepository::class);
        // Called twice now: once by the budget pre-flight, once for the usage
        // row's configuration link. The count is not what this test is about.
        $configurationRepository->method('findOneByIdentifier')
            ->with('alt-text-images')
            ->willReturn($configuration);

        $usageTrackerMock = $this->createMock(UsageTrackerServiceInterface::class);
        $usageTrackerMock
            ->expects(self::once())
            ->method('trackUsage')
            ->with(
                'image',
                'dall-e',
                self::callback(static fn(array $metrics): bool => $metrics['images'] === 1),
                23,
                0,
                'dall-e-3',
            );

        $this->extensionConfigMock
            ->expects(self::once())->method('get')
            ->with('nr_llm')
            ->willReturn(['providers' => ['openai' => ['apiKeyIdentifier' => 'test-api-key']]]);
        $this->setupSuccessfulRequest([
            'data' => [['url' => 'https://example.com/image.png']],
        ]);

        $subject = $this->buildService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $usageTrackerMock,
            $this->loggerStub,
            ['configuration' => $configurationRepository],
        );

        $subject->generate('A cat', ['configuration' => 'alt-text-images']);
    }

    #[Test]
    public function generateWithOptionsObject(): void
    {
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest([
            'data' => [['url' => 'https://example.com/image.png']],
        ]);

        $options = new ImageGenerationOptions(
            model: 'dall-e-3',
            size: '1792x1024',
            quality: 'hd',
            style: 'natural',
        );

        $result = $subject->generate('A landscape', $options);

        self::assertInstanceOf(ImageGenerationResult::class, $result);
    }

    #[Test]
    public function generateWithArrayOptions(): void
    {
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest([
            'data' => [['url' => 'https://example.com/image.png']],
        ]);

        $options = [
            'model' => 'dall-e-3',
            'size' => '1024x1792',
        ];

        $result = $subject->generate('A portrait', $options);

        self::assertInstanceOf(ImageGenerationResult::class, $result);
    }

    #[Test]
    public function generateWithGptImageModelSendsMinimalPayload(): void
    {
        // gpt-image-* reject response_format/style/quality and return b64_json; the request
        // payload must therefore carry only model/prompt/n/size.
        $captured = $this->captureGeneratePayload(
            ['data' => [['b64_json' => base64_encode('img')]]],
            new ImageGenerationOptions(model: 'gpt-image-1', size: '1536x1024'),
        );

        self::assertSame('gpt-image-1', $captured['payload']['model']);
        self::assertSame('1536x1024', $captured['payload']['size']);
        self::assertArrayNotHasKey('response_format', $captured['payload']);
        self::assertArrayNotHasKey('style', $captured['payload']);
        self::assertArrayNotHasKey('quality', $captured['payload']);
        self::assertInstanceOf(ImageGenerationResult::class, $captured['result']);
    }

    #[Test]
    public function generateFallsBackToDefaultUrlWhenConfiguredBaseUrlIsEmpty(): void
    {
        // The ext_conf default for image.dalle.baseUrl is an empty string meaning "use the
        // OpenAI default" — it must NOT be sent as the (scheme-less) request URL.
        $capturedUrl = null;
        $requestStub = self::createStub(RequestInterface::class);
        $requestStub->method('withHeader')->willReturnSelf();
        $requestStub->method('withBody')->willReturnSelf();
        $this->requestFactoryStub->method('createRequest')->willReturnCallback(
            function () use (&$capturedUrl, $requestStub): RequestInterface {
                // createRequest($method, $url) — capture the URL (the second positional argument).
                $args = func_get_args();
                $capturedUrl = is_string($args[1] ?? null) ? $args[1] : '';
                return $requestStub;
            },
        );
        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub->method('createStream')->willReturn($streamStub);
        $responseBodyStub = self::createStub(StreamInterface::class);
        $responseBodyStub->method('__toString')->willReturn((string)json_encode(['data' => [['url' => 'x']]]));
        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn(200);
        $responseStub->method('getBody')->willReturn($responseBodyStub);
        $this->httpClientStub->method('sendRequest')->willReturn($responseStub);

        $subject = $this->createSubject(['image' => ['dalle' => ['baseUrl' => '']]]);
        $subject->generate('x', new ImageGenerationOptions(model: 'gpt-image-1', size: '1024x1024'));

        self::assertIsString($capturedUrl);
        self::assertStringStartsWith('https://api.openai.com/v1/images', $capturedUrl);
    }

    #[Test]
    #[DataProvider('dalleModelProvider')]
    public function generateSendsResponseFormatForDalleModels(string $model): void
    {
        // response_format (url|b64_json) is accepted by BOTH dall-e-2 and dall-e-3 and must be
        // sent for them — only gpt-image-* omits it.
        $captured = $this->captureGeneratePayload(
            ['data' => [['url' => 'https://example.com/i.png']]],
            new ImageGenerationOptions(model: $model, size: '1024x1024'),
        );

        self::assertSame($model, $captured['payload']['model']);
        self::assertSame('url', $captured['payload']['response_format']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function dalleModelProvider(): array
    {
        return [
            'dall-e-2' => ['dall-e-2'],
            'dall-e-3' => ['dall-e-3'],
        ];
    }

    /**
     * Run generate() while recording the JSON request body the service builds.
     *
     * @param array<string, mixed> $responseData
     *
     * @return array{payload: array<string, mixed>, result: ImageGenerationResult}
     */
    private function captureGeneratePayload(array $responseData, ImageGenerationOptions $options): array
    {
        $captured = null;
        $requestStub = self::createStub(RequestInterface::class);
        $requestStub->method('withHeader')->willReturnSelf();
        $requestStub->method('withBody')->willReturnSelf();
        $this->requestFactoryStub->method('createRequest')->willReturn($requestStub);

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub->method('createStream')->willReturnCallback(
            function (string $json) use (&$captured, $streamStub): StreamInterface {
                $captured = $json;
                return $streamStub;
            },
        );

        $responseBodyStub = self::createStub(StreamInterface::class);
        $responseBodyStub->method('__toString')->willReturn((string)json_encode($responseData));
        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn(200);
        $responseStub->method('getBody')->willReturn($responseBodyStub);
        $this->httpClientStub->method('sendRequest')->willReturn($responseStub);

        $result = $this->createSubject()->generate('A test prompt', $options);

        self::assertIsString($captured);
        $payload = json_decode($captured, true);
        self::assertIsArray($payload);

        /** @var array<string, mixed> $payload */
        return ['payload' => $payload, 'result' => $result];
    }

    #[Test]
    public function generateWithBase64Response(): void
    {
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest([
            'data' => [
                [
                    'url' => '',
                    'b64_json' => base64_encode('image-content'),
                ],
            ],
        ]);

        $result = $subject->generate('A cat', ['format' => 'b64_json']);

        self::assertNotNull($result->base64);
    }

    // ==================== generateMultiple tests ====================

    #[Test]
    public function generateMultipleSingleImageReturnsSingleResult(): void
    {
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest([
            'data' => [['url' => 'https://example.com/image.png']],
        ]);

        $results = $subject->generateMultiple('A cat', 1);

        self::assertCount(1, $results);
        self::assertInstanceOf(ImageGenerationResult::class, $results[0]);
    }

    #[Test]
    public function generateMultipleWithDallE2ReturnsMultipleResults(): void
    {
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest([
            'data' => [
                ['url' => 'https://example.com/image1.png'],
                ['url' => 'https://example.com/image2.png'],
            ],
        ]);

        $options = new ImageGenerationOptions(
            model: 'dall-e-2',
            size: '512x512',
        );

        $results = $subject->generateMultiple('A cat', 2, $options);

        self::assertCount(2, $results);
    }

    #[Test]
    public function generateMultipleLimitsToTenForDallE2(): void
    {
        $subject = $this->createSubject();

        // The mock will be called for N=10, not N=20
        $responseData = array_fill(0, 10, ['url' => 'https://example.com/image.png']);
        $this->setupSuccessfulRequest(['data' => $responseData]);

        $options = new ImageGenerationOptions(
            model: 'dall-e-2',
            size: '512x512',
        );

        $results = $subject->generateMultiple('A cat', 20, $options);

        self::assertCount(10, $results);
    }

    // ==================== createVariations tests ====================

    #[Test]
    public function createVariationsReturnsResults(): void
    {
        $subject = $this->createSubject();
        $imageFile = $this->createTestImageFile();
        $this->setupSuccessfulRequest([
            'data' => [['url' => 'https://example.com/variation.png']],
        ]);

        $results = $subject->createVariations($imageFile);

        self::assertCount(1, $results);
        self::assertInstanceOf(ImageGenerationResult::class, $results[0]);
        self::assertEquals('dall-e-2', $results[0]->model);
        // The response URL is mapped onto the result, and the variation
        // metadata tag is preserved.
        self::assertSame('https://example.com/variation.png', $results[0]->url);
        self::assertSame('[variation of uploaded image]', $results[0]->prompt);
        self::assertSame('1024x1024', $results[0]->size);
        /** @var array<string, mixed> $metadata */
        $metadata = $results[0]->metadata;
        self::assertSame('variation', $metadata['type'] ?? null);
    }

    #[Test]
    public function createVariationsThrowsOnFileNotFound(): void
    {
        $subject = $this->createSubject();

        $this->expectException(ServiceUnavailableException::class);

        $subject->createVariations('/non/existent/file.png');
    }

    #[Test]
    public function createVariationsThrowsOnNonPngFile(): void
    {
        $subject = $this->createSubject();
        $tempFile = tempnam(sys_get_temp_dir(), 'test_') . '.jpg';
        file_put_contents($tempFile, 'content');
        $this->tempFile = $tempFile;

        $this->expectException(ServiceUnavailableException::class);

        try {
            $subject->createVariations($tempFile);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    #[Test]
    public function createVariationsThrowsOnFileTooLarge(): void
    {
        $subject = $this->createSubject();
        $tempFile = tempnam(sys_get_temp_dir(), 'test_') . '.png';
        // Write >4MB
        file_put_contents($tempFile, str_repeat('a', 5 * 1024 * 1024));
        $this->tempFile = $tempFile;

        $this->expectException(ServiceUnavailableException::class);

        try {
            $subject->createVariations($tempFile);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    #[Test]
    public function createVariationsTracksUsage(): void
    {
        $imageFile = $this->createTestImageFile();
        $this->setupSuccessfulRequest([
            'data' => [['url' => 'https://example.com/variation.png']],
        ]);

        $this->extensionConfigMock
            ->expects(self::once())->method('get')
            ->with('nr_llm')
            ->willReturn([
                'providers' => [
                    'openai' => [
                        'apiKeyIdentifier' => 'test-api-key',
                    ],
                ],
            ]);

        $usageTrackerMock = $this->createMock(UsageTrackerServiceInterface::class);
        $usageTrackerMock
            ->expects(self::once())
            ->method('trackUsage')
            ->with(
                'image',
                'dall-e',
                self::callback(
                    // One variation at the default 1024x1024 — dall-e-2 list
                    // price $0.020 per image.
                    fn(array $metrics): bool => $metrics['images'] === 1
                        && is_float($metrics['cost']) && abs($metrics['cost'] - 0.020) < 1e-9,
                ),
                null,
                0,
                'dall-e-2',
            );

        $subject = $this->buildService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $usageTrackerMock,
            $this->loggerStub,
        );

        $subject->createVariations($imageFile);
    }

    // ==================== edit tests ====================

    #[Test]
    public function editReturnsResult(): void
    {
        $subject = $this->createSubject();
        $imageFile = $this->createTestImageFile();
        $this->setupSuccessfulRequest([
            'data' => [['url' => 'https://example.com/edited.png']],
        ]);

        $result = $subject->edit($imageFile, 'Add a hat');

        self::assertInstanceOf(ImageGenerationResult::class, $result);
        self::assertEquals('Add a hat', $result->prompt);
        self::assertEquals('dall-e-2', $result->model);
        // The first response entry's URL is mapped onto the result, and the
        // edit metadata tag is preserved.
        self::assertSame('https://example.com/edited.png', $result->url);
        self::assertSame('1024x1024', $result->size);
        /** @var array<string, mixed> $metadata */
        $metadata = $result->metadata;
        self::assertSame('edit', $metadata['type'] ?? null);
    }

    #[Test]
    public function editWithMaskReturnsResult(): void
    {
        $subject = $this->createSubject();
        $imageFile = $this->createTestImageFile();

        // Create mask file
        $maskFile = tempnam(sys_get_temp_dir(), 'mask_') . '.png';
        $pngContent = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAYAAADED76LAAAADklEQVQI12NgGAWjYGgAAAIIAAFcCg/wAAAAAElFTkSuQmCC');
        file_put_contents($maskFile, $pngContent);

        $this->setupSuccessfulRequest([
            'data' => [['url' => 'https://example.com/edited.png']],
        ]);

        try {
            $result = $subject->edit($imageFile, 'Add a hat', $maskFile);
            self::assertInstanceOf(ImageGenerationResult::class, $result);
        } finally {
            if (file_exists($maskFile)) {
                unlink($maskFile);
            }
        }
    }

    #[Test]
    public function editTracksUsage(): void
    {
        $imageFile = $this->createTestImageFile();
        $this->setupSuccessfulRequest([
            'data' => [['url' => 'https://example.com/edited.png']],
        ]);

        $this->extensionConfigMock
            ->expects(self::once())->method('get')
            ->with('nr_llm')
            ->willReturn([
                'providers' => [
                    'openai' => [
                        'apiKeyIdentifier' => 'test-api-key',
                    ],
                ],
            ]);

        $usageTrackerMock = $this->createMock(UsageTrackerServiceInterface::class);
        $usageTrackerMock
            ->expects(self::once())
            ->method('trackUsage')
            ->with(
                'image',
                'dall-e',
                self::callback(
                    fn(array $metrics): bool => $metrics['images'] === 1
                        && is_float($metrics['cost']) && abs($metrics['cost'] - 0.020) < 1e-9,
                ),
                null,
                0,
                'dall-e-2',
            );

        $subject = $this->buildService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $usageTrackerMock,
            $this->loggerStub,
        );

        $subject->edit($imageFile, 'Add a hat');
    }

    // ==================== API error handling tests ====================

    #[Test]
    public function generateThrowsOnUnauthorized(): void
    {
        $subject = $this->createSubject();
        $this->setupFailedRequest(401, 'Invalid API key');

        $this->expectException(ServiceConfigurationException::class);

        $subject->generate('A cat');
    }

    #[Test]
    public function generateThrowsOnForbidden(): void
    {
        $subject = $this->createSubject();
        $this->setupFailedRequest(403, 'Forbidden');

        $this->expectException(ServiceConfigurationException::class);

        $subject->generate('A cat');
    }

    #[Test]
    public function generateThrowsOnRateLimitExceeded(): void
    {
        $subject = $this->createSubject();
        $this->setupFailedRequest(429, 'Rate limit exceeded');

        $this->expectException(ServiceQuotaExceededException::class);

        $subject->generate('A cat');
    }

    #[Test]
    public function generateThrowsOnBadRequest(): void
    {
        $subject = $this->createSubject();
        $this->setupFailedRequest(400, 'Invalid request');

        try {
            $subject->generate('A cat');
            self::fail('Expected ServiceUnavailableException was not thrown');
        } catch (ServiceUnavailableException $e) {
            // DALL-E maps 400 to a distinct validation branch (see mapErrorStatus()):
            // the message carries the upstream detail and the context flags 'validation'
            // so downstream catches that branched on it keep working.
            self::assertStringContainsString('DALL-E API error: Invalid request', $e->getMessage());
            self::assertIsArray($e->context);
            self::assertSame('validation', $e->context['type'] ?? null);
            // The 400 branch keeps the provider tag in the exception payload.
            self::assertSame('dall-e', $e->context['provider'] ?? null);
        }
    }

    #[Test]
    public function generateThrowsOnServerError(): void
    {
        $subject = $this->createSubject();
        $this->setupFailedRequest(500, 'Internal server error');

        $this->expectException(ServiceUnavailableException::class);

        $subject->generate('A cat');
    }

    // ==================== Configuration tests ====================

    #[Test]
    public function loadConfigurationHandlesInvalidConfig(): void
    {
        $this->extensionConfigMock
            ->expects(self::once())->method('get')
            ->with('nr_llm')
            ->willReturn('not-an-array');

        $subject = $this->buildService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $this->usageTrackerStub,
            $this->loggerStub,
        );

        self::assertFalse($subject->isAvailable());
    }

    #[Test]
    public function loadConfigurationUsesCustomSettings(): void
    {
        $config = [
            'providers' => [
                'openai' => [
                    'apiKeyIdentifier' => 'test-api-key',
                ],
            ],
            'image' => [
                'dalle' => [
                    'baseUrl' => 'https://custom-api.example.com/v1/images',
                    'timeout' => 180,
                ],
            ],
        ];

        $subject = $this->createSubject($config);

        self::assertTrue($subject->isAvailable());
    }

    #[Test]
    public function defaultTimeoutIsFiveMinutesForLargeImageGeneration(): void
    {
        // gpt-image-2 at its larger sizes routinely takes 2-3+ minutes per
        // image; the previous 120s default produced live timeouts on healthy
        // generations. Without an ext-conf override the service must come up
        // with the 300s default.
        $subject = $this->createSubject();

        self::assertSame(300, $this->readTimeout($subject));
    }

    #[Test]
    public function extensionConfiguredTimeoutOverridesImageDefault(): void
    {
        // The image.dalle.timeout ext-conf override must keep winning over
        // getDefaultTimeout().
        $subject = $this->createSubject([
            'image' => [
                'dalle' => [
                    'timeout' => 600,
                ],
            ],
        ]);

        self::assertSame(600, $this->readTimeout($subject));
    }

    private function readTimeout(DallEImageService $subject): int
    {
        $timeout = (new ReflectionClass($subject))->getProperty('timeout')->getValue($subject);
        self::assertIsInt($timeout);

        return $timeout;
    }

    /**
     * Extract a scalar form-field value from an encoded multipart/form-data
     * body (the shape produced by MultipartBodyBuilderTrait): a field part is
     * `Content-Disposition: form-data; name="<name>"\r\n\r\n<value>\r\n`.
     * File parts carry an extra `; filename="..."` and are therefore skipped
     * by this name-anchored pattern.
     */
    private function multipartFieldValue(string $body, string $name): string
    {
        $pattern = sprintf(
            '/Content-Disposition: form-data; name="%s"\r\n\r\n(.*?)\r\n/s',
            preg_quote($name, '/'),
        );
        self::assertMatchesRegularExpression($pattern, $body);
        preg_match($pattern, $body, $matches);

        return is_string($matches[1] ?? null) ? $matches[1] : '';
    }

    #[Test]
    public function loadConfigurationHandlesExceptionGracefully(): void
    {
        $extensionConfigStub = self::createStub(ExtensionConfiguration::class);
        $extensionConfigStub
            ->method('get')
            ->willThrowException(new RuntimeException('Config error'));

        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock
            ->expects(self::once())
            ->method('warning');

        $subject = $this->buildService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $extensionConfigStub,
            $this->usageTrackerStub,
            $loggerMock,
        );

        self::assertFalse($subject->isAvailable());
    }

    // ==================== DallE-2 specific prompt length test ====================

    #[Test]
    public function generateWithDallE2ThrowsOnPromptTooLong(): void
    {
        $subject = $this->createSubject();
        $longPrompt = str_repeat('a', 1001); // >1000 chars for DALL-E 2

        $options = new ImageGenerationOptions(
            model: 'dall-e-2',
            size: '512x512',
        );

        $this->expectException(ServiceUnavailableException::class);

        $subject->generate($longPrompt, $options);
    }

    // ==================== generateMultiple with dall-e-3 (multiple separate calls) ====================

    #[Test]
    public function generateMultipleWithDallE3MakesMultipleSeparateCalls(): void
    {
        // DALL-E 3 does not support n > 1 in a single API call.
        // generateMultiple() must loop and call generate() individually.
        $requestStub = self::createStub(RequestInterface::class);
        $requestStub->method('withHeader')->willReturnSelf();
        $requestStub->method('withBody')->willReturnSelf();
        $this->requestFactoryStub->method('createRequest')->willReturn($requestStub);

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub->method('createStream')->willReturn($streamStub);

        $responseBodyStub = self::createStub(StreamInterface::class);
        $responseBodyStub->method('__toString')->willReturn(json_encode([
            'data' => [['url' => 'https://example.com/image.png', 'revised_prompt' => null]],
        ]));

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn(200);
        $responseStub->method('getBody')->willReturn($responseBodyStub);

        // The HTTP client must be called exactly 3 times (once per loop iteration).
        $httpClientMock = $this->createMock(ClientInterface::class);
        $httpClientMock
            ->expects(self::exactly(3))
            ->method('sendRequest')
            ->willReturn($responseStub);

        $this->extensionConfigMock
            ->expects(self::once())->method('get')
            ->with('nr_llm')
            ->willReturn([
                'providers' => ['openai' => ['apiKeyIdentifier' => 'test-api-key']],
            ]);

        $subject = $this->buildService(
            $httpClientMock,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $this->usageTrackerStub,
            $this->loggerStub,
        );

        $options = new ImageGenerationOptions(model: 'dall-e-3', size: '1024x1024');
        $results = $subject->generateMultiple('A cat', 3, $options);

        self::assertCount(3, $results);
        foreach ($results as $result) {
            self::assertInstanceOf(ImageGenerationResult::class, $result);
        }
    }

    // ==================== createVariations count clamping ====================

    #[Test]
    public function createVariationsClampCountToMinimumOfOne(): void
    {
        // Passing count=0 should be clamped to 1 by min(max($count, 1), 10).
        $subject = $this->createSubject();
        $imageFile = $this->createTestImageFile();
        $this->setupSuccessfulRequest([
            'data' => [['url' => 'https://example.com/variation.png']],
        ]);

        $results = $subject->createVariations($imageFile, 0);

        self::assertCount(1, $results);
    }

    #[Test]
    public function createVariationsClampCountToMaximumOfTen(): void
    {
        // Passing count=99 should be clamped to 10 by min(max($count, 1), 10).
        // The API responds with 10 items (we simulate that).
        $subject = $this->createSubject();
        $imageFile = $this->createTestImageFile();
        $responseData = array_fill(0, 10, ['url' => 'https://example.com/variation.png']);
        $this->setupSuccessfulRequest(['data' => $responseData]);

        $results = $subject->createVariations($imageFile, 99);

        self::assertCount(10, $results);
    }

    // ==================== executeRequest Throwable catch path ====================

    #[Test]
    public function executeRequestThrowsServiceUnavailableOnConnectionError(): void
    {
        // When the HTTP client throws an arbitrary exception (e.g. network error),
        // executeRequest() catches Throwable and wraps it in ServiceUnavailableException.
        $requestStub = self::createStub(RequestInterface::class);
        $requestStub->method('withHeader')->willReturnSelf();
        $requestStub->method('withBody')->willReturnSelf();
        $this->requestFactoryStub->method('createRequest')->willReturn($requestStub);

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub->method('createStream')->willReturn($streamStub);

        $httpClientMock = $this->createMock(ClientInterface::class);
        $httpClientMock
            ->method('sendRequest')
            ->willThrowException(new RuntimeException('Connection refused'));

        $this->extensionConfigMock
            ->expects(self::once())->method('get')
            ->with('nr_llm')
            ->willReturn([
                'providers' => ['openai' => ['apiKeyIdentifier' => 'test-api-key']],
            ]);

        $subject = $this->buildService(
            $httpClientMock,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $this->usageTrackerStub,
            $this->loggerStub,
        );

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessageMatches('/Failed to connect to OpenAI Images API/');

        $subject->generate('A cat');
    }

    // ==================== generateMultiple with dall-e-2 usage tracking ====================

    #[Test]
    public function generateMultipleWithDallE2TracksUsageWithCount(): void
    {
        // DALL-E 2 batch path tracks the produced image count after the loop.
        $imageData = [
            ['url' => 'https://example.com/image1.png'],
            ['url' => 'https://example.com/image2.png'],
        ];
        $this->setupSuccessfulRequest(['data' => $imageData]);

        $this->extensionConfigMock
            ->expects(self::once())->method('get')
            ->with('nr_llm')
            ->willReturn([
                'providers' => ['openai' => ['apiKeyIdentifier' => 'test-api-key']],
            ]);

        $usageTrackerMock = $this->createMock(UsageTrackerServiceInterface::class);
        $usageTrackerMock
            ->expects(self::once())
            ->method('trackUsage')
            ->with(
                'image',
                'dall-e',
                self::callback(
                    // Two 512x512 dall-e-2 images at $0.018 list price each.
                    fn(array $metrics): bool => $metrics['images'] === 2
                        && is_float($metrics['cost']) && abs($metrics['cost'] - 0.036) < 1e-9,
                ),
                null,
                0,
                'dall-e-2',
            );

        $subject = $this->buildService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $usageTrackerMock,
            $this->loggerStub,
        );

        $options = new ImageGenerationOptions(model: 'dall-e-2', size: '512x512');
        $results = $subject->generateMultiple('Two cats', 2, $options);

        self::assertCount(2, $results);
    }

    // ==================== gpt-image usage object parsing ====================

    #[Test]
    public function generateWithGptImageModelTracksUsageTokensAndTokenCost(): void
    {
        // gpt-image-* responses include a usage object; its tokens must land
        // in the usage row and price the call token-based:
        // 40 text-in × $5/1M + 10 image-in × $8/1M + 1000 out × $30/1M.
        $this->setupSuccessfulRequest([
            'data' => [['b64_json' => base64_encode('png-bytes')]],
            'usage' => [
                'input_tokens' => 50,
                'output_tokens' => 1000,
                'total_tokens' => 1050,
                'input_tokens_details' => ['text_tokens' => 40, 'image_tokens' => 10],
            ],
        ]);

        $this->extensionConfigMock
            ->expects(self::once())->method('get')
            ->with('nr_llm')
            ->willReturn([
                'providers' => ['openai' => ['apiKeyIdentifier' => 'test-api-key']],
            ]);

        $usageTrackerMock = $this->createMock(UsageTrackerServiceInterface::class);
        $usageTrackerMock
            ->expects(self::once())
            ->method('trackUsage')
            ->with(
                'image',
                'dall-e',
                self::callback(
                    fn(array $metrics): bool => $metrics['images'] === 1
                        && $metrics['tokens'] === 1050
                        && $metrics['promptTokens'] === 50
                        && $metrics['completionTokens'] === 1000
                        && is_float($metrics['cost']) && abs($metrics['cost'] - 0.03028) < 1e-9,
                ),
                null,
                0,
                'gpt-image-2',
                0,
                null,
            );

        $subject = $this->buildService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $usageTrackerMock,
            $this->loggerStub,
        );

        $subject->generate('A cat', ['model' => 'gpt-image-2']);
    }

    #[Test]
    public function generateWithGptImageModelWithoutUsageObjectRecordsZeroCost(): void
    {
        // Defensive path: should a gpt-image response ever lack the usage
        // object, token metrics are omitted and no cost is guessed — the
        // per-image fallback has no entry for the DALL-E quality vocabulary.
        $this->setupSuccessfulRequest([
            'data' => [['b64_json' => base64_encode('png-bytes')]],
        ]);

        $this->extensionConfigMock
            ->expects(self::once())->method('get')
            ->with('nr_llm')
            ->willReturn([
                'providers' => ['openai' => ['apiKeyIdentifier' => 'test-api-key']],
            ]);

        $usageTrackerMock = $this->createMock(UsageTrackerServiceInterface::class);
        $usageTrackerMock
            ->expects(self::once())
            ->method('trackUsage')
            ->with(
                'image',
                'dall-e',
                self::callback(
                    fn(array $metrics): bool => $metrics['images'] === 1
                        && !isset($metrics['tokens'])
                        && $metrics['cost'] === 0.0,
                ),
                null,
                0,
                'gpt-image-2',
                0,
                null,
            );

        $subject = $this->buildService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $usageTrackerMock,
            $this->loggerStub,
        );

        $subject->generate('A cat', ['model' => 'gpt-image-2']);
    }

    // ==================== buildGeneratePayload DALL-E 3 quality/style options ====================

    #[Test]
    public function generateWithDallE3IncludesQualityAndStyleInPayload(): void
    {
        // buildGeneratePayload() adds 'quality' and 'style' only when model is dall-e-3.
        // This tests that those options are forwarded and the result is well-formed.
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest([
            'data' => [['url' => 'https://example.com/hd.png', 'revised_prompt' => 'High-def landscape']],
        ]);

        $options = new ImageGenerationOptions(
            model: 'dall-e-3',
            size: '1792x1024',
            quality: 'hd',
            style: 'natural',
        );

        $result = $subject->generate('A landscape', $options);

        self::assertSame('https://example.com/hd.png', $result->url);
        self::assertSame('dall-e-3', $result->model);
        /** @var array<string, mixed> $metadata */
        $metadata = $result->metadata;
        self::assertSame('hd', $metadata['quality']);
        self::assertSame('natural', $metadata['style']);
    }

    // ==================== edit with non-existent mask file ====================

    #[Test]
    public function editThrowsWhenMaskFileNotFound(): void
    {
        // Passing a non-existent mask path to edit() must throw ServiceUnavailableException
        // because validateImageFile() is called on the mask as well.
        $subject = $this->createSubject();
        $imageFile = $this->createTestImageFile();

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessageMatches('/Image file not found/');

        $subject->edit($imageFile, 'Add a hat', '/non/existent/mask.png');
    }

    // ==================== generateMultiple guards + request shaping ====================

    #[Test]
    public function generateMultipleDefaultsToSingleImage(): void
    {
        // The default count is 1: with the default model (dall-e-3) the loop
        // runs exactly once and returns a single result.
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest([
            'data' => [['url' => 'https://example.com/image.png']],
        ]);

        $results = $subject->generateMultiple('A cat');

        self::assertCount(1, $results);
    }

    #[Test]
    public function generateMultipleThrowsWhenServiceUnavailable(): void
    {
        // The dall-e-2 batch path is guarded by ensureAvailable() before it
        // ever builds a request; a dall-e-2 model avoids the dall-e-3 loop's
        // own generate() guard so this pins generateMultiple()'s own check.
        $subject = $this->createSubjectWithoutApiKey();

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessageMatches('/is not configured/');

        $subject->generateMultiple('A cat', 2, new ImageGenerationOptions(model: 'dall-e-2', size: '512x512'));
    }

    #[Test]
    public function generateMultipleWithDallE2ValidatesPrompt(): void
    {
        // The dall-e-2 batch path validates the prompt before dispatching.
        // A successful transport is wired so that, were the validation removed,
        // the call would return normally instead of throwing.
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest(['data' => []]);

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('Prompt cannot be empty');

        $subject->generateMultiple('   ', 2, new ImageGenerationOptions(model: 'dall-e-2', size: '512x512'));
    }

    #[Test]
    public function generateMultipleWithDallE2SendsClampedNAndMapsResultFields(): void
    {
        // The dall-e-2 batch sends a single request with n clamped to 10 and
        // maps every response entry onto a result (URL, revised prompt, size,
        // metadata).
        $captured = null;
        $requestStub = self::createStub(RequestInterface::class);
        $requestStub->method('withHeader')->willReturnSelf();
        $requestStub->method('withBody')->willReturnSelf();
        $this->requestFactoryStub->method('createRequest')->willReturn($requestStub);

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub->method('createStream')->willReturnCallback(
            function (string $json) use (&$captured, $streamStub): StreamInterface {
                $captured = $json;
                return $streamStub;
            },
        );

        $responseBodyStub = self::createStub(StreamInterface::class);
        $responseBodyStub->method('__toString')->willReturn((string)json_encode([
            'data' => array_fill(0, 10, ['url' => 'https://example.com/i.png', 'revised_prompt' => 'rev']),
        ]));
        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn(200);
        $responseStub->method('getBody')->willReturn($responseBodyStub);
        $this->httpClientStub->method('sendRequest')->willReturn($responseStub);

        $options = new ImageGenerationOptions(model: 'dall-e-2', size: '512x512');
        $results = $this->createSubject()->generateMultiple('A cat', 20, $options);

        self::assertIsString($captured);
        $payload = json_decode($captured, true);
        self::assertIsArray($payload);
        // Count 20 is clamped to the dall-e-2 maximum of 10 in the request body.
        self::assertSame(10, $payload['n']);
        self::assertSame('dall-e-2', $payload['model']);
        self::assertSame('512x512', $payload['size']);

        self::assertCount(10, $results);
        self::assertSame('https://example.com/i.png', $results[0]->url);
        self::assertSame('rev', $results[0]->revisedPrompt);
        self::assertSame('A cat', $results[0]->prompt);
        self::assertSame('512x512', $results[0]->size);
        /** @var array<string, mixed> $metadata */
        $metadata = $results[0]->metadata;
        self::assertSame('standard', $metadata['quality'] ?? null);
    }

    // ==================== createVariations / edit guards + request shaping ====================

    #[Test]
    public function createVariationsThrowsWhenServiceUnavailable(): void
    {
        $subject = $this->createSubjectWithoutApiKey();
        $imageFile = $this->createTestImageFile();

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessageMatches('/is not configured/');

        $subject->createVariations($imageFile);
    }

    #[Test]
    public function createVariationsSendsClampedCountAndFieldsInMultipartBody(): void
    {
        // n is clamped to [1, 10] and shipped as a string form field alongside
        // size and response_format.
        $bodies = [];
        $requestStub = self::createStub(RequestInterface::class);
        $requestStub->method('withHeader')->willReturnSelf();
        $requestStub->method('withBody')->willReturnSelf();
        $this->requestFactoryStub->method('createRequest')->willReturn($requestStub);

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub->method('createStream')->willReturnCallback(
            function (string $body) use (&$bodies, $streamStub): StreamInterface {
                $bodies[] = $body;
                return $streamStub;
            },
        );

        $responseBodyStub = self::createStub(StreamInterface::class);
        $responseBodyStub->method('__toString')->willReturn((string)json_encode([
            'data' => [['url' => 'https://example.com/v.png']],
        ]));
        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn(200);
        $responseStub->method('getBody')->willReturn($responseBodyStub);
        $this->httpClientStub->method('sendRequest')->willReturn($responseStub);

        $subject = $this->createSubject();
        $imageFile = $this->createTestImageFile();

        $subject->createVariations($imageFile);       // default count -> clamped to 1
        $subject->createVariations($imageFile, 0);    // clamped up to 1
        $subject->createVariations($imageFile, 99);   // clamped down to 10

        self::assertCount(3, $bodies);
        self::assertSame('1', $this->multipartFieldValue($bodies[0], 'n'));
        self::assertSame('1', $this->multipartFieldValue($bodies[1], 'n'));
        self::assertSame('10', $this->multipartFieldValue($bodies[2], 'n'));
        self::assertSame('1024x1024', $this->multipartFieldValue($bodies[0], 'size'));
        self::assertSame('url', $this->multipartFieldValue($bodies[0], 'response_format'));
    }

    #[Test]
    public function editThrowsWhenServiceUnavailable(): void
    {
        $subject = $this->createSubjectWithoutApiKey();
        $imageFile = $this->createTestImageFile();

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessageMatches('/is not configured/');

        $subject->edit($imageFile, 'Add a hat');
    }

    #[Test]
    public function editThrowsWhenSourceImageFileNotFound(): void
    {
        // The source image is validated before dispatch: a missing file yields
        // the "not found" message, distinct from the later read failure.
        $subject = $this->createSubject();

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessageMatches('/Image file not found/');

        $subject->edit('/non/existent/source.png', 'Add a hat');
    }

    #[Test]
    public function editSendsPromptAndFieldsInMultipartBody(): void
    {
        // The prompt, size and response_format ride along as form fields.
        $captured = null;
        $requestStub = self::createStub(RequestInterface::class);
        $requestStub->method('withHeader')->willReturnSelf();
        $requestStub->method('withBody')->willReturnSelf();
        $this->requestFactoryStub->method('createRequest')->willReturn($requestStub);

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub->method('createStream')->willReturnCallback(
            function (string $body) use (&$captured, $streamStub): StreamInterface {
                $captured = $body;
                return $streamStub;
            },
        );

        $responseBodyStub = self::createStub(StreamInterface::class);
        $responseBodyStub->method('__toString')->willReturn((string)json_encode([
            'data' => [['url' => 'https://example.com/edited.png']],
        ]));
        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn(200);
        $responseStub->method('getBody')->willReturn($responseBodyStub);
        $this->httpClientStub->method('sendRequest')->willReturn($responseStub);

        $subject = $this->createSubject();
        $imageFile = $this->createTestImageFile();
        $subject->edit($imageFile, 'Add a red hat');

        self::assertIsString($captured);
        self::assertSame('Add a red hat', $this->multipartFieldValue($captured, 'prompt'));
        self::assertSame('1024x1024', $this->multipartFieldValue($captured, 'size'));
        self::assertSame('url', $this->multipartFieldValue($captured, 'response_format'));
    }

    // ==================== gpt-image usage: numeric-string token coercion ====================

    #[Test]
    public function generateWithGptImageModelCoercesNumericStringTokensToInt(): void
    {
        // A usage object may serialise token counts as numeric strings; the
        // service casts them to ints so the recorded metrics stay strictly
        // integer.
        $this->setupSuccessfulRequest([
            'data' => [['b64_json' => base64_encode('png-bytes')]],
            'usage' => [
                'input_tokens' => '50',
                'output_tokens' => '1000',
                'total_tokens' => '1050',
                'input_tokens_details' => ['image_tokens' => 10],
            ],
        ]);

        $this->extensionConfigMock
            ->expects(self::once())->method('get')
            ->with('nr_llm')
            ->willReturn([
                'providers' => ['openai' => ['apiKeyIdentifier' => 'test-api-key']],
            ]);

        $usageTrackerMock = $this->createMock(UsageTrackerServiceInterface::class);
        $usageTrackerMock
            ->expects(self::once())
            ->method('trackUsage')
            ->with(
                'image',
                'dall-e',
                self::callback(
                    static fn(array $metrics): bool => $metrics['tokens'] === 1050
                        && $metrics['promptTokens'] === 50
                        && $metrics['completionTokens'] === 1000,
                ),
                null,
                0,
                'gpt-image-2',
                0,
                null,
            );

        $subject = $this->buildService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $usageTrackerMock,
            $this->loggerStub,
        );

        $subject->generate('A cat', ['model' => 'gpt-image-2']);
    }
}
