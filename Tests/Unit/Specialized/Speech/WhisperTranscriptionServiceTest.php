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
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Provider\Middleware\UsageMiddleware;
use Netresearch\NrLlm\Service\Guardrail\InputGuardrailScreener;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Specialized\Exception\ServiceConfigurationException;
use Netresearch\NrLlm\Specialized\Exception\ServiceQuotaExceededException;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Exception\UnsupportedFormatException;
use Netresearch\NrLlm\Specialized\Option\TranscriptionOptions;
use Netresearch\NrLlm\Specialized\Pricing\SpecializedCostCalculator;
use Netresearch\NrLlm\Specialized\Pricing\SpecializedCostCalculatorInterface;
use Netresearch\NrLlm\Specialized\Speech\TranscriptionResult;
use Netresearch\NrLlm\Specialized\Speech\WhisperTranscriptionService;
use Netresearch\NrLlm\Specialized\Usage\WhisperUsageExtractor;
use Netresearch\NrLlm\Tests\Fixture\AllowingBudgetService;
use Netresearch\NrLlm\Tests\Fixture\CapturingMiddleware;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Specialized\PipelineRoutingAssertionTrait;
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
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(WhisperTranscriptionService::class)]
class WhisperTranscriptionServiceTest extends AbstractUnitTestCase
{
    use PipelineRoutingAssertionTrait;

    private ClientInterface&Stub $httpClientStub;
    private RequestFactoryInterface&Stub $requestFactoryStub;
    private StreamFactoryInterface&Stub $streamFactoryStub;
    private ExtensionConfiguration&MockObject $extensionConfigMock;
    private UsageTrackerServiceInterface&Stub $usageTrackerStub;
    private LoggerInterface&Stub $loggerStub;
    private VaultServiceInterface $vaultStub;
    private SpecializedCostCalculatorInterface $costCalculator;
    private ?string $tempFile = null;

    /**
     * Method and URL of the last request built through
     * `setupSuccessfulRequest()` — lets tests assert the endpoint without
     * reflecting into the service.
     */
    private string $lastRequestMethod = '';

    private string $lastRequestUrl = '';

    /**
     * The exact multipart/form-data body the service handed to the stream
     * factory in the last `setupSuccessfulRequest()` flow — lets tests
     * inspect the encoded parts (file, model, language, …) without a real
     * HTTP request.
     */
    private string $lastRequestBody = '';

