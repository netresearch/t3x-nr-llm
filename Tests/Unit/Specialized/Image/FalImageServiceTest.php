<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Image;

use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Specialized\Exception\ServiceConfigurationException;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Image\FalImageService;
use Netresearch\NrLlm\Specialized\Image\ImageGenerationResult;
use Netresearch\NrLlm\Specialized\Pricing\SpecializedCostCalculatorInterface;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Support\InMemoryQueryResult;
use Netresearch\NrVault\Http\SecretPlacement;
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
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(FalImageService::class)]
class FalImageServiceTest extends AbstractUnitTestCase
{
    private ClientInterface&Stub $httpClientStub;
    private RequestFactoryInterface&Stub $requestFactoryStub;
    private StreamFactoryInterface&Stub $streamFactoryStub;
    private ExtensionConfiguration&MockObject $extensionConfigMock;
    private UsageTrackerServiceInterface&Stub $usageTrackerStub;
    private LoggerInterface&Stub $loggerStub;
    private VaultServiceInterface $vaultStub;

    /** @var list<array{method: string, uri: string}> */
    private array $capturedRequests = [];

    /** @var list<string> */
    private array $capturedBodies = [];

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
    }

    /**
     * Build a FalImageService wired to the vault mock, then inject the given
     * plain HTTP client through the test seam (bypasses the vault secure client
     * so request/response assertions can read the request the service built).
     */
    private function buildService(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        ExtensionConfiguration $extensionConfiguration,
        UsageTrackerServiceInterface $usageTracker,
        LoggerInterface $logger,
    ): FalImageService {
        $service = new FalImageService(
            $this->vaultStub,
            $requestFactory,
            $streamFactory,
            $extensionConfiguration,
            $usageTracker,
            $logger,
            self::createStub(SpecializedCostCalculatorInterface::class),
        );
        $service->setHttpClient($httpClient);

        return $service;
    }

    /**
     * The IMAGE capability is shared across providers: an OpenAI record — even
     * default-flagged — must never reach the FAL endpoint. With no FAL-speakable
     * record in the registry, resolution returns the caller's fallback.
     */
    #[Test]
    public function resolveDefaultModelSkipsForeignProviderModelIds(): void
    {
        $foreignDefault = new Model();
        $foreignDefault->setModelId('gpt-image-2');
        $foreignDefault->setIsDefault(true);

        $modelRepository = $this->createMock(ModelRepository::class);
        $modelRepository->method('findByCapability')
            ->willReturn(new InMemoryQueryResult([$foreignDefault]));

        $service = new FalImageService(
            $this->vaultStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $this->usageTrackerStub,
            $this->loggerStub,
            self::createStub(SpecializedCostCalculatorInterface::class),
            $modelRepository,
        );

        self::assertSame('flux-schnell', $service->resolveDefaultModel('flux-schnell'));
    }

    /**
     * Assert FAL exposes the Header placement and `Key ` prefix the secure
     * client uses to authenticate (FAL: `Authorization: Key <secret>`).
     */
    #[Test]
    public function getSecretPlacementUsesHeaderWithKeyPrefix(): void
    {
        $subject = $this->createSubject();

        $reflection = new ReflectionClass($subject);

        $placementMethod = $reflection->getMethod('getSecretPlacement');
        self::assertSame(SecretPlacement::Header, $placementMethod->invoke($subject));

        $optionsMethod = $reflection->getMethod('getSecretPlacementOptions');
        self::assertSame(
            ['headerName' => 'Authorization', 'prefix' => 'Key '],
            $optionsMethod->invoke($subject),
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createSubject(array $config = []): FalImageService
    {
        $defaultConfig = [
            'image' => [
                'fal' => [
                    'apiKeyIdentifier' => 'test-api-key',
                ],
            ],
        ];

        $this->extensionConfigMock
            ->expects(self::once())->method('get')
            ->with('nr_llm')
            ->willReturn(array_replace_recursive($defaultConfig, $config));

        return $this->buildService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $this->usageTrackerStub,
            $this->loggerStub,
        );
    }

    private function createSubjectWithoutApiKey(): FalImageService
    {
        $this->extensionConfigMock
            ->expects(self::once())->method('get')
            ->with('nr_llm')
            ->willReturn([
                'image' => [
                    'fal' => [],
                ],
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
        $responseBodyStub->method('__toString')->willReturn((string)json_encode($responseData));

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn(200);
        $responseStub->method('getBody')->willReturn($responseBodyStub);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($responseStub);
    }

    /**
     * @param array<string, mixed> $finalResponseData
     */
    private function setupQueueSuccessfulRequest(array $finalResponseData): void
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

        // Create response bodies for: queue submit, status poll, result fetch
        $queueSubmitBody = self::createStub(StreamInterface::class);
        $queueSubmitBody->method('__toString')->willReturn((string)json_encode(['request_id' => 'test-request-123']));

        $statusBody = self::createStub(StreamInterface::class);
        $statusBody->method('__toString')->willReturn((string)json_encode(['status' => 'COMPLETED']));

        $resultBody = self::createStub(StreamInterface::class);
        $resultBody->method('__toString')->willReturn((string)json_encode($finalResponseData));

        // Set up responses in order: submit, status, result
        $queueSubmitResponse = self::createStub(ResponseInterface::class);
        $queueSubmitResponse->method('getStatusCode')->willReturn(200);
        $queueSubmitResponse->method('getBody')->willReturn($queueSubmitBody);

        $statusResponse = self::createStub(ResponseInterface::class);
        $statusResponse->method('getStatusCode')->willReturn(200);
        $statusResponse->method('getBody')->willReturn($statusBody);

        $resultResponse = self::createStub(ResponseInterface::class);
        $resultResponse->method('getStatusCode')->willReturn(200);
        $resultResponse->method('getBody')->willReturn($resultBody);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls($queueSubmitResponse, $statusResponse, $resultResponse);
    }

    /**
     * Drive the queue flow with an explicit status sequence: one submit
     * response (carrying request_id), then one status response per entry in
     * $statuses, then (when $finalResponseData is given) the result fetch.
     *
     * @param list<string>              $statuses
     * @param array<string, mixed>|null $finalResponseData
     */
    private function setupQueueResponses(array $statuses, ?array $finalResponseData): void
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

        $responses = [];

        $submitBody = self::createStub(StreamInterface::class);
        $submitBody->method('__toString')->willReturn((string)json_encode(['request_id' => 'test-request-123']));
        $submitResponse = self::createStub(ResponseInterface::class);
        $submitResponse->method('getStatusCode')->willReturn(200);
        $submitResponse->method('getBody')->willReturn($submitBody);
        $responses[] = $submitResponse;

        foreach ($statuses as $status) {
            $statusBody = self::createStub(StreamInterface::class);
            $statusBody->method('__toString')->willReturn((string)json_encode(['status' => $status]));
            $statusResponse = self::createStub(ResponseInterface::class);
            $statusResponse->method('getStatusCode')->willReturn(200);
            $statusResponse->method('getBody')->willReturn($statusBody);
            $responses[] = $statusResponse;
        }

        if ($finalResponseData !== null) {
            $resultBody = self::createStub(StreamInterface::class);
            $resultBody->method('__toString')->willReturn((string)json_encode($finalResponseData));
            $resultResponse = self::createStub(ResponseInterface::class);
            $resultResponse->method('getStatusCode')->willReturn(200);
            $resultResponse->method('getBody')->willReturn($resultBody);
            $responses[] = $resultResponse;
        }

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls(...$responses);
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
        $responseBodyStub->method('__toString')->willReturn((string)json_encode([
            'detail' => $errorMessage,
        ]));

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn($statusCode);
        $responseStub->method('getBody')->willReturn($responseBodyStub);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($responseStub);
    }

    /**
     * Wire the request/stream factory stubs to record every outgoing request
     * (method + URI) and every serialized request body, so a test can assert
     * the exact URL the service constructed and the exact JSON payload it sent.
     * The recorded data lands in $this->capturedRequests / $this->capturedBodies.
     */
    private function installCapturingFactories(): void
    {
        $this->capturedRequests = [];
        $this->capturedBodies = [];

        $requestStub = self::createStub(RequestInterface::class);
        $requestStub->method('withHeader')->willReturnSelf();
        $requestStub->method('withBody')->willReturnSelf();

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturnCallback(function (string $method, UriInterface|string $uri) use ($requestStub): RequestInterface {
                $this->capturedRequests[] = ['method' => $method, 'uri' => (string)$uri];

                return $requestStub;
            });

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturnCallback(function (string $content = '') use ($streamStub): StreamInterface {
                $this->capturedBodies[] = $content;

                return $streamStub;
            });
    }

    /**
     * Queue a single successful JSON (non-queue path) response. Pairs with
     * installCapturingFactories() — it does not touch the request/stream
     * factories, so the capturing callbacks stay in place.
     *
     * @param array<string, mixed> $responseData
     */
    private function respondWithJson(array $responseData): void
    {
        $responseBodyStub = self::createStub(StreamInterface::class);
        $responseBodyStub->method('__toString')->willReturn((string)json_encode($responseData));

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn(200);
        $responseStub->method('getBody')->willReturn($responseBodyStub);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($responseStub);
    }

    /**
     * Queue the submit -> COMPLETED status -> result sequence a queue-based
     * model drives. Pairs with installCapturingFactories().
     *
     * @param array<string, mixed> $finalResponseData
     */
    private function respondWithQueue(array $finalResponseData): void
    {
        $submitBody = self::createStub(StreamInterface::class);
        $submitBody->method('__toString')->willReturn((string)json_encode(['request_id' => 'test-request-123']));
        $submitResponse = self::createStub(ResponseInterface::class);
        $submitResponse->method('getStatusCode')->willReturn(200);
        $submitResponse->method('getBody')->willReturn($submitBody);

        $statusBody = self::createStub(StreamInterface::class);
        $statusBody->method('__toString')->willReturn((string)json_encode(['status' => 'COMPLETED']));
        $statusResponse = self::createStub(ResponseInterface::class);
        $statusResponse->method('getStatusCode')->willReturn(200);
        $statusResponse->method('getBody')->willReturn($statusBody);

        $resultBody = self::createStub(StreamInterface::class);
        $resultBody->method('__toString')->willReturn((string)json_encode($finalResponseData));
        $resultResponse = self::createStub(ResponseInterface::class);
        $resultResponse->method('getStatusCode')->willReturn(200);
        $resultResponse->method('getBody')->willReturn($resultBody);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls($submitResponse, $statusResponse, $resultResponse);
    }

    /**
     * Set up a non-2xx response carrying the given (already-structured) JSON
     * error body, so error-decoding branches can be asserted precisely.
     *
     * @param array<string, mixed> $bodyData
     */
    private function setupFailedRequestRaw(int $statusCode, array $bodyData): void
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
        $responseBodyStub->method('__toString')->willReturn((string)json_encode($bodyData));

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn($statusCode);
        $responseStub->method('getBody')->willReturn($responseBodyStub);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($responseStub);
    }

    /**
     * Read a (possibly non-public) property off the subject via reflection.
     */
    private function readProperty(FalImageService $subject, string $property): mixed
    {
        return (new ReflectionClass($subject))->getProperty($property)->getValue($subject);
    }

    /**
     * Decode the JSON body the service sent for the request at the given index.
     *
     * @return array<string, mixed>
     */
    private function decodedBody(int $index = 0): array
    {
        self::assertArrayHasKey($index, $this->capturedBodies, 'no request body was captured');
        $decoded = json_decode($this->capturedBodies[$index], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
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
    public function getAvailableModelsReturnsModels(): void
    {
        $subject = $this->createSubject();

        $models = $subject->getAvailableModels();

        self::assertArrayHasKey('flux-pro', $models);
        self::assertArrayHasKey('flux-dev', $models);
        self::assertArrayHasKey('flux-schnell', $models);
        self::assertArrayHasKey('sdxl', $models);
    }

    #[Test]
    public function getAspectRatiosReturnsRatios(): void
    {
        $subject = $this->createSubject();

        $ratios = $subject->getAspectRatios();

        self::assertArrayHasKey('square', $ratios);
        self::assertArrayHasKey('landscape', $ratios);
        self::assertArrayHasKey('portrait', $ratios);
    }

    // ==================== generate tests ====================

    #[Test]
    public function generateReturnsImageGenerationResult(): void
    {
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest([
            'images' => [
                [
                    'url' => 'https://fal.media/files/image.png',
                    'width' => 1024,
                    'height' => 1024,
                ],
            ],
            'seed' => 12345,
        ]);

        $result = $subject->generate('A beautiful sunset');

        self::assertInstanceOf(ImageGenerationResult::class, $result);
        self::assertEquals('https://fal.media/files/image.png', $result->url);
        self::assertEquals('A beautiful sunset', $result->prompt);
        self::assertEquals('flux-schnell', $result->model);
        self::assertEquals('1024x1024', $result->size);
        self::assertEquals('fal', $result->provider);
    }

    #[Test]
    public function generateThrowsWhenServiceUnavailable(): void
    {
        $subject = $this->createSubjectWithoutApiKey();

        $this->expectException(ServiceUnavailableException::class);

        $subject->generate('A sunset');
    }

    #[Test]
    public function generateTracksUsage(): void
    {
        $this->setupSuccessfulRequest([
            'images' => [['url' => 'https://example.com/image.png']],
        ]);

        $this->extensionConfigMock
            ->expects(self::once())->method('get')
            ->with('nr_llm')
            ->willReturn([
                'image' => [
                    'fal' => [
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
                'fal',
                ['images' => 1],
                null,
                0,
                'flux-schnell',
                0,
                // Ambient fallback: no beUserUid options key was passed (ADR-057).
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

        $subject->generate('A sunset');
    }

    #[Test]
    public function generateAttributesUsageToOptionsKeyUid(): void
    {
        // ADR-057: the documented `beUserUid` options key reaches the usage
        // row; the payload builder's allowlist keeps it off the wire.
        $this->setupSuccessfulRequest([
            'images' => [['url' => 'https://fal.ai/image.png']],
        ]);

        $this->extensionConfigMock
            ->expects(self::once())->method('get')
            ->with('nr_llm')
            ->willReturn(['image' => ['fal' => ['apiKeyIdentifier' => 'test-api-key']]]);

        $usageTrackerMock = $this->createMock(UsageTrackerServiceInterface::class);
        $usageTrackerMock
            ->expects(self::once())
            ->method('trackUsage')
            ->with(
                'image',
                'fal',
                ['images' => 1],
                null,
                0,
                'flux-schnell',
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

        $subject->generate('A sunset', options: ['beUserUid' => 42]);
    }

    #[Test]
    public function generateWithDifferentModelUsesQueue(): void
    {
        $subject = $this->createSubject();
        // Queue-based models require request_id in response and then poll
        $this->setupQueueSuccessfulRequest([
            'images' => [['url' => 'https://example.com/image.png']],
        ]);

        $result = $subject->generate('A sunset', 'sdxl');

        self::assertEquals('sdxl', $result->model);
    }

    #[Test]
    public function generateWithFullEndpointPathUsesQueue(): void
    {
        $subject = $this->createSubject();
        $this->setupQueueSuccessfulRequest([
            'images' => [['url' => 'https://example.com/image.png']],
        ]);

        $result = $subject->generate('A sunset', 'fal-ai/custom-model');

        self::assertEquals('fal-ai/custom-model', $result->model);
    }

    #[Test]
    public function generateWithUnknownModelUsesQueue(): void
    {
        $subject = $this->createSubject();
        $this->setupQueueSuccessfulRequest([
            'images' => [['url' => 'https://example.com/image.png']],
        ]);

        $result = $subject->generate('A sunset', 'unknown-model');

        self::assertEquals('unknown-model', $result->model);
    }

    #[Test]
    public function generateWithOptions(): void
    {
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest([
            'images' => [['url' => 'https://example.com/image.png', 'width' => 1920, 'height' => 1080]],
        ]);

        $options = [
            'image_size' => 'landscape_16_9',
            'guidance_scale' => 7.5,
            'num_inference_steps' => 25,
            'seed' => 42,
            'negative_prompt' => 'blurry, low quality',
            'enable_safety_checker' => true,
        ];

        $result = $subject->generate('A sunset', 'flux-schnell', $options);

        self::assertInstanceOf(ImageGenerationResult::class, $result);
    }

    #[Test]
    public function generateWithWidthHeightOptions(): void
    {
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest([
            'images' => [['url' => 'https://example.com/image.png', 'width' => 1920, 'height' => 1080]],
        ]);

        $options = [
            'width' => 1920,
            'height' => 1080,
        ];

        $result = $subject->generate('A sunset', 'flux-schnell', $options);

        self::assertEquals('1920x1080', $result->size);
    }

    #[Test]
    public function generateExtractsDefaultSizeWhenMissing(): void
    {
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest([
            'images' => [['url' => 'https://example.com/image.png']],
        ]);

        $result = $subject->generate('A sunset');

        self::assertEquals('1024x1024', $result->size);
    }

    // ==================== generateMultiple tests ====================

    #[Test]
    public function generateMultipleReturnsMultipleResults(): void
    {
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest([
            'images' => [
                ['url' => 'https://example.com/image1.png', 'width' => 1024, 'height' => 1024],
                ['url' => 'https://example.com/image2.png', 'width' => 1024, 'height' => 1024],
            ],
        ]);

        $results = $subject->generateMultiple('A sunset', 2);

        self::assertCount(2, $results);
        self::assertInstanceOf(ImageGenerationResult::class, $results[0]);
        self::assertInstanceOf(ImageGenerationResult::class, $results[1]);
    }

    #[Test]
    public function generateMultipleLimitsToFour(): void
    {
        $subject = $this->createSubject();
        $responseData = array_fill(0, 4, ['url' => 'https://example.com/image.png', 'width' => 1024, 'height' => 1024]);
        $this->setupSuccessfulRequest(['images' => $responseData]);

        $results = $subject->generateMultiple('A sunset', 10);

        self::assertCount(4, $results);
    }

    #[Test]
    public function generateMultipleTracksUsage(): void
    {
        $this->setupSuccessfulRequest([
            'images' => [
                ['url' => 'https://example.com/image.png'],
            ],
        ]);

        $this->extensionConfigMock
            ->expects(self::once())->method('get')
            ->with('nr_llm')
            ->willReturn([
                'image' => [
                    'fal' => [
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
                'fal',
                self::callback(fn(array $metrics): bool => isset($metrics['images'])),
                null,
                0,
                'flux-schnell',
            );

        $subject = $this->buildService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $usageTrackerMock,
            $this->loggerStub,
        );

        $subject->generateMultiple('A sunset', 1);
    }

    #[Test]
    public function generateMultipleSkipsInvalidImageData(): void
    {
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest([
            'images' => [
                ['url' => 'https://example.com/image.png'],
                'invalid', // Non-array data should be skipped
            ],
        ]);

        $results = $subject->generateMultiple('A sunset', 2);

        self::assertCount(1, $results);
    }

    // ==================== imageToImage tests ====================

    #[Test]
    public function imageToImageReturnsResult(): void
    {
        $subject = $this->createSubject();
        // imageToImage uses flux-dev by default which uses queue
        $this->setupQueueSuccessfulRequest([
            'images' => [['url' => 'https://example.com/transformed.png']],
        ]);

        $result = $subject->imageToImage(
            'https://example.com/source.png',
            'Make it more colorful',
        );

        self::assertInstanceOf(ImageGenerationResult::class, $result);
    }

    #[Test]
    public function imageToImageWithCustomStrength(): void
    {
        $subject = $this->createSubject();
        // flux-dev uses queue
        $this->setupQueueSuccessfulRequest([
            'images' => [['url' => 'https://example.com/transformed.png']],
        ]);

        $result = $subject->imageToImage(
            'https://example.com/source.png',
            'Make it darker',
            'flux-dev',
            ['strength' => 0.5],
        );

        self::assertInstanceOf(ImageGenerationResult::class, $result);
    }

    // ==================== API error handling tests ====================

    #[Test]
    public function generateThrowsOnUnauthorized(): void
    {
        $subject = $this->createSubject();
        $this->setupFailedRequest(401, 'Invalid API key');

        $this->expectException(ServiceConfigurationException::class);

        $subject->generate('A sunset');
    }

    #[Test]
    public function generateThrowsOnForbidden(): void
    {
        $subject = $this->createSubject();
        $this->setupFailedRequest(403, 'Forbidden');

        $this->expectException(ServiceConfigurationException::class);

        $subject->generate('A sunset');
    }

    #[Test]
    public function generateThrowsOnRateLimitExceeded(): void
    {
        $subject = $this->createSubject();
        $this->setupFailedRequest(429, 'Rate limit exceeded');

        $this->expectException(ServiceUnavailableException::class);

        $subject->generate('A sunset');
    }

    #[Test]
    public function generateThrowsOnValidationError(): void
    {
        $subject = $this->createSubject();
        $this->setupFailedRequest(422, 'Invalid prompt');

        $this->expectException(ServiceUnavailableException::class);
        // FAL surfaces 422 with its own validation wording (see mapErrorStatus()):
        // the prefix and the decoded detail in that exact order.
        $this->expectExceptionMessage('FAL API validation error: Invalid prompt');

        $subject->generate('A sunset');
    }

    #[Test]
    public function generateThrowsOnServerError(): void
    {
        $subject = $this->createSubject();
        $this->setupFailedRequest(500, 'Internal server error');

        $this->expectException(ServiceUnavailableException::class);

        $subject->generate('A sunset');
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
    public function loadConfigurationHandlesMissingImageConfig(): void
    {
        $this->extensionConfigMock
            ->expects(self::once())->method('get')
            ->with('nr_llm')
            ->willReturn([]);

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
    public function loadConfigurationHandlesMissingFalConfig(): void
    {
        $this->extensionConfigMock
            ->expects(self::once())->method('get')
            ->with('nr_llm')
            ->willReturn(['image' => []]);

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
            'image' => [
                'fal' => [
                    'apiKeyIdentifier' => 'test-api-key',
                    'baseUrl' => 'https://custom-api.example.com',
                    'timeout' => 180,
                    'pollInterval' => 2000,
                ],
            ],
        ];

        $subject = $this->createSubject($config);

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
    public function loadConfigurationHandlesNumericTypes(): void
    {
        $this->extensionConfigMock
            ->expects(self::once())->method('get')
            ->with('nr_llm')
            ->willReturn([
                'image' => [
                    'fal' => [
                        'apiKeyIdentifier' => 'test-key',
                        'timeout' => '180', // String instead of int
                        'pollInterval' => '2000', // String instead of int
                    ],
                ],
            ]);

        $subject = $this->buildService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $this->usageTrackerStub,
            $this->loggerStub,
        );

        self::assertTrue($subject->isAvailable());
    }

    // ==================== Polling tests ====================

    #[Test]
    public function generateWithQueueDoesNotCrashWhenPollIntervalIsZero(): void
    {
        // A configured pollInterval of 0 must be clamped (max(1, …)) so the
        // maxAttempts division and the usleep() loop don't blow up.
        $config = [
            'image' => [
                'fal' => [
                    'apiKeyIdentifier' => 'test-api-key',
                    'pollInterval' => 0,
                ],
            ],
        ];
        $subject = $this->createSubject($config);

        // Queue path: submit → status COMPLETED → result.
        $this->setupQueueResponses(
            ['COMPLETED'],
            ['images' => [['url' => 'https://example.com/image.png']]],
        );

        $result = $subject->generate('A sunset', 'sdxl');

        self::assertSame('sdxl', $result->model);
    }

    #[Test]
    public function pollForResultThrowsTimeoutWhenStatusNeverCompletes(): void
    {
        // timeout 1s / pollInterval 1000ms → exactly one non-terminal poll,
        // then the loop is exhausted and a timeout is raised.
        $config = [
            'image' => [
                'fal' => [
                    'apiKeyIdentifier' => 'test-api-key',
                    'timeout' => 1,
                    'pollInterval' => 1000,
                ],
            ],
        ];
        $subject = $this->createSubject($config);

        $this->setupQueueResponses(['IN_PROGRESS'], null);

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('FAL generation timed out');

        $subject->generate('A sunset', 'sdxl');
    }

    #[Test]
    public function pollForResultSucceedsAfterAPendingPoll(): void
    {
        // timeout 1s / pollInterval 200ms → up to 5 polls. The first status is
        // non-terminal, the second COMPLETED — covers a multi-iteration poll.
        $config = [
            'image' => [
                'fal' => [
                    'apiKeyIdentifier' => 'test-api-key',
                    'timeout' => 1,
                    'pollInterval' => 200,
                ],
            ],
        ];
        $subject = $this->createSubject($config);

        $this->setupQueueResponses(
            ['IN_PROGRESS', 'COMPLETED'],
            ['images' => [['url' => 'https://example.com/image.png']]],
        );

        $result = $subject->generate('A sunset', 'sdxl');

        self::assertSame('sdxl', $result->model);
    }

    // ==================== Queue error handling tests ====================

    #[Test]
    public function generateWithQueueThrowsWhenNoRequestIdReturned(): void
    {
        $subject = $this->createSubject();

        // Setup queue submit response without request_id
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

        $queueSubmitBody = self::createStub(StreamInterface::class);
        $queueSubmitBody->method('__toString')->willReturn((string)json_encode(['status' => 'queued']));

        $queueSubmitResponse = self::createStub(ResponseInterface::class);
        $queueSubmitResponse->method('getStatusCode')->willReturn(200);
        $queueSubmitResponse->method('getBody')->willReturn($queueSubmitBody);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($queueSubmitResponse);

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('no request_id');

        $subject->generate('A sunset', 'sdxl'); // sdxl uses queue
    }

    #[Test]
    public function generateWithQueueThrowsWhenStatusFailed(): void
    {
        $subject = $this->createSubject();

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

        // Queue submit returns request_id
        $queueSubmitBody = self::createStub(StreamInterface::class);
        $queueSubmitBody->method('__toString')->willReturn((string)json_encode(['request_id' => 'test-123']));

        $queueSubmitResponse = self::createStub(ResponseInterface::class);
        $queueSubmitResponse->method('getStatusCode')->willReturn(200);
        $queueSubmitResponse->method('getBody')->willReturn($queueSubmitBody);

        // Status poll returns FAILED
        $statusBody = self::createStub(StreamInterface::class);
        $statusBody->method('__toString')->willReturn((string)json_encode([
            'status' => 'FAILED',
            'error' => 'Generation failed due to content policy',
        ]));

        $statusResponse = self::createStub(ResponseInterface::class);
        $statusResponse->method('getStatusCode')->willReturn(200);
        $statusResponse->method('getBody')->willReturn($statusBody);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls($queueSubmitResponse, $statusResponse);

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('FAL generation failed');

        $subject->generate('A sunset', 'sdxl');
    }

    #[Test]
    public function generateThrowsOnConnectionError(): void
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

        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock->expects(self::once())->method('error');

        $httpClientStub = self::createStub(ClientInterface::class);
        $httpClientStub
            ->method('sendRequest')
            ->willThrowException(new RuntimeException('Connection timeout'));

        $this->extensionConfigMock
            ->expects(self::once())->method('get')
            ->with('nr_llm')
            ->willReturn([
                'image' => [
                    'fal' => [
                        'apiKeyIdentifier' => 'test-api-key',
                    ],
                ],
            ]);

        $subject = $this->buildService(
            $httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $this->usageTrackerStub,
            $loggerMock,
        );

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('Failed to connect to FAL API');

        $subject->generate('A sunset');
    }

    #[Test]
    public function generateHandlesErrorWithMessageInsteadOfDetail(): void
    {
        $subject = $this->createSubject();

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
        $responseBodyStub->method('__toString')->willReturn((string)json_encode([
            'message' => 'Invalid model specified',
        ]));

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn(400);
        $responseStub->method('getBody')->willReturn($responseBodyStub);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($responseStub);

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('Invalid model specified');

        $subject->generate('A sunset');
    }

    #[Test]
    public function generateHandlesNonImageData(): void
    {
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest([
            'images' => 'not-an-array',
            'seed' => 12345,
        ]);

        $result = $subject->generate('A beautiful sunset');

        // Should handle gracefully - no URL
        self::assertInstanceOf(ImageGenerationResult::class, $result);
        self::assertEquals('', $result->url);
    }

    #[Test]
    public function generateWithNumImagesOption(): void
    {
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest([
            'images' => [
                ['url' => 'https://example.com/image.png', 'width' => 1024, 'height' => 1024],
            ],
        ]);

        $result = $subject->generate('A sunset', 'flux-schnell', [
            'num_images' => '2', // String, should be converted
        ]);

        self::assertInstanceOf(ImageGenerationResult::class, $result);
    }

    #[Test]
    public function generateMultipleWithQueuedModel(): void
    {
        $subject = $this->createSubject();
        $this->setupQueueSuccessfulRequest([
            'images' => [
                ['url' => 'https://example.com/image1.png', 'width' => 1024, 'height' => 1024],
                ['url' => 'https://example.com/image2.png', 'width' => 1024, 'height' => 1024],
            ],
        ]);

        $results = $subject->generateMultiple('A sunset', 2, 'flux-pro');

        self::assertCount(2, $results);
    }

    #[Test]
    public function generateMultipleEnforcesMinimumCount(): void
    {
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest([
            'images' => [
                ['url' => 'https://example.com/image.png'],
            ],
        ]);

        $results = $subject->generateMultiple('A sunset', 0); // Should be set to 1

        self::assertCount(1, $results);
    }

    // ==================== request URL / endpoint construction ====================

    #[Test]
    public function generateBuildsQueueUrlFromMappedModelEndpoint(): void
    {
        // flux-pro maps to fal-ai/flux-pro (self::MODELS) and is not a "fast"
        // model, so it takes the queue path. Pins resolveModelEndpoint()'s
        // mapped-model branch and the queue-URL assembly.
        $subject = $this->createSubject();
        $this->installCapturingFactories();
        $this->respondWithQueue(['images' => [['url' => 'https://example.com/image.png']]]);

        $subject->generate('A sunset', 'flux-pro');

        self::assertSame('POST', $this->capturedRequests[0]['method']);
        self::assertSame('https://queue.fal.run/fal-ai/flux-pro', $this->capturedRequests[0]['uri']);
    }

    #[Test]
    public function generateUsesFullEndpointPathVerbatimInQueueUrl(): void
    {
        // A slash-bearing model id is an explicit endpoint and must be used
        // verbatim (resolveModelEndpoint()'s str_contains branch), never
        // rewritten to the flux-schnell fallback.
        $subject = $this->createSubject();
        $this->installCapturingFactories();
        $this->respondWithQueue(['images' => [['url' => 'https://example.com/image.png']]]);

        $subject->generate('A sunset', 'fal-ai/custom-model');

        self::assertSame('POST', $this->capturedRequests[0]['method']);
        self::assertSame('https://queue.fal.run/fal-ai/custom-model', $this->capturedRequests[0]['uri']);
    }

    #[Test]
    public function generateSendsPromptAndDefaultImageSizeInPayload(): void
    {
        $subject = $this->createSubject();
        $this->installCapturingFactories();
        $this->respondWithJson(['images' => [['url' => 'https://example.com/image.png']]]);

        $subject->generate('A beautiful sunset');

        $payload = $this->decodedBody();
        self::assertSame('A beautiful sunset', $payload['prompt']);
        self::assertSame('square_hd', $payload['image_size']);
    }

    // ==================== num_images clamping (payload) ====================

    /**
     * @return array<string, array{int, int}>
     */
    public static function numImagesClampProvider(): array
    {
        return [
            'one stays one'        => [1, 1],
            'zero clamps up to one' => [0, 1],
            'two stays two'        => [2, 2],
            'four stays four'      => [4, 4],
            'ten clamps to four'   => [10, 4],
        ];
    }

    #[Test]
    #[DataProvider('numImagesClampProvider')]
    public function generateMultipleClampsNumImagesInPayload(int $inputCount, int $expectedNumImages): void
    {
        $subject = $this->createSubject();
        $this->installCapturingFactories();
        $this->respondWithJson(['images' => [['url' => 'https://example.com/image.png']]]);

        $subject->generateMultiple('A sunset', $inputCount);

        self::assertSame($expectedNumImages, $this->decodedBody()['num_images']);
    }

    #[Test]
    public function generateMultipleDefaultsToASingleImageInPayload(): void
    {
        // Default $count (omitted) must resolve to num_images = 1.
        $subject = $this->createSubject();
        $this->installCapturingFactories();
        $this->respondWithJson(['images' => [['url' => 'https://example.com/image.png']]]);

        $subject->generateMultiple('A sunset');

        self::assertSame(1, $this->decodedBody()['num_images']);
    }

    // ==================== availability guards ====================

    #[Test]
    public function generateMultipleThrowsWhenServiceUnavailable(): void
    {
        $subject = $this->createSubjectWithoutApiKey();

        $this->expectException(ServiceUnavailableException::class);

        $subject->generateMultiple('A sunset', 2);
    }

    #[Test]
    public function imageToImageThrowsWhenServiceUnavailable(): void
    {
        $subject = $this->createSubjectWithoutApiKey();

        $this->expectException(ServiceUnavailableException::class);

        $subject->imageToImage('https://example.com/source.png', 'Make it colorful');
    }

    // ==================== imageToImage payload ====================

    #[Test]
    public function imageToImageKeepsCallerStrengthInPayload(): void
    {
        // The caller-supplied strength must survive (`??=` keeps it); it is
        // only defaulted when absent.
        $subject = $this->createSubject();
        $this->installCapturingFactories();
        $this->respondWithQueue(['images' => [['url' => 'https://example.com/transformed.png']]]);

        $subject->imageToImage(
            'https://example.com/source.png',
            'Make it darker',
            'flux-dev',
            ['strength' => 0.5],
        );

        $payload = $this->decodedBody();
        self::assertSame('https://example.com/source.png', $payload['image_url']);
        self::assertSame(0.5, $payload['strength']);
    }

    #[Test]
    public function imageToImageAppliesDefaultStrengthInPayload(): void
    {
        $subject = $this->createSubject();
        $this->installCapturingFactories();
        $this->respondWithQueue(['images' => [['url' => 'https://example.com/transformed.png']]]);

        $subject->imageToImage('https://example.com/source.png', 'Make it colorful');

        self::assertSame(0.75, $this->decodedBody()['strength']);
    }

    // ==================== response parsing: metadata / url / size ====================

    #[Test]
    public function generatePrefersResponseSeedOverImageSeedInMetadata(): void
    {
        // metadata seed = $response['seed'] ?? $image['seed']: the top-level
        // response seed wins over the per-image seed.
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest([
            'images' => [['url' => 'https://example.com/image.png', 'seed' => 222]],
            'seed'   => 111,
        ]);

        $result = $subject->generate('A sunset');

        assert(isset($result->metadata['seed']));
        self::assertSame(111, $result->metadata['seed']);
    }

    #[Test]
    public function generateFallsBackToImageSeedWhenResponseSeedMissing(): void
    {
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest([
            'images' => [['url' => 'https://example.com/image.png', 'seed' => 222]],
        ]);

        $result = $subject->generate('A sunset');

        assert(isset($result->metadata['seed']));
        self::assertSame(222, $result->metadata['seed']);
    }

    #[Test]
    public function generateReturnsEmptyImageWhenFirstImageIsNotAnArray(): void
    {
        // images is a list, but images[0] is a scalar: the guard
        // (is_array($images) && isset($images[0]) && is_array($images[0]))
        // must fall to the empty-image branch, yielding an empty URL and the
        // default size rather than dereferencing the scalar.
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest([
            'images' => ['not-an-array-element'],
        ]);

        $result = $subject->generate('A sunset');

        self::assertSame('', $result->url);
        self::assertSame('1024x1024', $result->size);
    }

    #[Test]
    public function generateMultipleExposesImageUrls(): void
    {
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest([
            'images' => [
                ['url' => 'https://example.com/image1.png'],
                ['url' => 'https://example.com/image2.png'],
            ],
        ]);

        $results = $subject->generateMultiple('A sunset', 2);

        self::assertSame('https://example.com/image1.png', $results[0]->url);
        self::assertSame('https://example.com/image2.png', $results[1]->url);
    }

    #[Test]
    public function generateMultipleYieldsEmptyUrlForNonStringUrl(): void
    {
        // A non-string url must be rejected by the
        // isset() && is_string() guard, leaving an empty URL.
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest([
            'images' => [
                ['url' => 12345],
            ],
        ]);

        $results = $subject->generateMultiple('A sunset', 1);

        self::assertCount(1, $results);
        self::assertSame('', $results[0]->url);
    }

    #[Test]
    public function generateMultipleIncludesPerImageSeedInMetadata(): void
    {
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest([
            'images' => [
                ['url' => 'https://example.com/image.png', 'seed' => 987],
            ],
        ]);

        $results = $subject->generateMultiple('A sunset', 1);

        assert(isset($results[0]->metadata['seed']));
        self::assertSame(987, $results[0]->metadata['seed']);
    }

    #[Test]
    public function generateMultipleContinuesPastLeadingInvalidImageData(): void
    {
        // The invalid (non-array) entry comes FIRST: the loop must `continue`
        // past it and still collect the trailing valid image (a `break` here
        // would drop it).
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest([
            'images' => [
                'invalid',
                ['url' => 'https://example.com/image.png'],
            ],
        ]);

        $results = $subject->generateMultiple('A sunset', 2);

        self::assertCount(1, $results);
        self::assertSame('https://example.com/image.png', $results[0]->url);
    }

    // ==================== usage attribution (beUserUid) ====================

    #[Test]
    public function generateAttributesUsageToZeroBeUserUid(): void
    {
        // uid 0 is a valid backend user id (>= 0), so it must reach the usage
        // row rather than being treated as absent.
        $this->setupSuccessfulRequest(['images' => [['url' => 'https://fal.ai/image.png']]]);

        $this->extensionConfigMock
            ->expects(self::once())->method('get')
            ->with('nr_llm')
            ->willReturn(['image' => ['fal' => ['apiKeyIdentifier' => 'test-api-key']]]);

        $usageTrackerMock = $this->createMock(UsageTrackerServiceInterface::class);
        $usageTrackerMock
            ->expects(self::once())
            ->method('trackUsage')
            ->with('image', 'fal', ['images' => 1], null, 0, 'flux-schnell', 0, 0);

        $subject = $this->buildService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $usageTrackerMock,
            $this->loggerStub,
        );

        $subject->generate('A sunset', options: ['beUserUid' => 0]);
    }

    #[Test]
    public function generateTreatsNegativeBeUserUidAsAbsent(): void
    {
        // A negative uid is not a real backend user: extractBeUserUid() must
        // drop it (is_int AND >= 0), so attribution falls back to null.
        $this->setupSuccessfulRequest(['images' => [['url' => 'https://fal.ai/image.png']]]);

        $this->extensionConfigMock
            ->expects(self::once())->method('get')
            ->with('nr_llm')
            ->willReturn(['image' => ['fal' => ['apiKeyIdentifier' => 'test-api-key']]]);

        $usageTrackerMock = $this->createMock(UsageTrackerServiceInterface::class);
        $usageTrackerMock
            ->expects(self::once())
            ->method('trackUsage')
            ->with('image', 'fal', ['images' => 1], null, 0, 'flux-schnell', 0, null);

        $subject = $this->buildService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $usageTrackerMock,
            $this->loggerStub,
        );

        $subject->generate('A sunset', options: ['beUserUid' => -5]);
    }

    // ==================== error decoding / mapping ====================

    #[Test]
    public function generateSurfacesErrorDetailInMessage(): void
    {
        $subject = $this->createSubject();
        $this->setupFailedRequest(500, 'Boom detail');

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('Boom detail');

        $subject->generate('A sunset');
    }

    #[Test]
    public function generateUsesUnknownLabelWhenDetailAndMessageEmpty(): void
    {
        // An empty detail AND empty message must not be surfaced verbatim —
        // decodeErrorMessage() falls through to the unknown-error label.
        $subject = $this->createSubject();
        $this->setupFailedRequestRaw(500, ['detail' => '', 'message' => '']);

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('Unknown FAL API error');

        $subject->generate('A sunset');
    }

    #[Test]
    public function validationErrorCarriesProviderContext(): void
    {
        $subject = $this->createSubject();
        $this->setupFailedRequest(422, 'Invalid prompt');

        try {
            $subject->generate('A sunset');
            self::fail('Expected ServiceUnavailableException');
        } catch (ServiceUnavailableException $e) {
            self::assertSame(['provider' => 'fal'], $e->context);
        }
    }

    // ==================== configuration: timeout / pollInterval ====================

    #[Test]
    public function defaultTimeoutIsTwoMinutes(): void
    {
        $subject = $this->createSubject();

        $method = (new ReflectionClass($subject))->getMethod('getDefaultTimeout');

        self::assertSame(120, $method->invoke($subject));
    }

    #[Test]
    public function loadConfigurationCastsNumericStringTimeoutToInt(): void
    {
        $subject = $this->createSubject([
            'image' => ['fal' => ['timeout' => '180']],
        ]);

        self::assertSame(180, $this->readProperty($subject, 'timeout'));
    }

    #[Test]
    public function loadConfigurationDefaultsPollIntervalToOneThousand(): void
    {
        $subject = $this->createSubject(); // no pollInterval configured

        self::assertSame(1000, $this->readProperty($subject, 'pollInterval'));
    }

    #[Test]
    public function loadConfigurationCastsNumericStringPollIntervalToInt(): void
    {
        $subject = $this->createSubject([
            'image' => ['fal' => ['pollInterval' => '2000']],
        ]);

        self::assertSame(2000, $this->readProperty($subject, 'pollInterval'));
    }

    #[Test]
    public function loadConfigurationDefaultsPollIntervalWhenNonNumeric(): void
    {
        $subject = $this->createSubject([
            'image' => ['fal' => ['pollInterval' => 'not-a-number']],
        ]);

        self::assertSame(1000, $this->readProperty($subject, 'pollInterval'));
    }

    #[Test]
    public function loadConfigurationClampsPollIntervalToAtLeastOne(): void
    {
        // A configured 1ms interval must pass through unchanged (max(1, …)).
        $subject = $this->createSubject([
            'image' => ['fal' => ['pollInterval' => 1]],
        ]);

        self::assertSame(1, $this->readProperty($subject, 'pollInterval'));
    }
}
