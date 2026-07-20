<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Speech;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Specialized\Exception\ServiceConfigurationException;
use Netresearch\NrLlm\Specialized\Exception\ServiceQuotaExceededException;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Option\SpeechSynthesisOptions;
use Netresearch\NrLlm\Specialized\Pricing\SpecializedCostCalculator;
use Netresearch\NrLlm\Specialized\Pricing\SpecializedCostCalculatorInterface;
use Netresearch\NrLlm\Specialized\Speech\SpeechSynthesisResult;
use Netresearch\NrLlm\Specialized\Speech\TextToSpeechService;
use Netresearch\NrLlm\Tests\Fixture\AllowingBudgetService;
use Netresearch\NrLlm\Tests\Fixture\CapturingMiddleware;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Support\InMemoryQueryResult;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(TextToSpeechService::class)]
class TextToSpeechServiceTest extends AbstractUnitTestCase
{
    private ClientInterface&Stub $httpClientStub;
    private RequestFactoryInterface&Stub $requestFactoryStub;
    private StreamFactoryInterface&Stub $streamFactoryStub;
    private ExtensionConfiguration&MockObject $extensionConfigMock;
    private UsageTrackerServiceInterface&Stub $usageTrackerStub;
    private LoggerInterface&Stub $loggerStub;
    private VaultServiceInterface $vaultStub;
    private SpecializedCostCalculatorInterface $costCalculator;

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
        // so cost assertions exercise the real pricing math.
        $modelRepositoryStub = self::createStub(ModelRepository::class);
        $modelRepositoryStub->method('findOneByIdentifier')->willReturn(null);
        $this->costCalculator = new SpecializedCostCalculator($modelRepositoryStub);
    }

    /**
     * Build a TextToSpeechService wired to the vault mock, then inject the given
     * plain HTTP client through the test seam (bypasses the vault secure client
     * so request/response assertions can read the request the service built).
     *
     * @param array{model?: ModelRepository|null, configuration?: LlmConfigurationRepository|null} $repositories
     */
    private function buildService(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        ExtensionConfiguration $extensionConfiguration,
        UsageTrackerServiceInterface $usageTracker,
        LoggerInterface $logger,
        array $repositories = [],
        ?MiddlewarePipeline $pipeline = null,
    ): TextToSpeechService {
        $service = new TextToSpeechService(
            $this->vaultStub,
            $requestFactory,
            $streamFactory,
            $extensionConfiguration,
            $usageTracker,
            $logger,
            $this->costCalculator,
            new AllowingBudgetService(),
            $pipeline ?? new MiddlewarePipeline([]),
            $repositories['model'] ?? null,
            $repositories['configuration'] ?? null,
        );
        $service->setHttpClient($httpClient);

        return $service;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createSubject(
        array $config = [],
        ?ModelRepository $modelRepository = null,
        ?LlmConfigurationRepository $configurationRepository = null,
    ): TextToSpeechService {
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
            ['model' => $modelRepository, 'configuration' => $configurationRepository],
        );
    }

    private function createSubjectWithoutApiKey(): TextToSpeechService
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

    private function setupSuccessfulRequest(string $audioContent = 'audio-binary-content'): void
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
        $responseBodyStub->method('__toString')->willReturn($audioContent);

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

    /**
     * Wire the shared factory/client stubs so the outgoing request is captured
     * verbatim: method + URI passed to createRequest(), each withHeader() name
     * and value, and the JSON body handed to createStream(). Callers MUST
     * initialise the by-ref array with all four keys before invoking (see the
     * call sites) so PHPStan sees a concrete shape and no offset is undefined.
     *
     * @param array{method: string, url: string, body: string, headers: array<string, string>} $captured
     */
    private function setupCapturingRequest(array &$captured, string $audioContent = 'audio-binary-content'): void
    {
        $requestStub = self::createStub(RequestInterface::class);
        $requestStub->method('withHeader')->willReturnCallback(
            static function (string $name, string $value) use (&$captured, $requestStub): RequestInterface {
                $captured['headers'][$name] = $value;

                return $requestStub;
            },
        );
        $requestStub->method('withBody')->willReturnSelf();

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturnCallback(
                static function (string $method, UriInterface|string $uri) use (&$captured, $requestStub): RequestInterface {
                    $captured['method'] = $method;
                    $captured['url']    = (string)$uri;

                    return $requestStub;
                },
            );

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturnCallback(
                static function (string $content) use (&$captured, $streamStub): StreamInterface {
                    $captured['body'] = $content;

                    return $streamStub;
                },
            );

        $responseBodyStub = self::createStub(StreamInterface::class);
        $responseBodyStub->method('__toString')->willReturn($audioContent);

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn(200);
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
    public function getAvailableVoicesReturnsAllVoices(): void
    {
        $subject = $this->createSubject();

        $voices = $subject->getAvailableVoices();

        self::assertArrayHasKey('alloy', $voices);
        self::assertArrayHasKey('echo', $voices);
        self::assertArrayHasKey('fable', $voices);
        self::assertArrayHasKey('onyx', $voices);
        self::assertArrayHasKey('nova', $voices);
        self::assertArrayHasKey('shimmer', $voices);
    }

    #[Test]
    public function getAvailableModelsReturnsAllModels(): void
    {
        $subject = $this->createSubject();

        $models = $subject->getAvailableModels();

        self::assertArrayHasKey('tts-1', $models);
        self::assertArrayHasKey('tts-1-hd', $models);
    }

    #[Test]
    public function getSupportedFormatsReturnsAllFormats(): void
    {
        $subject = $this->createSubject();

        $formats = $subject->getSupportedFormats();

        self::assertContains('mp3', $formats);
        self::assertContains('opus', $formats);
        self::assertContains('aac', $formats);
        self::assertContains('flac', $formats);
        self::assertContains('wav', $formats);
        self::assertContains('pcm', $formats);
    }

    #[Test]
    public function getMaxInputLengthReturns4096(): void
    {
        $subject = $this->createSubject();

        self::assertEquals(4096, $subject->getMaxInputLength());
    }

    // ==================== synthesize tests ====================

    #[Test]
    public function synthesizeReturnsSpeechSynthesisResult(): void
    {
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest('audio-content');

        $result = $subject->synthesize('Hello world');

        self::assertInstanceOf(SpeechSynthesisResult::class, $result);
        self::assertEquals('audio-content', $result->audioContent);
        self::assertEquals('mp3', $result->format);
        self::assertEquals('tts-1', $result->model);
        self::assertEquals('alloy', $result->voice);
    }

    #[Test]
    public function aCallIsRoutedThroughThePipelineWithAServiceContext(): void
    {
        // ADR-097: the HTTP dispatch runs through the shared MiddlewarePipeline
        // with a service-scoped ProviderCallContext (SpeechSynthesis operation).
        $capture = new CapturingMiddleware();

        $this->setupSuccessfulRequest();
        $this->extensionConfigMock
            ->expects(self::once())->method('get')
            ->with('nr_llm')
            ->willReturn(['providers' => ['openai' => ['apiKeyIdentifier' => 'test-api-key']]]);

        $subject = $this->buildService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $this->usageTrackerStub,
            $this->loggerStub,
            [],
            new MiddlewarePipeline([$capture]),
        );

        $subject->synthesize('hello world');

        $captured = $capture->captured;
        self::assertInstanceOf(ProviderCallContext::class, $captured);
        self::assertSame(ProviderOperation::SpeechSynthesis, $captured->operation);
        self::assertNotSame('', $captured->telemetryProvider());
        self::assertNotSame('', $captured->correlationId);
    }

    #[Test]
    public function synthesizeThrowsWhenServiceUnavailable(): void
    {
        $subject = $this->createSubjectWithoutApiKey();

        $this->expectException(ServiceUnavailableException::class);
        // ensureAvailable() fires FIRST, before any request is built — the
        // not-configured message is distinct from the generic API-error
        // message the send path would otherwise raise.
        $this->expectExceptionMessage('Tts service is not configured');

        $subject->synthesize('Hello world');
    }

    #[Test]
    public function synthesizeThrowsOnEmptyText(): void
    {
        $subject = $this->createSubject();

        $this->expectException(ServiceUnavailableException::class);
        // trim('   ') is empty, so validateInput() rejects it with this exact
        // message — pins both the trim() unwrap and the throw itself.
        $this->expectExceptionMessage('Input text cannot be empty');

        $subject->synthesize('   ');
    }

    #[Test]
    public function synthesizeThrowsOnTextTooLong(): void
    {
        $subject = $this->createSubject();
        $longText = str_repeat('a', 4097);

        $this->expectException(ServiceUnavailableException::class);
        // Over-length input is rejected by validateInput() before any request
        // — pins the throw against the generic send-path error message.
        $this->expectExceptionMessage('Input text exceeds maximum length');

        $subject->synthesize($longText);
    }

    #[Test]
    public function synthesizeTracksUsage(): void
    {
        $this->setupSuccessfulRequest();

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
                'speech',
                'tts',
                self::callback(
                    // 'Test text' = 9 characters at the tts-1 list price of
                    // $15 per 1M characters.
                    fn(array $metrics): bool => $metrics['characters'] === 9
                        && is_float($metrics['cost']) && abs($metrics['cost'] - 9 * 15.00 / 1_000_000) < 1e-12,
                ),
                null,
                0,
                'tts-1',
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

        $subject->synthesize('Test text');
    }

    #[Test]
    public function synthesizeAttributesUsageToOptionUid(): void
    {
        // ADR-057: a caller-supplied beUserUid in the options reaches the
        // usage row instead of the ambient fallback.
        $this->setupSuccessfulRequest();

        $this->extensionConfigMock
            ->expects(self::once())->method('get')
            ->with('nr_llm')
            ->willReturn(['providers' => ['openai' => ['apiKeyIdentifier' => 'test-api-key']]]);

        $usageTrackerMock = $this->createMock(UsageTrackerServiceInterface::class);
        $usageTrackerMock
            ->expects(self::once())
            ->method('trackUsage')
            ->with(
                'speech',
                'tts',
                self::callback(static fn(array $metrics): bool => $metrics['characters'] === 9),
                null,
                0,
                'tts-1',
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

        $subject->synthesize('Test text', new SpeechSynthesisOptions(beUserUid: 42));
    }

    #[Test]
    public function synthesizeLinksUsageRowToRegistryRecordUid(): void
    {
        // When the used model id matches a tx_nrllm_model record, the
        // usage row carries that record's uid so the Analytics model
        // breakdowns link back to the registry.
        $record = new Model();
        $record->setModelId('tts-1');
        $record->_setProperty('uid', 7);

        $modelRepository = $this->createMock(ModelRepository::class);
        $modelRepository->expects(self::once())->method('findOneByModelId')->with('tts-1')->willReturn($record);

        $this->setupSuccessfulRequest();
        $this->extensionConfigMock
            ->expects(self::once())->method('get')
            ->with('nr_llm')
            ->willReturn(['providers' => ['openai' => ['apiKeyIdentifier' => 'test-api-key']]]);

        $usageTrackerMock = $this->createMock(UsageTrackerServiceInterface::class);
        $usageTrackerMock
            ->expects(self::once())
            ->method('trackUsage')
            ->with(
                'speech',
                'tts',
                self::callback(static fn(array $metrics): bool => $metrics['characters'] === 9),
                null,
                7,
                'tts-1',
            );

        $subject = $this->buildService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $usageTrackerMock,
            $this->loggerStub,
            ['model' => $modelRepository],
        );

        $subject->synthesize('Test text');
    }

    #[Test]
    public function resolveDefaultModelPrefersDefaultFlaggedTextToSpeechRecord(): void
    {
        $regular = new Model();
        $regular->setModelId('tts-1');
        $default = new Model();
        $default->setModelId('tts-1-hd');
        $default->setIsDefault(true);

        $modelRepository = $this->createMock(ModelRepository::class);
        $modelRepository->expects(self::once())
            ->method('findByCapability')
            ->with('text_to_speech')
            ->willReturn(new InMemoryQueryResult([$regular, $default]));

        $subject = $this->createSubject(modelRepository: $modelRepository);

        self::assertSame('tts-1-hd', $subject->resolveDefaultModel('tts-1'));
    }

    #[Test]
    public function resolveDefaultModelReturnsFallbackWhenNoTextToSpeechRecordExists(): void
    {
        $modelRepository = $this->createMock(ModelRepository::class);
        $modelRepository->method('findByCapability')->willReturn(new InMemoryQueryResult([]));

        $subject = $this->createSubject(modelRepository: $modelRepository);

        self::assertSame('tts-1', $subject->resolveDefaultModel('tts-1'));
    }

    #[Test]
    public function resolveDefaultModelReturnsFallbackWhenRepositoryThrows(): void
    {
        $modelRepository = $this->createMock(ModelRepository::class);
        $modelRepository->method('findByCapability')
            ->willThrowException(new RuntimeException('persistence unavailable'));

        $subject = $this->createSubject(modelRepository: $modelRepository);

        self::assertSame('tts-1', $subject->resolveDefaultModel('tts-1'));
    }

    #[Test]
    public function resolveModelForConfigurationUsesConfiguredModel(): void
    {
        $model = new Model();
        $model->setModelId('tts-1-hd');

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('podcast-narration');
        $configuration->setLlmModel($model);

        $configurationRepository = $this->createMock(LlmConfigurationRepository::class);
        $configurationRepository->expects(self::once())
            ->method('findOneByIdentifier')
            ->with('podcast-narration')
            ->willReturn($configuration);

        $subject = $this->createSubject(configurationRepository: $configurationRepository);

        self::assertSame('tts-1-hd', $subject->resolveModelForConfiguration('podcast-narration', 'tts-1'));
    }

    #[Test]
    public function resolveModelForConfigurationFallsBackToTextToSpeechCapabilityDefault(): void
    {
        // Unknown configuration identifier: the capability-based registry
        // default applies — for this service the `text_to_speech` capability.
        $configurationRepository = self::createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findOneByIdentifier')->willReturn(null);

        $registryDefault = new Model();
        $registryDefault->setModelId('tts-1-hd');

        $modelRepository = $this->createMock(ModelRepository::class);
        $modelRepository->expects(self::once())
            ->method('findByCapability')
            ->with('text_to_speech')
            ->willReturn(new InMemoryQueryResult([$registryDefault]));

        $subject = $this->createSubject(
            modelRepository: $modelRepository,
            configurationRepository: $configurationRepository,
        );

        self::assertSame('tts-1-hd', $subject->resolveModelForConfiguration('unknown', 'tts-1'));
    }

    #[Test]
    public function resolveModelForConfigurationReturnsFallbackWithoutRepositories(): void
    {
        $subject = $this->createSubject();

        self::assertSame('tts-1', $subject->resolveModelForConfiguration('podcast-narration', 'tts-1'));
    }

    #[Test]
    public function getConfigurationSystemPromptReturnsPromptOfActiveConfiguration(): void
    {
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('podcast-narration');
        $configuration->setSystemPrompt('Speak slowly and clearly.');

        $configurationRepository = self::createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findOneByIdentifier')->willReturn($configuration);

        $subject = $this->createSubject(configurationRepository: $configurationRepository);

        self::assertSame('Speak slowly and clearly.', $subject->getConfigurationSystemPrompt('podcast-narration'));
    }

    #[Test]
    public function synthesizeLinksUsageRowToConfigurationUid(): void
    {
        // When the options carry an LlmConfiguration identifier, the usage
        // row links to that configuration record so the Analytics module
        // can aggregate speech spend per configuration.
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('podcast-narration');
        $configuration->_setProperty('uid', 11);

        $configurationRepository = $this->createMock(LlmConfigurationRepository::class);
        // Called twice now: once by the budget pre-flight, once for the usage
        // row's configuration link. The count is not what this test is about.
        $configurationRepository->method('findOneByIdentifier')
            ->with('podcast-narration')
            ->willReturn($configuration);

        $this->setupSuccessfulRequest();
        $this->extensionConfigMock
            ->expects(self::once())->method('get')
            ->with('nr_llm')
            ->willReturn(['providers' => ['openai' => ['apiKeyIdentifier' => 'test-api-key']]]);

        $usageTrackerMock = $this->createMock(UsageTrackerServiceInterface::class);
        $usageTrackerMock
            ->expects(self::once())
            ->method('trackUsage')
            ->with(
                'speech',
                'tts',
                self::callback(static fn(array $metrics): bool => $metrics['characters'] === 9),
                11,
                0,
                'tts-1',
            );

        $subject = $this->buildService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $usageTrackerMock,
            $this->loggerStub,
            ['configuration' => $configurationRepository],
        );

        $subject->synthesize('Test text', ['configuration' => 'podcast-narration']);
    }

    #[Test]
    public function synthesizeWithOptions(): void
    {
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest();

        $options = new SpeechSynthesisOptions(
            voice: 'nova',
            model: 'tts-1-hd',
            format: 'opus',
            speed: 1.5,
        );

        $result = $subject->synthesize('Hello world', $options);

        self::assertInstanceOf(SpeechSynthesisResult::class, $result);
    }

    #[Test]
    public function synthesizeWithArrayOptions(): void
    {
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest();

        $options = [
            'voice' => 'shimmer',
            'model' => 'tts-1-hd',
        ];

        $result = $subject->synthesize('Hello world', $options);

        self::assertInstanceOf(SpeechSynthesisResult::class, $result);
    }

    // ==================== synthesizeToFile tests ====================

    #[Test]
    public function synthesizeToFileReturnsSpeechSynthesisResult(): void
    {
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest('audio-content');

        $tempFile = tempnam(sys_get_temp_dir(), 'tts_test_');
        if ($tempFile === false) {
            self::markTestSkipped('Could not create temporary file');
        }

        try {
            $result = $subject->synthesizeToFile('Hello world', $tempFile);

            self::assertInstanceOf(SpeechSynthesisResult::class, $result);
            self::assertFileExists($tempFile);
            self::assertEquals('audio-content', file_get_contents($tempFile));
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    #[Test]
    public function synthesizeToFileThrowsOnSaveFailure(): void
    {
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest();

        try {
            $subject->synthesizeToFile('Hello world', '/non/existent/path/file.mp3');
            self::fail('Expected ServiceUnavailableException was not thrown');
        } catch (ServiceUnavailableException $e) {
            // The exception names the failed path and carries the provider
            // context used by upstream error reporting.
            self::assertStringContainsString('/non/existent/path/file.mp3', $e->getMessage());
            self::assertSame('speech', $e->service);
            self::assertSame(['provider' => 'tts'], $e->context);
        }
    }

    // ==================== synthesizeLong tests ====================

    #[Test]
    public function synthesizeLongReturnsArrayOfResults(): void
    {
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest();

        $result = $subject->synthesizeLong('Short text');

        self::assertCount(1, $result);
        self::assertInstanceOf(SpeechSynthesisResult::class, $result[0]);
    }

    #[Test]
    public function synthesizeLongSplitsLongText(): void
    {
        // Create text longer than 4096 characters
        $longText = str_repeat('Hello world. ', 400); // ~5200 chars

        // Create fresh stubs for multiple calls
        $httpClientStub = self::createStub(ClientInterface::class);
        $requestFactoryStub = self::createStub(RequestFactoryInterface::class);
        $streamFactoryStub = self::createStub(StreamFactoryInterface::class);
        $extensionConfigStub = self::createStub(ExtensionConfiguration::class);

        $requestStub = self::createStub(RequestInterface::class);
        $requestStub->method('withHeader')->willReturnSelf();
        $requestStub->method('withBody')->willReturnSelf();

        $requestFactoryStub
            ->method('createRequest')
            ->willReturn($requestStub);

        $streamStub = self::createStub(StreamInterface::class);
        $streamFactoryStub
            ->method('createStream')
            ->willReturn($streamStub);

        $responseBodyStub = self::createStub(StreamInterface::class);
        $responseBodyStub->method('__toString')->willReturn('audio-chunk');

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn(200);
        $responseStub->method('getBody')->willReturn($responseBodyStub);

        $httpClientStub
            ->method('sendRequest')
            ->willReturn($responseStub);

        $extensionConfigStub
            ->method('get')
            ->willReturn([
                'providers' => [
                    'openai' => [
                        'apiKeyIdentifier' => 'test-api-key',
                    ],
                ],
            ]);

        $subject = $this->buildService(
            $httpClientStub,
            $requestFactoryStub,
            $streamFactoryStub,
            $extensionConfigStub,
            $this->usageTrackerStub,
            $this->loggerStub,
        );

        $result = $subject->synthesizeLong($longText);

        self::assertGreaterThan(1, count($result));
    }

    #[Test]
    public function splitTextIntoChunksHardSplitsAnOverLimitClause(): void
    {
        // A comma-delimited sentence whose first clause already exceeds the
        // input limit must be hard-split: emitting the clause whole would
        // produce an over-limit chunk that synthesize() rejects.
        $subject = $this->createSubject();

        $firstClause = str_repeat('a', 5000); // > MAX_INPUT_LENGTH (4096)
        $sentence = $firstClause . ', tail clause.';

        $reflection = new ReflectionClass($subject);
        $chunks = $reflection->getMethod('splitTextIntoChunks')->invoke($subject, $sentence);

        self::assertIsArray($chunks);
        self::assertNotEmpty($chunks);
        foreach ($chunks as $chunk) {
            self::assertIsString($chunk);
            self::assertLessThanOrEqual(4096, mb_strlen($chunk));
        }
    }

    #[Test]
    public function splitTextIntoChunksHardSplitKeepsMultibyteCharactersIntact(): void
    {
        // The hard-split must cut at character boundaries: a byte-wise split
        // would slice multibyte UTF-8 sequences in half and feed invalid
        // UTF-8 to the synthesis API. 5000 two-byte characters exceed the
        // 4096-character limit, so the clause is hard-split.
        $subject = $this->createSubject();

        $sentence = str_repeat('ü', 5000) . ', tail clause.';

        $reflection = new ReflectionClass($subject);
        $chunks = $reflection->getMethod('splitTextIntoChunks')->invoke($subject, $sentence);

        self::assertIsArray($chunks);
        self::assertNotEmpty($chunks);
        foreach ($chunks as $chunk) {
            self::assertIsString($chunk);
            self::assertTrue(mb_check_encoding($chunk, 'UTF-8'), 'Chunk must remain valid UTF-8');
            self::assertLessThanOrEqual(4096, mb_strlen($chunk));
        }
    }

    // ==================== API error handling tests ====================

    #[Test]
    public function synthesizeThrowsOnUnauthorized(): void
    {
        $subject = $this->createSubject();
        $this->setupFailedRequest(401, 'Invalid API key');

        $this->expectException(ServiceConfigurationException::class);

        $subject->synthesize('Hello world');
    }

    #[Test]
    public function synthesizeThrowsOnForbidden(): void
    {
        $subject = $this->createSubject();
        $this->setupFailedRequest(403, 'Forbidden');

        $this->expectException(ServiceConfigurationException::class);

        $subject->synthesize('Hello world');
    }

    #[Test]
    public function synthesizeThrowsOnRateLimitExceeded(): void
    {
        $subject = $this->createSubject();
        $this->setupFailedRequest(429, 'Rate limit exceeded');

        $this->expectException(ServiceQuotaExceededException::class);

        $subject->synthesize('Hello world');
    }

    #[Test]
    public function synthesizeThrowsOnServerError(): void
    {
        $subject = $this->createSubject();
        $this->setupFailedRequest(500, 'Internal server error');

        $this->expectException(ServiceUnavailableException::class);

        $subject->synthesize('Hello world');
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
    public function loadConfigurationUsesCustomBaseUrl(): void
    {
        $config = [
            'providers' => [
                'openai' => [
                    'apiKeyIdentifier' => 'test-api-key',
                ],
            ],
            'speech' => [
                'tts' => [
                    'baseUrl' => 'https://custom-api.example.com/v1/audio/speech',
                    'timeout' => 120,
                ],
            ],
        ];

        $subject = $this->createSubject($config);

        // Just verify the service is created without errors
        self::assertTrue($subject->isAvailable());
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

    #[Test]
    public function synthesizeLongSplitsTextAtSentenceBoundaries(): void
    {
        $subject = $this->createSubject();

        // Create text longer than 4096 chars with sentence boundaries
        $sentence = 'This is a test sentence. ';
        $text = str_repeat($sentence, 200); // ~5000 chars

        $this->setupSuccessfulRequest('audio-chunk');

        $result = $subject->synthesizeLong($text);

        // Should return multiple results
        self::assertGreaterThan(1, count($result));
    }

    #[Test]
    public function synthesizeLongReturnsArrayWithSingleResultForShortText(): void
    {
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest();

        $result = $subject->synthesizeLong('Short text');

        self::assertCount(1, $result);
        self::assertInstanceOf(SpeechSynthesisResult::class, $result[0]);
    }

    #[Test]
    public function synthesizeLongHandlesVeryLongSentences(): void
    {
        $subject = $this->createSubject();

        // Create text with a very long sentence (no periods)
        $longWord = str_repeat('word ', 1000); // ~5000 chars without periods
        $text = $longWord . '. Another sentence.';

        $this->setupSuccessfulRequest('audio-chunk');

        $result = $subject->synthesizeLong($text);

        // Should split even the long sentence
        self::assertGreaterThan(1, count($result));
    }

    #[Test]
    public function synthesizeLongHandlesCommaDelimitedSentences(): void
    {
        $subject = $this->createSubject();

        // Create text with comma-separated parts
        $part = 'this is a comma-delimited part, ';
        $text = str_repeat($part, 200); // ~6600 chars

        $this->setupSuccessfulRequest('audio-chunk');

        $result = $subject->synthesizeLong($text);

        self::assertGreaterThan(1, count($result));
    }

    #[Test]
    public function sendRequestHandles429RateLimitError(): void
    {
        $subject = $this->createSubject();

        $requestStub = self::createStub(RequestInterface::class);
        $requestStub->method('withHeader')->willReturnSelf();
        $requestStub->method('withBody')->willReturnSelf();

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($requestStub);

        $bodyStub = self::createStub(StreamInterface::class);
        $bodyStub->method('__toString')->willReturn('{}');

        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($bodyStub);

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn(429);
        $responseStub->method('getBody')->willReturn($bodyStub);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($responseStub);

        $this->expectException(ServiceQuotaExceededException::class);
        $this->expectExceptionMessage('Rate limit exceeded');

        $subject->synthesize('Test text');
    }

    #[Test]
    public function sendRequestHandlesGenericApiError(): void
    {
        $subject = $this->createSubject();

        $requestStub = self::createStub(RequestInterface::class);
        $requestStub->method('withHeader')->willReturnSelf();
        $requestStub->method('withBody')->willReturnSelf();

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($requestStub);

        $bodyStub = self::createStub(StreamInterface::class);
        $bodyStub->method('__toString')->willReturn('{"error": {"message": "Server error"}}');

        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($bodyStub);

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn(500);
        $responseStub->method('getBody')->willReturn($bodyStub);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($responseStub);

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('Server error');

        $subject->synthesize('Test text');
    }

    #[Test]
    public function synthesizeWithOptionsObject(): void
    {
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest();

        $options = new SpeechSynthesisOptions(
            voice: 'nova',
            model: 'tts-1-hd',
            format: 'opus',
            speed: 1.5,
        );

        $result = $subject->synthesize('Test text', $options);

        self::assertInstanceOf(SpeechSynthesisResult::class, $result);
        self::assertEquals('nova', $result->voice);
        self::assertEquals('tts-1-hd', $result->model);
        self::assertEquals('opus', $result->format);
        // The effective speed is echoed into the result metadata — the only
        // place a caller can read back what rate was actually requested.
        self::assertSame(['speed' => 1.5], $result->metadata);
    }

    #[Test]
    public function loadConfigurationHandlesNonArrayConfig(): void
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
    public function loadConfigurationHandlesNonArrayProviders(): void
    {
        $this->extensionConfigMock
            ->expects(self::once())->method('get')
            ->with('nr_llm')
            ->willReturn([
                'providers' => 'not-an-array',
            ]);

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
    public function synthesizeLongHandlesVeryLongSentenceWithNoCommasOrPeriods(): void
    {
        $subject = $this->createSubject();

        // Create a single very long word-like string with no sentence breaks or commas
        // This triggers the force-split path in splitLongSentence()
        $longWord = str_repeat('A', 5000); // 5000 chars, no commas or periods
        $text = $longWord;

        $this->setupSuccessfulRequest('audio-chunk');

        $result = $subject->synthesizeLong($text);

        // Should split into multiple chunks even without natural break points
        self::assertGreaterThan(1, count($result));
        foreach ($result as $chunk) {
            self::assertInstanceOf(SpeechSynthesisResult::class, $chunk);
        }
    }

    #[Test]
    public function synthesizeLongWithTextExactlyAtMaxLengthReturnsSingleResult(): void
    {
        $subject = $this->createSubject();

        // Text at exactly max length should NOT be split
        $text = str_repeat('a', 4096);

        $this->setupSuccessfulRequest('audio-chunk');

        $result = $subject->synthesizeLong($text);

        self::assertCount(1, $result);
    }

    #[Test]
    public function synthesizeWithExactMaxLengthTextSucceeds(): void
    {
        $subject = $this->createSubject();

        // Text at exactly max length should succeed
        $text = str_repeat('x', 4096);

        $this->setupSuccessfulRequest('audio-content');

        $result = $subject->synthesize($text);

        self::assertInstanceOf(SpeechSynthesisResult::class, $result);
        self::assertEquals(4096, $result->characterCount);
    }

    #[Test]
    public function sendRequestHandlesConnectionException(): void
    {
        $subject = $this->createSubject();

        $requestStub = self::createStub(RequestInterface::class);
        $requestStub->method('withHeader')->willReturnSelf();
        $requestStub->method('withBody')->willReturnSelf();

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($requestStub);

        $bodyStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($bodyStub);

        $this->httpClientStub
            ->method('sendRequest')
            ->willThrowException(new RuntimeException('Connection refused'));

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('Failed to connect to TTS API');

        $subject->synthesize('Test text');
    }

    #[Test]
    public function sendRequestHandlesApiErrorWithNonJsonBody(): void
    {
        $subject = $this->createSubject();

        $requestStub = self::createStub(RequestInterface::class);
        $requestStub->method('withHeader')->willReturnSelf();
        $requestStub->method('withBody')->willReturnSelf();

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($requestStub);

        $bodyStub = self::createStub(StreamInterface::class);
        $bodyStub->method('__toString')->willReturn('not valid json at all');

        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($bodyStub);

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn(500);
        $responseStub->method('getBody')->willReturn($bodyStub);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($responseStub);

        $this->expectException(ServiceUnavailableException::class);

        $subject->synthesize('Test text');
    }

    #[Test]
    public function synthesizeLongWithCommaSplitWhenChunkOverflowsOnLastPart(): void
    {
        $subject = $this->createSubject();

        // Build a text that has comma-delimited sentences each just over 4096/2 chars
        // The text itself is over 4096 chars, with one very long sentence containing commas
        $longPart = str_repeat('word ', 1000); // ~5000 chars without periods
        // This creates a text with a very long sentence that will be comma-split
        $text = $longPart;

        $this->setupSuccessfulRequest('chunk');

        $result = $subject->synthesizeLong($text);

        self::assertGreaterThanOrEqual(1, count($result));
    }

    // ==================== outgoing-request payload tests ====================

    #[Test]
    public function synthesizeSendsExactPostPayloadWithDefaults(): void
    {
        $subject = $this->createSubject();

        $captured = ['method' => '', 'url' => '', 'body' => '', 'headers' => []];
        $this->setupCapturingRequest($captured);

        $subject->synthesize('Hello world');

        self::assertSame('POST', $captured['method']);
        // Default config leaves speech.tts.baseUrl unset, so the base falls back
        // to getDefaultBaseUrl(); buildEndpointUrl('') is that base verbatim.
        self::assertSame('https://api.openai.com/v1/audio/speech', $captured['url']);
        self::assertSame('application/json', $captured['headers']['Content-Type']);

        $decoded = json_decode($captured['body'], true);
        self::assertIsArray($decoded);
        self::assertSame('tts-1', $decoded['model']);
        self::assertSame('Hello world', $decoded['input']);
        self::assertSame('alloy', $decoded['voice']);
        self::assertSame('mp3', $decoded['response_format']);
        self::assertEqualsWithDelta(1.0, $decoded['speed'], 1e-9);
    }

    #[Test]
    public function synthesizeSendsProvidedOptionValuesInPayload(): void
    {
        $subject = $this->createSubject();

        $captured = ['method' => '', 'url' => '', 'body' => '', 'headers' => []];
        $this->setupCapturingRequest($captured);

        $options = new SpeechSynthesisOptions(
            model: 'tts-1-hd',
            voice: 'nova',
            format: 'opus',
            speed: 1.5,
        );

        $subject->synthesize('Hello world', $options);

        $decoded = json_decode($captured['body'], true);
        self::assertIsArray($decoded);
        self::assertSame('tts-1-hd', $decoded['model']);
        self::assertSame('Hello world', $decoded['input']);
        self::assertSame('nova', $decoded['voice']);
        self::assertSame('opus', $decoded['response_format']);
        self::assertEqualsWithDelta(1.5, $decoded['speed'], 1e-9);
    }

    #[Test]
    public function synthesizeTargetsConfiguredCustomBaseUrl(): void
    {
        $subject = $this->createSubject([
            'speech' => [
                'tts' => [
                    'baseUrl' => 'https://custom-api.example.com/v1/audio/speech',
                ],
            ],
        ]);

        $captured = ['method' => '', 'url' => '', 'body' => '', 'headers' => []];
        $this->setupCapturingRequest($captured);

        $subject->synthesize('Hello world');

        // The custom base URL only reaches the request when the config is read
        // from the speech.tts branch; a wrong service path silently falls back
        // to the default endpoint.
        self::assertSame('https://custom-api.example.com/v1/audio/speech', $captured['url']);
    }

    #[Test]
    public function synthesizeCountsMultibyteCharactersByCodepoint(): void
    {
        $this->setupSuccessfulRequest();

        $this->extensionConfigMock
            ->expects(self::once())->method('get')
            ->with('nr_llm')
            ->willReturn(['providers' => ['openai' => ['apiKeyIdentifier' => 'test-api-key']]]);

        // 3000 two-byte characters: mb_strlen 3000, strlen 6000. A byte-length
        // count would over-report AND trip the 4096 length guard.
        $text = str_repeat('ü', 3000);

        $usageTrackerMock = $this->createMock(UsageTrackerServiceInterface::class);
        $usageTrackerMock
            ->expects(self::once())
            ->method('trackUsage')
            ->with(
                'speech',
                'tts',
                self::callback(static fn(array $metrics): bool => $metrics['characters'] === 3000),
                null,
                0,
                'tts-1',
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

        $result = $subject->synthesize($text);

        self::assertSame(3000, $result->characterCount);
    }

    #[Test]
    public function getDefaultTimeoutReturnsSixtySeconds(): void
    {
        $subject = $this->createSubject();

        $reflection = new ReflectionClass($subject);
        $timeout = $reflection->getMethod('getDefaultTimeout')->invoke($subject);

        self::assertSame(60, $timeout);
    }

    #[Test]
    public function resolveDefaultModelSkipsModelIdOutsideTtsVocabulary(): void
    {
        // A registry record carrying the text_to_speech capability but whose
        // model id is neither tts-*  nor *-tts must be skipped: this service
        // cannot speak it, so resolution falls back to the given default.
        $foreign = new Model();
        $foreign->setModelId('dall-e-3');

        $modelRepository = $this->createMock(ModelRepository::class);
        $modelRepository->method('findByCapability')->willReturn(new InMemoryQueryResult([$foreign]));

        $subject = $this->createSubject(modelRepository: $modelRepository);

        self::assertSame('tts-1', $subject->resolveDefaultModel('tts-1'));
    }

    #[Test]
    public function synthesizeLongAcceptsOptionsObjectForShortText(): void
    {
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest();

        $result = $subject->synthesizeLong('Short text', new SpeechSynthesisOptions(voice: 'nova'));

        self::assertCount(1, $result);
        // The already-normalised options object must pass through untouched;
        // re-running fromArray() on it would be a type error.
        self::assertSame('nova', $result[0]->voice);
    }

    #[Test]
    public function splitTextIntoChunksJoinsSentencesWithSingleSpace(): void
    {
        $subject = $this->createSubject();

        $reflection = new ReflectionClass($subject);
        $chunks = $reflection->getMethod('splitTextIntoChunks')->invoke($subject, 'Aa. Bb.');

        // Two sentences that fit within the limit are re-joined with exactly one
        // space between them — pins the concatenation order and separator.
        self::assertSame(['Aa. Bb.'], $chunks);
    }

    #[Test]
    public function splitTextIntoChunksCombinesUpToTheCharacterLimit(): void
    {
        $subject = $this->createSubject();

        // Two sentences whose combined length (2048 + 1 space + 2047) is exactly
        // MAX_INPUT_LENGTH: with `<=` they merge into one chunk; a strict `<`
        // would split them.
        $first  = str_repeat('a', 2047) . '.';
        $second = str_repeat('b', 2046) . '.';

        $reflection = new ReflectionClass($subject);
        $chunks = $reflection->getMethod('splitTextIntoChunks')->invoke($subject, $first . ' ' . $second);

        self::assertIsArray($chunks);
        self::assertCount(1, $chunks);
    }

    #[Test]
    public function splitTextIntoChunksMeasuresCombinedLengthByCodepoint(): void
    {
        $subject = $this->createSubject();

        // Two multibyte sentences: combined codepoint length 3003 (<= limit, so
        // one chunk), but combined byte length 6003 would exceed it. A byte-wise
        // measure would wrongly split them.
        $first  = str_repeat('ü', 1500) . '.';
        $second = str_repeat('ü', 1500) . '.';

        $reflection = new ReflectionClass($subject);
        $chunks = $reflection->getMethod('splitTextIntoChunks')->invoke($subject, $first . ' ' . $second);

        self::assertIsArray($chunks);
        self::assertCount(1, $chunks);
    }

    #[Test]
    public function splitTextIntoChunksSplitsAtSentenceBoundariesNotFixedWidth(): void
    {
        $subject = $this->createSubject();

        // Two 4001-char sentences (8003 chars total) split at the sentence
        // boundary: the first chunk is the whole first sentence (4001 chars),
        // not a blind 4096-char slice.
        $first  = str_repeat('a', 4000) . '.';
        $second = str_repeat('b', 4000) . '.';

        $reflection = new ReflectionClass($subject);
        $chunks = $reflection->getMethod('splitTextIntoChunks')->invoke($subject, $first . ' ' . $second);

        self::assertIsArray($chunks);
        self::assertCount(2, $chunks);
        self::assertIsString($chunks[0]);
        self::assertSame(4001, mb_strlen($chunks[0]));
    }

    #[Test]
    public function splitTextIntoChunksMeasuresSentenceLengthByCodepoint(): void
    {
        $subject = $this->createSubject();

        // A 3001-codepoint multibyte sentence (6001 bytes) is within the limit
        // and combines with the short tail into a single chunk. A byte-wise
        // over-limit check would hard-split it and yield two chunks.
        $sentence = str_repeat('ü', 3000) . '.';

        $reflection = new ReflectionClass($subject);
        $chunks = $reflection->getMethod('splitTextIntoChunks')->invoke($subject, $sentence . ' Tail.');

        self::assertIsArray($chunks);
        self::assertCount(1, $chunks);
    }

    // ==================== second-pass mutation pins ====================

    #[Test]
    public function synthesizeLongSendsExactMaxLengthMultibyteTextVerbatim(): void
    {
        $subject = $this->createSubject();

        $captured = ['method' => '', 'url' => '', 'body' => '', 'headers' => []];
        $this->setupCapturingRequest($captured);

        // Codepoint length exactly 4096 (2001 + 2 + 2093) but BYTE length
        // 8188: the short-text branch must measure by codepoint and take
        // the text as-is. Any mutant that falls into the split path re-joins
        // the two sentences with a SINGLE space, so the double space between
        // them is the discriminator visible in the outgoing payload.
        $text = str_repeat('ü', 2000) . '.' . '  ' . str_repeat('ü', 2092) . '.';

        $results = $subject->synthesizeLong($text);

        self::assertCount(1, $results);
        $decoded = json_decode($captured['body'], true);
        self::assertIsArray($decoded);
        self::assertSame($text, $decoded['input']);
    }

    #[Test]
    public function splitTextIntoChunksKeepsExactLimitSentenceVerbatim(): void
    {
        $subject = $this->createSubject();

        // The second sentence is exactly 4096 characters and ends in ', '.
        // At exactly the limit it is NOT long-split — the whole sentence
        // becomes one chunk, trailing separator intact. A `>=` boundary
        // mutant routes it through splitLongSentence(), whose flush rtrims
        // the trailing ', ' away.
        $sentence = str_repeat('x', 4094) . ', ';
        $text = 'Intro. ' . $sentence;

        $reflection = new ReflectionClass($subject);
        $chunks = $reflection->getMethod('splitTextIntoChunks')->invoke($subject, $text);

        self::assertSame(['Intro.', $sentence], $chunks);
    }

    #[Test]
    public function splitTextIntoChunksStripsTrailingSeparatorFromFinalCommaChunk(): void
    {
        $subject = $this->createSubject();

        // Over-limit sentence ending in ', ': explode() yields a trailing
        // empty part, so the final running chunk still carries its ', '
        // separator — the flush in splitLongSentence() must rtrim it.
        $text = str_repeat('a', 4000) . ', ' . str_repeat('b', 1000) . ', ';

        $reflection = new ReflectionClass($subject);
        $chunks = $reflection->getMethod('splitTextIntoChunks')->invoke($subject, $text);

        self::assertSame([str_repeat('a', 4000), str_repeat('b', 1000)], $chunks);
    }

    #[Test]
    public function splitTextIntoChunksMergesCommaPartsUpToExactLimit(): void
    {
        $subject = $this->createSubject();

        // Second chunk is 1202 + 2894 = exactly 4096 characters: the last
        // part merges only because its separator is '' (it IS the last part)
        // and the comparison is `<=`. Mutants that give the last part a ', '
        // separator (lastIndex off-by-one, `<`→`<=`, swapped ternary) or use
        // strict `<` push it to 4098 / fail at 4096 and emit three chunks;
        // concat-order mutants corrupt the merged chunk's content.
        $text = str_repeat('a', 3000) . ', ' . str_repeat('b', 1200) . ', ' . str_repeat('c', 2893) . '.';

        $reflection = new ReflectionClass($subject);
        $chunks = $reflection->getMethod('splitTextIntoChunks')->invoke($subject, $text);

        self::assertSame(
            [
                str_repeat('a', 3000),
                str_repeat('b', 1200) . ', ' . str_repeat('c', 2893) . '.',
            ],
            $chunks,
        );
    }

    #[Test]
    public function splitTextIntoChunksPreservesSeparatorBetweenLeadingMergedParts(): void
    {
        $subject = $this->createSubject();

        // The first two parts merge into one chunk; the ', ' between them is
        // part of the chunk content (only TRAILING separators are trimmed).
        $text = str_repeat('a', 2000) . ', ' . str_repeat('b', 2000) . ', ' . str_repeat('c', 3000) . '.';

        $reflection = new ReflectionClass($subject);
        $chunks = $reflection->getMethod('splitTextIntoChunks')->invoke($subject, $text);

        self::assertSame(
            [
                str_repeat('a', 2000) . ', ' . str_repeat('b', 2000),
                str_repeat('c', 3000) . '.',
            ],
            $chunks,
        );
    }

    #[Test]
    public function splitTextIntoChunksPreservesSeparatorBeforeFinalMergedPart(): void
    {
        $subject = $this->createSubject();

        // Four parts; the last three merge into the second chunk. The ', '
        // between the second-to-last and last part must survive — an
        // off-by-one lastIndex hands the second-to-last part an empty
        // separator and glues 'c…' and 'd…' together.
        $text = str_repeat('a', 3000) . ', ' . str_repeat('b', 1200)
            . ', ' . str_repeat('c', 100) . ', ' . str_repeat('d', 100) . '.';

        $reflection = new ReflectionClass($subject);
        $chunks = $reflection->getMethod('splitTextIntoChunks')->invoke($subject, $text);

        self::assertSame(
            [
                str_repeat('a', 3000),
                str_repeat('b', 1200) . ', ' . str_repeat('c', 100) . ', ' . str_repeat('d', 100) . '.',
            ],
            $chunks,
        );
    }

    #[Test]
    public function splitTextIntoChunksAccountsForSeparatorWhenMergingCommaParts(): void
    {
        $subject = $this->createSubject();

        // Merging part two INCLUDING its ', ' separator would hit 4098 > 4096,
        // so the chunk is flushed as part one only. A mutant that measures the
        // test chunk WITHOUT the separator sees 4096 <= 4096, merges, and
        // produces ['a…, b…', 'c….'] instead.
        $text = str_repeat('a', 2000) . ', ' . str_repeat('b', 2094) . ', ' . str_repeat('c', 500) . '.';

        $reflection = new ReflectionClass($subject);
        $chunks = $reflection->getMethod('splitTextIntoChunks')->invoke($subject, $text);

        self::assertSame(
            [
                str_repeat('a', 2000),
                str_repeat('b', 2094) . ', ' . str_repeat('c', 500) . '.',
            ],
            $chunks,
        );
    }

    #[Test]
    public function splitTextIntoChunksMeasuresMergedCommaChunksByCodepoint(): void
    {
        $subject = $this->createSubject();

        // First two multibyte parts: 4004 codepoints (merge) but 8004 bytes
        // (would flush). A byte-wise running-chunk measure splits them apart
        // and yields three chunks instead of two.
        $text = str_repeat('ü', 2000) . ', ' . str_repeat('ü', 2000) . ', ' . str_repeat('ü', 1500) . '.';

        $reflection = new ReflectionClass($subject);
        $chunks = $reflection->getMethod('splitTextIntoChunks')->invoke($subject, $text);

        self::assertSame(
            [
                str_repeat('ü', 2000) . ', ' . str_repeat('ü', 2000),
                str_repeat('ü', 1500) . '.',
            ],
            $chunks,
        );
    }

    #[Test]
    public function splitTextIntoChunksMeasuresCommaPartOverflowByCodepoint(): void
    {
        $subject = $this->createSubject();

        // The 'ü' part is 2502 codepoints (fits after the flush, merges with
        // the tail) but 5002 bytes. A byte-wise part-length check would
        // hard-split it into its own chunk and orphan the tail.
        $text = str_repeat('a', 4000) . ', ' . str_repeat('ü', 2500) . ', tail.';

        $reflection = new ReflectionClass($subject);
        $chunks = $reflection->getMethod('splitTextIntoChunks')->invoke($subject, $text);

        self::assertSame(
            [
                str_repeat('a', 4000),
                str_repeat('ü', 2500) . ', tail.',
            ],
            $chunks,
        );
    }

    #[Test]
    public function splitTextIntoChunksHardSplitsCommalessSentenceAtCodepointBoundaries(): void
    {
        $subject = $this->createSubject();

        // 5000 two-byte characters, no commas: hard-split at 4096 CODEPOINTS
        // gives chunks of 4096 + 904. A byte-wise split cuts every 4096 BYTES
        // (= 2048 'ü') and yields three shorter chunks.
        $reflection = new ReflectionClass($subject);
        $chunks = $reflection->getMethod('splitTextIntoChunks')->invoke($subject, str_repeat('ü', 5000));

        self::assertSame([str_repeat('ü', 4096), str_repeat('ü', 904)], $chunks);
    }

    #[Test]
    public function splitTextIntoChunksHardSplitsOverLimitCommaPartAtCodepointBoundaries(): void
    {
        $subject = $this->createSubject();

        // The first comma part alone exceeds the limit and is hard-split at
        // codepoint boundaries into 4096 + 904; the short tail part follows
        // as its own chunk. A byte-wise split of the 10000-byte part yields
        // 2048 + 2048 + 904; dropping the hard-split loop loses the part
        // entirely.
        $text = str_repeat('ü', 5000) . ', x.';

        $reflection = new ReflectionClass($subject);
        $chunks = $reflection->getMethod('splitTextIntoChunks')->invoke($subject, $text);

        self::assertSame([str_repeat('ü', 4096), str_repeat('ü', 904), 'x.'], $chunks);
    }

    #[Test]
    public function synthesizeRecordsModelAndVoiceAsPendingAuditContext(): void
    {
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest();

        $subject->synthesize('Hello world');

        // The test seam bypasses the vault secure client, so the audit
        // context set right before dispatch is still pending; folding it
        // into the audit reason is exactly what the secure-client build
        // would record with the secret access.
        $reflection = new ReflectionClass($subject);
        $reason = $reflection->getMethod('getAuditReason')->invoke($subject);

        self::assertSame('OpenAI TTS API call (tts-1, voice alloy)', $reason);
    }

    #[Test]
    public function synthesizeEmptyTextExceptionCarriesProviderContext(): void
    {
        $subject = $this->createSubject();

        try {
            $subject->synthesize('   ');
            self::fail('Expected ServiceUnavailableException was not thrown');
        } catch (ServiceUnavailableException $e) {
            self::assertSame('Input text cannot be empty', $e->getMessage());
            self::assertSame('speech', $e->service);
            self::assertSame(['provider' => 'tts'], $e->context);
        }
    }

    #[Test]
    public function synthesizeOverLengthExceptionReportsCodepointLengthInContext(): void
    {
        $subject = $this->createSubject();

        // 4097 two-byte characters: the context must report the CODEPOINT
        // length 4097, not the byte length 8194, alongside the provider key.
        try {
            $subject->synthesize(str_repeat('ü', 4097));
            self::fail('Expected ServiceUnavailableException was not thrown');
        } catch (ServiceUnavailableException $e) {
            self::assertStringContainsString('Input text exceeds maximum length', $e->getMessage());
            self::assertSame(['provider' => 'tts', 'length' => 4097], $e->context);
        }
    }

    #[Test]
    public function synthesizeSubstitutesInvalidUtf8InPayload(): void
    {
        $subject = $this->createSubject();

        $captured = ['method' => '', 'url' => '', 'body' => '', 'headers' => []];
        $this->setupCapturingRequest($captured);

        // "\xE9" is an invalid UTF-8 byte. JSON_INVALID_UTF8_SUBSTITUTE
        // replaces it with U+FFFD so the request still encodes; without the
        // flag combination json_encode() returns false and the stream
        // factory would reject it.
        $result = $subject->synthesize("Caf\xE9 test");

        self::assertSame('audio-binary-content', $result->audioContent);
        $decoded = json_decode($captured['body'], true);
        self::assertIsArray($decoded);
        self::assertSame("Caf\u{FFFD} test", $decoded['input']);
    }

    #[Test]
    public function synthesizeTreatsStatus300AsError(): void
    {
        $subject = $this->createSubject();
        $this->setupFailedRequest(300, 'Multiple choices');

        // The success window is [200, 300): a 300 response is an error and
        // maps through the default branch of mapErrorStatus().
        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('OpenAI TTS API error: Multiple choices');

        $subject->synthesize('Hello world');
    }

    #[Test]
    public function sendBinaryRequestLogsApiErrorWithStatusCode(): void
    {
        $this->setupFailedRequest(500, 'Server error');

        $this->extensionConfigMock
            ->expects(self::once())->method('get')
            ->with('nr_llm')
            ->willReturn(['providers' => ['openai' => ['apiKeyIdentifier' => 'test-api-key']]]);

        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock
            ->expects(self::once())
            ->method('error')
            ->with('TTS API error', ['status_code' => 500, 'error' => 'Server error']);

        $subject = $this->buildService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $this->usageTrackerStub,
            $loggerMock,
        );

        $this->expectException(ServiceUnavailableException::class);

        $subject->synthesize('Test text');
    }

    #[Test]
    public function sendBinaryRequestLogsAndWrapsConnectionException(): void
    {
        $connectionError = new RuntimeException('Connection refused');

        $requestStub = self::createStub(RequestInterface::class);
        $requestStub->method('withHeader')->willReturnSelf();
        $requestStub->method('withBody')->willReturnSelf();
        $this->requestFactoryStub->method('createRequest')->willReturn($requestStub);

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub->method('createStream')->willReturn($streamStub);

        $this->httpClientStub
            ->method('sendRequest')
            ->willThrowException($connectionError);

        $this->extensionConfigMock
            ->expects(self::once())->method('get')
            ->with('nr_llm')
            ->willReturn(['providers' => ['openai' => ['apiKeyIdentifier' => 'test-api-key']]]);

        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock
            ->expects(self::once())
            ->method('error')
            ->with('TTS API connection error', ['exception' => $connectionError]);

        $subject = $this->buildService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $this->usageTrackerStub,
            $loggerMock,
        );

        try {
            $subject->synthesize('Test text');
            self::fail('Expected ServiceUnavailableException was not thrown');
        } catch (ServiceUnavailableException $e) {
            self::assertSame('Failed to connect to TTS API', $e->getMessage());
            self::assertSame('speech', $e->service);
            self::assertSame(['provider' => 'tts'], $e->context);
            self::assertSame(0, $e->getCode());
            self::assertSame($connectionError, $e->getPrevious());
        }
    }

    #[Test]
    public function synthesizeLongChecksAvailabilityBeforeParsingOptions(): void
    {
        $subject = $this->createSubjectWithoutApiKey();

        // ensureAvailable() must fire BEFORE the options array is parsed:
        // an invalid voice would otherwise surface as an
        // InvalidArgumentException from the options validation instead of
        // the not-configured error.
        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('Tts service is not configured');

        $subject->synthesizeLong('Hello world', ['voice' => 'not-a-real-voice']);
    }
}