    /**
     * Headers set on the last request built through `setupSuccessfulRequest()`,
     * keyed by header name (e.g. `Content-Type`).
     *
     * @var array<string, mixed>
     */
    private array $lastRequestHeaders = [];

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
     * Build a WhisperTranscriptionService wired to the vault mock, then inject
     * the given plain HTTP client through the test seam (bypasses the vault
     * secure client so request/response assertions can read the request the
     * service built).
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
    ): WhisperTranscriptionService {
        $service = new WhisperTranscriptionService(
            $this->vaultStub,
            $requestFactory,
            $streamFactory,
            $extensionConfiguration,
            $usageTracker,
            $logger,
            $this->costCalculator,
            new AllowingBudgetService(),
            $pipeline ?? new MiddlewarePipeline([
                new UsageMiddleware($usageTracker, $logger, [new WhisperUsageExtractor($this->costCalculator)]),
            ]),
            new InputGuardrailScreener([]),
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
    ): WhisperTranscriptionService {
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

    private function createSubjectWithoutApiKey(): WhisperTranscriptionService
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

    private function createTestAudioFile(): string
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'whisper_test_') . '.mp3';
        file_put_contents($this->tempFile, 'fake audio content');
        return $this->tempFile;
    }

    private function setupSuccessfulRequest(string $responseBody): void
    {
        $requestStub = self::createStub(RequestInterface::class);
        $requestStub->method('withHeader')->willReturnCallback(
            function (string $name, mixed $value) use ($requestStub): RequestInterface {
                $this->lastRequestHeaders[$name] = $value;

                return $requestStub;
            },
        );
        $requestStub->method('withBody')->willReturnSelf();

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturnCallback(
                function (string $method, UriInterface|string $uri) use ($requestStub): RequestInterface {
                    $this->lastRequestMethod = $method;
                    $this->lastRequestUrl = (string)$uri;

                    return $requestStub;
                },
            );

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturnCallback(
                function (string $content = '') use ($streamStub): StreamInterface {
                    $this->lastRequestBody = $content;

                    return $streamStub;
                },
            );

        $responseBodyStub = self::createStub(StreamInterface::class);
        $responseBodyStub->method('__toString')->willReturn($responseBody);

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

    #[Test]
    public function emptyStringBaseUrlFallsBackToApiUrl(): void
    {
        // An empty ext_conf baseUrl is the documented "use the default" value;
        // it must not be sent verbatim (a scheme-less URL breaks the HTTP
        // client) — it falls back to the OpenAI default like the siblings do.
        // Observed through the request the service builds (no reflection).
        $subject = $this->createSubject(['speech' => ['whisper' => ['baseUrl' => '']]]);
        $audioFile = $this->createTestAudioFile();
        $this->setupSuccessfulRequest((string)json_encode(['text' => 'Hello world']));

        $subject->transcribe($audioFile);

        self::assertSame('POST', $this->lastRequestMethod);
        self::assertStringStartsWith('https://api.openai.com/v1/audio', $this->lastRequestUrl);
    }

    // ==================== getters tests ====================

    #[Test]
    public function getSupportedFormatsReturnsAllFormats(): void
    {
        $subject = $this->createSubject();

        $formats = $subject->getSupportedFormats();

        self::assertContains('mp3', $formats);
        self::assertContains('wav', $formats);
        self::assertContains('mp4', $formats);
        self::assertContains('webm', $formats);
        self::assertContains('flac', $formats);
        self::assertContains('ogg', $formats);
    }

    #[Test]
    public function getSupportedResponseFormatsReturnsAllFormats(): void
    {
        $subject = $this->createSubject();

        $formats = $subject->getSupportedResponseFormats();

        self::assertContains('json', $formats);
        self::assertContains('text', $formats);
        self::assertContains('srt', $formats);
        self::assertContains('vtt', $formats);
        self::assertContains('verbose_json', $formats);
    }

    // ==================== resolveDefaultModel tests ====================

    #[Test]
    public function resolveDefaultModelPrefersDefaultFlaggedTranscriptionRecord(): void
    {
        $regular = new Model();
        $regular->setModelId('whisper-1');
        $default = new Model();
        $default->setModelId('gpt-4o-transcribe');
        $default->setIsDefault(true);

        $modelRepository = $this->createMock(ModelRepository::class);
        $modelRepository->expects(self::once())
            ->method('findByCapability')
            ->with('transcription')
            ->willReturn(new InMemoryQueryResult([$regular, $default]));

        $subject = $this->createSubject(modelRepository: $modelRepository);

        self::assertSame('gpt-4o-transcribe', $subject->resolveDefaultModel('whisper-1'));
    }

    #[Test]
    public function resolveDefaultModelReturnsFallbackWhenNoTranscriptionRecordExists(): void
    {
        $modelRepository = $this->createMock(ModelRepository::class);
        $modelRepository->method('findByCapability')->willReturn(new InMemoryQueryResult([]));

        $subject = $this->createSubject(modelRepository: $modelRepository);

        self::assertSame('whisper-1', $subject->resolveDefaultModel('whisper-1'));
    }

    #[Test]
    public function resolveDefaultModelReturnsFallbackWhenRepositoryThrows(): void
    {
        $modelRepository = $this->createMock(ModelRepository::class);
        $modelRepository->method('findByCapability')
            ->willThrowException(new RuntimeException('persistence unavailable'));

        $subject = $this->createSubject(modelRepository: $modelRepository);

        self::assertSame('whisper-1', $subject->resolveDefaultModel('whisper-1'));
    }

    #[Test]
    public function resolveModelForConfigurationUsesConfiguredModel(): void
    {
        $model = new Model();
        $model->setModelId('gpt-4o-transcribe');

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('meeting-minutes');
        $configuration->setLlmModel($model);

        $configurationRepository = $this->createMock(LlmConfigurationRepository::class);
        $configurationRepository->expects(self::once())
            ->method('findOneByIdentifier')
            ->with('meeting-minutes')
            ->willReturn($configuration);

        $subject = $this->createSubject(configurationRepository: $configurationRepository);

        self::assertSame('gpt-4o-transcribe', $subject->resolveModelForConfiguration('meeting-minutes', 'whisper-1'));
    }

    #[Test]
    public function resolveModelForConfigurationFallsBackToTranscriptionCapabilityDefault(): void
    {
        // Unknown configuration identifier: the capability-based registry
        // default applies — for this service the `transcription` capability.
        $configurationRepository = self::createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findOneByIdentifier')->willReturn(null);

        $registryDefault = new Model();
        $registryDefault->setModelId('gpt-4o-transcribe');

        $modelRepository = $this->createMock(ModelRepository::class);
        $modelRepository->expects(self::once())
            ->method('findByCapability')
            ->with('transcription')
            ->willReturn(new InMemoryQueryResult([$registryDefault]));

        $subject = $this->createSubject(
            modelRepository: $modelRepository,
            configurationRepository: $configurationRepository,
        );

        self::assertSame('gpt-4o-transcribe', $subject->resolveModelForConfiguration('unknown', 'whisper-1'));
    }

    #[Test]
    public function resolveModelForConfigurationReturnsFallbackWithoutRepositories(): void
    {
        $subject = $this->createSubject();

        self::assertSame('whisper-1', $subject->resolveModelForConfiguration('meeting-minutes', 'whisper-1'));
    }

    #[Test]
    public function getConfigurationSystemPromptReturnsPromptOfActiveConfiguration(): void
    {
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('meeting-minutes');
        $configuration->setSystemPrompt('Use formal meeting-minutes vocabulary.');

        $configurationRepository = self::createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findOneByIdentifier')->willReturn($configuration);

        $subject = $this->createSubject(configurationRepository: $configurationRepository);

        self::assertSame(
            'Use formal meeting-minutes vocabulary.',
            $subject->getConfigurationSystemPrompt('meeting-minutes'),
        );
    }

    #[Test]
    public function transcribeLinksUsageRowToConfigurationUid(): void
    {
        // When the options carry an LlmConfiguration identifier, the usage
        // row links to that configuration record so the Analytics module
        // can aggregate transcription spend per configuration.
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('meeting-minutes');
        $configuration->_setProperty('uid', 31);

        $configurationRepository = $this->createMock(LlmConfigurationRepository::class);
        // Called twice now: once by the budget pre-flight, once for the usage
        // row's configuration link. The count is not what this test is about.
        $configurationRepository->method('findOneByIdentifier')
            ->with('meeting-minutes')
            ->willReturn($configuration);

        $audioFile = $this->createTestAudioFile();
        $this->setupSuccessfulRequest((string)json_encode(['text' => 'Hello']));

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
                'whisper',
                [],
                31,
                0,
                'whisper-1',
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
            ['configuration' => $configurationRepository],
        );

        $subject->transcribe($audioFile, ['configuration' => 'meeting-minutes']);
    }

    #[Test]
    public function transcribeAttributesUsageToOptionUid(): void
    {
        // ADR-057: a caller-supplied beUserUid in the options reaches the
        // usage row instead of the ambient fallback.
        $audioFile = $this->createTestAudioFile();
        $this->setupSuccessfulRequest((string)json_encode(['text' => 'Hello']));

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
                'whisper',
                [],
                null,
                0,
                'whisper-1',
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

        $subject->transcribe($audioFile, new TranscriptionOptions(beUserUid: 42));
    }

    // ==================== transcribe tests ====================

    #[Test]
    public function transcribeReturnsTranscriptionResult(): void
    {
        $subject = $this->createSubject();
        $audioFile = $this->createTestAudioFile();
        $this->setupSuccessfulRequest((string)json_encode([
            'text' => 'Hello world',
            'language' => 'en',
            'duration' => 5.5,
        ]));

        $result = $subject->transcribe($audioFile);

        self::assertInstanceOf(TranscriptionResult::class, $result);
        self::assertEquals('Hello world', $result->text);
        self::assertEquals('en', $result->language);
        self::assertEquals(5.5, $result->duration);
    }

    #[Test]
    public function transcribeThrowsWhenServiceUnavailable(): void
    {
        $subject = $this->createSubjectWithoutApiKey();
        $audioFile = $this->createTestAudioFile();

        // The availability guard runs before any request work; without it the
        // path would later surface a generic connection failure instead.
        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('Whisper service is not configured');

        $subject->transcribe($audioFile);
    }

    #[Test]
    public function transcribeThrowsOnFileNotFound(): void
    {
        $subject = $this->createSubject();

        $this->expectException(UnsupportedFormatException::class);

        $subject->transcribe('/non/existent/file.mp3');
    }

    #[Test]
    public function transcribeThrowsOnUnsupportedFormat(): void
    {
        $subject = $this->createSubject();
        $tempFile = tempnam(sys_get_temp_dir(), 'test_') . '.xyz';
        file_put_contents($tempFile, 'content');
        $this->tempFile = $tempFile;

        $this->expectException(UnsupportedFormatException::class);

        $subject->transcribe($tempFile);
    }

    #[Test]
    public function transcribeTracksUsage(): void
    {
        $audioFile = $this->createTestAudioFile();
        $this->setupSuccessfulRequest((string)json_encode(['text' => 'Hello']));

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
                'whisper',
                // Plain `json` responses expose no duration — the request is
                // recorded without audio seconds and without a guessed cost.
                [],
                null,
                0,
                'whisper-1',
            );

        $subject = $this->buildService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $usageTrackerMock,
            $this->loggerStub,
        );

        $subject->transcribe($audioFile);
    }

    #[Test]
    public function transcribeWithOptions(): void
    {
        $subject = $this->createSubject();
        $audioFile = $this->createTestAudioFile();
        $this->setupSuccessfulRequest((string)json_encode(['text' => 'Bonjour']));

        $options = new TranscriptionOptions(
            language: 'fr',
            format: 'json',
            temperature: 0.5,
        );

        $result = $subject->transcribe($audioFile, $options);

        self::assertInstanceOf(TranscriptionResult::class, $result);
    }

    #[Test]
    public function transcribeWithArrayOptions(): void
    {
        $subject = $this->createSubject();
        $audioFile = $this->createTestAudioFile();
        $this->setupSuccessfulRequest((string)json_encode(['text' => 'Test']));

        $options = [
            'language' => 'de',
        ];

        $result = $subject->transcribe($audioFile, $options);

        self::assertInstanceOf(TranscriptionResult::class, $result);
    }

    #[Test]
    public function transcribeWithTextFormat(): void
    {
        $subject = $this->createSubject();
        $audioFile = $this->createTestAudioFile();
        $this->setupSuccessfulRequest('Hello world');

        $options = new TranscriptionOptions(format: 'text');

        $result = $subject->transcribe($audioFile, $options);

        self::assertEquals('Hello world', $result->text);
    }

    #[Test]
    public function transcribeWithVerboseJsonFormat(): void
    {
        $subject = $this->createSubject();
        $audioFile = $this->createTestAudioFile();
        $this->setupSuccessfulRequest((string)json_encode([
            'text' => 'Hello world',
            'language' => 'en',
            'duration' => 5.5,
            'task' => 'transcribe',
            'segments' => [
                [
                    'id' => 0,
                    'text' => 'Hello',
                    'start' => 0.0,
                    'end' => 2.5,
                ],
                [
                    'id' => 1,
                    'text' => 'world',
                    'start' => 2.5,
                    'end' => 5.5,
                ],
            ],
        ]));

        $options = new TranscriptionOptions(format: 'verbose_json');

        $result = $subject->transcribe($audioFile, $options);

        self::assertNotNull($result->segments);
        self::assertCount(2, $result->segments);
    }

    // ==================== transcribeFromContent tests ====================

    #[Test]
    public function transcribeFromContentReturnsTranscriptionResult(): void
    {
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest((string)json_encode(['text' => 'Hello']));

        $result = $subject->transcribeFromContent('fake audio content', 'test.mp3');

        self::assertInstanceOf(TranscriptionResult::class, $result);
        self::assertEquals('Hello', $result->text);
    }

    #[Test]
    public function transcribeFromContentThrowsOnUnsupportedFormat(): void
    {
        $subject = $this->createSubject();

        $this->expectException(UnsupportedFormatException::class);

        $subject->transcribeFromContent('content', 'test.xyz');
    }

    #[Test]
    public function transcribeFromContentThrowsOnContentTooLarge(): void
    {
        $subject = $this->createSubject();
        $largeContent = str_repeat('a', 26 * 1024 * 1024); // > 25MB

        $this->expectException(UnsupportedFormatException::class);
        // 25 * 1024 * 1024 / 1024 / 1024 = 25 MB in the limit message.
        $this->expectExceptionMessage('Audio content exceeds maximum size of 25 MB');

        $subject->transcribeFromContent($largeContent, 'test.mp3');
    }

    // ==================== translateToEnglish tests ====================

    #[Test]
    public function translateToEnglishReturnsTranscriptionResult(): void
    {
        $subject = $this->createSubject();
        $audioFile = $this->createTestAudioFile();
        $this->setupSuccessfulRequest((string)json_encode(['text' => 'Hello in English']));

        $result = $subject->translateToEnglish($audioFile);

        self::assertInstanceOf(TranscriptionResult::class, $result);
        self::assertEquals('Hello in English', $result->text);
    }

    #[Test]
    public function translateToEnglishTracksUsage(): void
    {
        $audioFile = $this->createTestAudioFile();
        $this->setupSuccessfulRequest((string)json_encode(['text' => 'Hello']));

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
            // Exactly once: the request is recorded inside the dispatch path;
            // translateToEnglish() used to add a second row, double-counting
            // every translation request.
            ->method('trackUsage')
            ->with(
                'speech',
                'whisper',
                [],
                null,
                0,
                'whisper-1',
            );

        $subject = $this->buildService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $usageTrackerMock,
            $this->loggerStub,
        );

        $subject->translateToEnglish($audioFile);
    }

    #[Test]
    public function transcribeWithVerboseJsonTracksAudioSecondsAndCost(): void
    {
        // verbose_json reports the audio duration; 90 seconds of whisper-1
        // at $0.006/minute = $0.009.
        $audioFile = $this->createTestAudioFile();
        $this->setupSuccessfulRequest((string)json_encode([
            'text' => 'Hello',
            'language' => 'en',
            'duration' => 90.0,
        ]));

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
                'whisper',
                self::callback(
                    fn(array $metrics): bool => $metrics['audioSeconds'] === 90
                        && is_float($metrics['cost']) && abs($metrics['cost'] - 0.009) < 1e-12,
                ),
                null,
                0,
                'whisper-1',
            );

        $subject = $this->buildService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $usageTrackerMock,
            $this->loggerStub,
        );

        $subject->transcribe($audioFile, new TranscriptionOptions(format: 'verbose_json'));
    }

    // ==================== API error handling tests ====================

    #[Test]
    public function transcribeThrowsOnUnauthorized(): void
    {
        $subject = $this->createSubject();
        $audioFile = $this->createTestAudioFile();
        $this->setupFailedRequest(401, 'Invalid API key');

        $this->expectException(ServiceConfigurationException::class);

        $subject->transcribe($audioFile);
    }

    #[Test]
    public function transcribeThrowsOnForbidden(): void
    {
        $subject = $this->createSubject();
        $audioFile = $this->createTestAudioFile();
        $this->setupFailedRequest(403, 'Forbidden');

        $this->expectException(ServiceConfigurationException::class);

        $subject->transcribe($audioFile);
    }

    #[Test]
    public function transcribeThrowsOnRateLimitExceeded(): void
    {
        $subject = $this->createSubject();
        $audioFile = $this->createTestAudioFile();
        $this->setupFailedRequest(429, 'Rate limit exceeded');

        $this->expectException(ServiceQuotaExceededException::class);

        $subject->transcribe($audioFile);
    }

    #[Test]
    public function transcribeThrowsOnServerError(): void
    {
        $subject = $this->createSubject();
        $audioFile = $this->createTestAudioFile();
        $this->setupFailedRequest(500, 'Internal server error');

        $this->expectException(ServiceUnavailableException::class);

        $subject->transcribe($audioFile);
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
    public function transcribeFromContentWithOptionsObject(): void
    {
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest((string)json_encode(['text' => 'Hello']));

        $options = new TranscriptionOptions(language: 'de', format: 'json');

        $result = $subject->transcribeFromContent('fake audio content', 'test.mp3', $options);

        self::assertInstanceOf(TranscriptionResult::class, $result);
    }

    #[Test]
    public function translateToEnglishWithOptionsObject(): void
    {
        $subject = $this->createSubject();
        $audioFile = $this->createTestAudioFile();
        $this->setupSuccessfulRequest((string)json_encode(['text' => 'Translated text']));

        $options = new TranscriptionOptions(format: 'json');

        $result = $subject->translateToEnglish($audioFile, $options);

        self::assertInstanceOf(TranscriptionResult::class, $result);
    }

    #[Test]
    public function translateToEnglishWithArrayOptions(): void
    {
        $subject = $this->createSubject();
        $audioFile = $this->createTestAudioFile();
        $this->setupSuccessfulRequest((string)json_encode(['text' => 'Translated']));

        $result = $subject->translateToEnglish($audioFile, ['format' => 'json']);

        self::assertInstanceOf(TranscriptionResult::class, $result);
    }

    #[Test]
    public function transcribeWithSrtFormat(): void
    {
        $subject = $this->createSubject();
        $audioFile = $this->createTestAudioFile();
        $srtContent = "1\n00:00:00,000 --> 00:00:02,000\nHello world\n";
        $this->setupSuccessfulRequest($srtContent);

        $options = new TranscriptionOptions(format: 'srt');

        $result = $subject->transcribe($audioFile, $options);

        self::assertEquals($srtContent, $result->text);
        self::assertEquals('srt', $result->metadata['format'] ?? null);
    }

    #[Test]
    public function transcribeWithVttFormat(): void
    {
        $subject = $this->createSubject();
        $audioFile = $this->createTestAudioFile();
        $vttContent = "WEBVTT\n\n00:00.000 --> 00:02.000\nHello world\n";
        $this->setupSuccessfulRequest($vttContent);

        $options = new TranscriptionOptions(format: 'vtt');

        $result = $subject->transcribe($audioFile, $options);

        self::assertEquals($vttContent, $result->text);
        self::assertEquals('vtt', $result->metadata['format'] ?? null);
    }

    #[Test]
    public function transcribeHandlesConnectionError(): void
    {
        $audioFile = $this->createTestAudioFile();

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

        $this->httpClientStub
            ->method('sendRequest')
            ->willThrowException(new RuntimeException('Connection refused'));

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

        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock
            ->expects(self::once())
            ->method('error');

        $subject = $this->buildService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $this->usageTrackerStub,
            $loggerMock,
        );

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('Failed to connect to Whisper API');

        $subject->transcribe($audioFile);
    }

    #[Test]
    public function transcribeHandlesNullErrorMessage(): void
    {
        $subject = $this->createSubject();
        $audioFile = $this->createTestAudioFile();

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
        $responseBodyStub->method('__toString')->willReturn(json_encode(['error' => []]));

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn(500);
        $responseStub->method('getBody')->willReturn($responseBodyStub);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($responseStub);

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('Unknown Whisper API error');

        $subject->transcribe($audioFile);
    }

    #[Test]
    public function transcribeWithPromptOption(): void
    {
        $subject = $this->createSubject();
        $audioFile = $this->createTestAudioFile();
        $this->setupSuccessfulRequest((string)json_encode(['text' => 'Technical term']));

        $options = new TranscriptionOptions(
            prompt: 'This is a technical discussion about programming',
        );

        $result = $subject->transcribe($audioFile, $options);

        self::assertInstanceOf(TranscriptionResult::class, $result);
    }

    #[Test]
    public function transcribeFromContentWithAllOptions(): void
    {
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest((string)json_encode(['text' => 'Full options test']));

        $options = new TranscriptionOptions(
            language: 'en',
            format: 'json',
            prompt: 'Technical content',
            temperature: 0.3,
            model: 'whisper-1',
        );

        $result = $subject->transcribeFromContent('fake audio content', 'test.wav', $options);

        self::assertInstanceOf(TranscriptionResult::class, $result);
    }

    #[Test]
    public function transcribeWithCustomBaseUrl(): void
    {
        $config = [
            'speech' => [
                'whisper' => [
                    'baseUrl' => 'https://custom-whisper.example.com/api',
                    'timeout' => 180,
                ],
            ],
        ];

        $subject = $this->createSubject($config);
        $audioFile = $this->createTestAudioFile();
        $this->setupSuccessfulRequest((string)json_encode(['text' => 'Custom endpoint']));

        $result = $subject->transcribe($audioFile);

        self::assertInstanceOf(TranscriptionResult::class, $result);
        // The configured `speech.whisper.baseUrl` must be the base the endpoint
        // path is appended to (config is read at [speech][whisper], not
        // elsewhere), so the request targets the custom host.
        self::assertSame(
            'https://custom-whisper.example.com/api/transcriptions',
            $this->lastRequestUrl,
        );
    }

    #[Test]
    public function parseTranscriptionResponseHandlesMissingLanguage(): void
    {
        $subject = $this->createSubject();
        $audioFile = $this->createTestAudioFile();
        $this->setupSuccessfulRequest((string)json_encode([
            'text' => 'Text without language',
            // language field missing
        ]));

        $result = $subject->transcribe($audioFile);

        self::assertEquals('Text without language', $result->text);
        // Should default to 'en'
        self::assertEquals('en', $result->language);
    }

    // ==================== outgoing request shape tests ====================

    #[Test]
    public function transcribePostsMultipartRequestToTranscriptionsEndpoint(): void
    {
        // Default createSubject() carries no baseUrl override, so the OpenAI
        // default base (proven by emptyStringBaseUrlFallsBackToApiUrl) applies
        // and the transcription endpoint is appended to it.
        $subject = $this->createSubject();
        $audioFile = $this->createTestAudioFile();
        $this->setupSuccessfulRequest((string)json_encode(['text' => 'Hello']));

        $subject->transcribe($audioFile);

        self::assertSame('POST', $this->lastRequestMethod);
        self::assertSame('https://api.openai.com/v1/audio/transcriptions', $this->lastRequestUrl);

        // The Content-Type carries the multipart boundary, prefixed `whisper-`.
        self::assertArrayHasKey('Content-Type', $this->lastRequestHeaders);
        $contentType = $this->lastRequestHeaders['Content-Type'];
        self::assertIsString($contentType);
        self::assertStringStartsWith('multipart/form-data; boundary=whisper-', $contentType);

        // The boundary is `whisper-` concatenated with a uniqid() suffix; the
        // body's first boundary line therefore has hex/dot chars after the
        // prefix (rules out a bare, dropped, or reordered concatenation).
        self::assertStringContainsString('--whisper-', $this->lastRequestBody);
        self::assertMatchesRegularExpression('#^--whisper-[0-9a-f.]+\r\n#', $this->lastRequestBody);

        // File part first, then the mandatory model field — both must survive.
        self::assertStringContainsString(
            'Content-Disposition: form-data; name="file"; filename="',
            $this->lastRequestBody,
        );
        self::assertStringContainsString(
            "Content-Disposition: form-data; name=\"model\"\r\n\r\nwhisper-1\r\n",
            $this->lastRequestBody,
        );
    }

    #[Test]
    public function transcribeSendsConfiguredModelValueInBody(): void
    {
        // The model field value is `$options->model ?? DEFAULT` — a caller model
        // distinct from the `whisper-1` default proves the coalesce direction.
        $subject = $this->createSubject();
        $audioFile = $this->createTestAudioFile();
        $this->setupSuccessfulRequest((string)json_encode(['text' => 'Hello']));

        $subject->transcribe($audioFile, new TranscriptionOptions(model: 'gpt-4o-transcribe'));

        self::assertStringContainsString(
            "Content-Disposition: form-data; name=\"model\"\r\n\r\ngpt-4o-transcribe\r\n",
            $this->lastRequestBody,
        );
    }

    #[Test]
    public function transcribeEncodesOptionalFieldPartsWithNamesAndValues(): void
    {
        $subject = $this->createSubject();
        $audioFile = $this->createTestAudioFile();
        $this->setupSuccessfulRequest((string)json_encode(['text' => 'Bonjour']));

        $options = new TranscriptionOptions(
            language: 'fr',
            format: 'json',
            prompt: 'Tech talk',
            temperature: 0.5,
        );

        $subject->transcribe($audioFile, $options);

        // Each optional field is added only when set, keyed by its API name and
        // carrying its exact value (temperature is string-cast).
        self::assertStringContainsString(
            "Content-Disposition: form-data; name=\"language\"\r\n\r\nfr\r\n",
            $this->lastRequestBody,
        );
        self::assertStringContainsString(
            "Content-Disposition: form-data; name=\"response_format\"\r\n\r\njson\r\n",
            $this->lastRequestBody,
        );
        self::assertStringContainsString(
            "Content-Disposition: form-data; name=\"prompt\"\r\n\r\nTech talk\r\n",
            $this->lastRequestBody,
        );
        self::assertStringContainsString(
            "Content-Disposition: form-data; name=\"temperature\"\r\n\r\n0.5\r\n",
            $this->lastRequestBody,
        );
    }

    #[Test]
    public function transcribeOmitsOptionalFieldPartsWhenUnset(): void
    {
        // With language/prompt/temperature unset (and format explicitly null so
        // no response_format part is emitted), only the file and model parts
        // remain — the `!== null` guards must skip the absent fields.
        $subject = $this->createSubject();
        $audioFile = $this->createTestAudioFile();
        $this->setupSuccessfulRequest((string)json_encode(['text' => 'Hello']));

        $subject->transcribe($audioFile, new TranscriptionOptions(format: null));

        self::assertStringNotContainsString('name="language"', $this->lastRequestBody);
        self::assertStringNotContainsString('name="response_format"', $this->lastRequestBody);
        self::assertStringNotContainsString('name="prompt"', $this->lastRequestBody);
        self::assertStringNotContainsString('name="temperature"', $this->lastRequestBody);
    }

    #[Test]
    public function transcribeFromContentEncodesFilePartWithFilenameAndContent(): void
    {
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest((string)json_encode(['text' => 'Hello']));

        $subject->transcribeFromContent('fake audio content', 'test.mp3');

        self::assertStringContainsString(
            "Content-Disposition: form-data; name=\"file\"; filename=\"test.mp3\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n"
            . "fake audio content\r\n",
            $this->lastRequestBody,
        );
    }

    #[Test]
    public function translateToEnglishPostsToTranslationsEndpoint(): void
    {
        // Translation dispatches to the `translations` endpoint, distinct from
        // transcription's `transcriptions`.
        $subject = $this->createSubject();
        $audioFile = $this->createTestAudioFile();
        $this->setupSuccessfulRequest((string)json_encode(['text' => 'Hello']));

        $subject->translateToEnglish($audioFile);

        self::assertSame('POST', $this->lastRequestMethod);
        self::assertSame('https://api.openai.com/v1/audio/translations', $this->lastRequestUrl);
    }

    #[Test]
    public function transcribeFromContentThrowsWhenServiceUnavailable(): void
    {
        // The availability guard runs before any content work; without it the
        // path would later surface a generic connection failure instead.
        $subject = $this->createSubjectWithoutApiKey();

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('Whisper service is not configured');

        $subject->transcribeFromContent('fake audio content', 'test.mp3');
    }

    #[Test]
    public function translateToEnglishThrowsWhenServiceUnavailable(): void
    {
        $subject = $this->createSubjectWithoutApiKey();
        $audioFile = $this->createTestAudioFile();

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('Whisper service is not configured');

        $subject->translateToEnglish($audioFile);
    }

    #[Test]
    public function translateToEnglishValidatesAudioFileBeforeSending(): void
    {
        // The file validation runs before the request; a missing file must
        // raise the format exception rather than a downstream read failure.
        $subject = $this->createSubject();

        $this->expectException(UnsupportedFormatException::class);

        $subject->translateToEnglish('/non/existent/file.mp3');
    }

    #[Test]
    public function transcribeThrowsWhenFileExceedsMaxSize(): void
    {
        // A file larger than the limit must be rejected by the size guard; the
        // check is an OR of the read-failure and over-size conditions.
        $subject = $this->createSubject();
        $largeFile = tempnam(sys_get_temp_dir(), 'whisper_big_') . '.mp3';
        file_put_contents($largeFile, str_repeat('a', 26 * 1024 * 1024)); // > 25MB
        $this->tempFile = $largeFile;

        $this->expectException(UnsupportedFormatException::class);
        $this->expectExceptionMessage('Audio file exceeds maximum size of 25 MB');

        $subject->transcribe($largeFile);
    }

    #[Test]
    public function transcribeFromContentAcceptsUppercaseExtension(): void
    {
        // The extension is lower-cased before the supported-format check, so an
        // uppercase `.MP3` filename is accepted like its lowercase form.
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest((string)json_encode(['text' => 'Hello']));

        $result = $subject->transcribeFromContent('fake audio content', 'test.MP3');

        self::assertInstanceOf(TranscriptionResult::class, $result);
    }

    #[Test]
    public function transcribeAcceptsUppercaseFileExtension(): void
    {
        $subject = $this->createSubject();
        $uppercaseFile = tempnam(sys_get_temp_dir(), 'whisper_upper_') . '.MP3';
        file_put_contents($uppercaseFile, 'fake audio content');
        $this->tempFile = $uppercaseFile;
        $this->setupSuccessfulRequest((string)json_encode(['text' => 'Hello']));

        $result = $subject->transcribe($uppercaseFile);

        self::assertInstanceOf(TranscriptionResult::class, $result);
    }

    #[Test]
    public function resolveDefaultModelSkipsModelIdsThisServiceCannotSpeak(): void
    {
        // The registry query is provider-agnostic; a record whose model id is
        // neither `whisper-*` nor `*-transcribe` must be skipped, so resolution
        // falls back rather than returning that foreign id.
        $foreign = new Model();
        $foreign->setModelId('some-other-model');

        $modelRepository = $this->createMock(ModelRepository::class);
        $modelRepository->method('findByCapability')
            ->willReturn(new InMemoryQueryResult([$foreign]));

        $subject = $this->createSubject(modelRepository: $modelRepository);

        self::assertSame('whisper-1', $subject->resolveDefaultModel('whisper-1'));
    }

    #[Test]
    public function transcribeWithVerboseJsonExposesEveryParsedResponseField(): void
    {
        $subject = $this->createSubject();
        $audioFile = $this->createTestAudioFile();
        $this->setupSuccessfulRequest((string)json_encode([
            'text' => 'Hello world',
            'language' => 'de',
            'duration' => 5.5,
            'task' => 'transcribe',
            'segments' => [
                ['id' => 0, 'text' => 'Hello', 'start' => 0.0, 'end' => 2.5],
                ['id' => 1, 'text' => 'world', 'start' => 2.5, 'end' => 5.5],
            ],
        ]));

        $result = $subject->transcribe($audioFile, new TranscriptionOptions(format: 'verbose_json'));

        self::assertSame('Hello world', $result->text);
        self::assertSame('de', $result->language);
        self::assertSame(5.5, $result->duration);
        self::assertNotNull($result->segments);
        self::assertCount(2, $result->segments);
        self::assertNotNull($result->metadata);
        self::assertSame('verbose_json', $result->metadata['format'] ?? null);
        self::assertSame('transcribe', $result->metadata['task'] ?? null);
    }

    #[Test]
    public function aCallIsRoutedThroughThePipelineWithAServiceContext(): void
    {
        // ADR-097: the HTTP dispatch runs through the shared MiddlewarePipeline
        // with a service ProviderCallContext (Transcription operation), so a
        // capturing middleware observes the context the service built.
        $capture = new CapturingMiddleware();
        $audioFile = $this->createTestAudioFile();
        $this->setupSuccessfulRequest((string)json_encode(['text' => 'Hello']));

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

        $service = $this->buildService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $this->usageTrackerStub,
            $this->loggerStub,
            [],
            new MiddlewarePipeline([$capture]),
        );

        $service->transcribe($audioFile);

        $this->assertRoutedThroughPipeline($capture, ProviderOperation::Transcription);
    }
}
