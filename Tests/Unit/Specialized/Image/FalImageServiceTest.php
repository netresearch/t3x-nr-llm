<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Image;

use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Specialized\Exception\ServiceConfigurationException;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Image\FalImageService;
use Netresearch\NrLlm\Specialized\Image\ImageGenerationResult;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(FalImageService::class)]
class FalImageServiceTest extends AbstractUnitTestCase
{
    private ClientInterface&Stub $httpClientStub;
    private RequestFactoryInterface&Stub $requestFactoryStub;
    private StreamFactoryInterface&Stub $streamFactoryStub;
    private ExtensionConfiguration&Stub $extensionConfigStub;
    private UsageTrackerServiceInterface&Stub $usageTrackerStub;
    private LoggerInterface&Stub $loggerStub;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClientStub = self::createStub(ClientInterface::class);
        $this->requestFactoryStub = self::createStub(RequestFactoryInterface::class);
        $this->streamFactoryStub = self::createStub(StreamFactoryInterface::class);
        $this->extensionConfigStub = self::createStub(ExtensionConfiguration::class);
        $this->usageTrackerStub = self::createStub(UsageTrackerServiceInterface::class);
        $this->loggerStub = self::createStub(LoggerInterface::class);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createSubject(array $config = []): FalImageService
    {
        $defaultConfig = [
            'image' => [
                'fal' => [
                    'apiKey' => 'test-api-key',
                ],
            ],
        ];

        $this->extensionConfigStub
            ->method('get')
            ->with('nr_llm')
            ->willReturn(array_replace_recursive($defaultConfig, $config));

        return new FalImageService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigStub,
            $this->usageTrackerStub,
            $this->loggerStub,
        );
    }

    private function createSubjectWithoutApiKey(): FalImageService
    {
        $this->extensionConfigStub
            ->method('get')
            ->with('nr_llm')
            ->willReturn([
                'image' => [
                    'fal' => [],
                ],
            ]);

        return new FalImageService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigStub,
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

        $this->extensionConfigStub
            ->method('get')
            ->with('nr_llm')
            ->willReturn([
                'image' => [
                    'fal' => [
                        'apiKey' => 'test-api-key',
                    ],
                ],
            ]);

        $usageTrackerMock = $this->createMock(UsageTrackerServiceInterface::class);
        $usageTrackerMock
            ->expects(self::once())
            ->method('trackUsage')
            ->with('image', 'fal:flux-schnell', self::anything());

        $subject = new FalImageService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigStub,
            $usageTrackerMock,
            $this->loggerStub,
        );

        $subject->generate('A sunset');
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

        $this->extensionConfigStub
            ->method('get')
            ->with('nr_llm')
            ->willReturn([
                'image' => [
                    'fal' => [
                        'apiKey' => 'test-api-key',
                    ],
                ],
            ]);

        $usageTrackerMock = $this->createMock(UsageTrackerServiceInterface::class);
        $usageTrackerMock
            ->expects(self::once())
            ->method('trackUsage')
            ->with('image', 'fal:flux-schnell', self::callback(fn($data) => is_array($data) && isset($data['count'])));

        $subject = new FalImageService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigStub,
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
        $this->extensionConfigStub
            ->method('get')
            ->with('nr_llm')
            ->willReturn('not-an-array');

        $subject = new FalImageService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigStub,
            $this->usageTrackerStub,
            $this->loggerStub,
        );

        self::assertFalse($subject->isAvailable());
    }

    #[Test]
    public function loadConfigurationHandlesMissingImageConfig(): void
    {
        $this->extensionConfigStub
            ->method('get')
            ->with('nr_llm')
            ->willReturn([]);

        $subject = new FalImageService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigStub,
            $this->usageTrackerStub,
            $this->loggerStub,
        );

        self::assertFalse($subject->isAvailable());
    }

    #[Test]
    public function loadConfigurationHandlesMissingFalConfig(): void
    {
        $this->extensionConfigStub
            ->method('get')
            ->with('nr_llm')
            ->willReturn(['image' => []]);

        $subject = new FalImageService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigStub,
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
                    'apiKey' => 'test-api-key',
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

        $subject = new FalImageService(
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
        $this->extensionConfigStub
            ->method('get')
            ->with('nr_llm')
            ->willReturn([
                'image' => [
                    'fal' => [
                        'apiKey' => 'test-key',
                        'timeout' => '180', // String instead of int
                        'pollInterval' => '2000', // String instead of int
                    ],
                ],
            ]);

        $subject = new FalImageService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigStub,
            $this->usageTrackerStub,
            $this->loggerStub,
        );

        self::assertTrue($subject->isAvailable());
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

        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock->expects(self::once())->method('error');

        $httpClientStub = self::createStub(ClientInterface::class);
        $httpClientStub
            ->method('sendRequest')
            ->willThrowException(new RuntimeException('Connection timeout'));

        $this->extensionConfigStub
            ->method('get')
            ->with('nr_llm')
            ->willReturn([
                'image' => [
                    'fal' => [
                        'apiKey' => 'test-api-key',
                    ],
                ],
            ]);

        $subject = new FalImageService(
            $httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigStub,
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
}
