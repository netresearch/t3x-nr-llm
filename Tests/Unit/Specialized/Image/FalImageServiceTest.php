<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Image;

use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Specialized\Exception\ServiceConfigurationException;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Image\FalImageService;
use Netresearch\NrLlm\Specialized\Image\ImageGenerationResult;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
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
    private ClientInterface&MockObject $httpClientMock;
    private RequestFactoryInterface&MockObject $requestFactoryMock;
    private StreamFactoryInterface&MockObject $streamFactoryMock;
    private ExtensionConfiguration&MockObject $extensionConfigMock;
    private UsageTrackerServiceInterface&MockObject $usageTrackerMock;
    private LoggerInterface&MockObject $loggerMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClientMock = $this->createMock(ClientInterface::class);
        $this->requestFactoryMock = $this->createMock(RequestFactoryInterface::class);
        $this->streamFactoryMock = $this->createMock(StreamFactoryInterface::class);
        $this->extensionConfigMock = $this->createMock(ExtensionConfiguration::class);
        $this->usageTrackerMock = $this->createMock(UsageTrackerServiceInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
    }

    private function createSubject(array $config = []): FalImageService
    {
        $defaultConfig = [
            'image' => [
                'fal' => [
                    'apiKey' => 'test-api-key',
                ],
            ],
        ];

        $this->extensionConfigMock
            ->method('get')
            ->with('nr_llm')
            ->willReturn(array_replace_recursive($defaultConfig, $config));

        return new FalImageService(
            $this->httpClientMock,
            $this->requestFactoryMock,
            $this->streamFactoryMock,
            $this->extensionConfigMock,
            $this->usageTrackerMock,
            $this->loggerMock,
        );
    }

    private function createSubjectWithoutApiKey(): FalImageService
    {
        $this->extensionConfigMock
            ->method('get')
            ->with('nr_llm')
            ->willReturn([
                'image' => [
                    'fal' => [],
                ],
            ]);

        return new FalImageService(
            $this->httpClientMock,
            $this->requestFactoryMock,
            $this->streamFactoryMock,
            $this->extensionConfigMock,
            $this->usageTrackerMock,
            $this->loggerMock,
        );
    }

    private function setupSuccessfulRequest(array $responseData): void
    {
        $requestMock = $this->createMock(RequestInterface::class);
        $requestMock->method('withHeader')->willReturnSelf();
        $requestMock->method('withBody')->willReturnSelf();

        $this->requestFactoryMock
            ->method('createRequest')
            ->willReturn($requestMock);

        $streamMock = $this->createMock(StreamInterface::class);
        $this->streamFactoryMock
            ->method('createStream')
            ->willReturn($streamMock);

        $responseBodyMock = $this->createMock(StreamInterface::class);
        $responseBodyMock->method('__toString')->willReturn(json_encode($responseData));

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('getBody')->willReturn($responseBodyMock);

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($responseMock);
    }

    private function setupQueueSuccessfulRequest(array $finalResponseData): void
    {
        $requestMock = $this->createMock(RequestInterface::class);
        $requestMock->method('withHeader')->willReturnSelf();
        $requestMock->method('withBody')->willReturnSelf();

        $this->requestFactoryMock
            ->method('createRequest')
            ->willReturn($requestMock);

        $streamMock = $this->createMock(StreamInterface::class);
        $this->streamFactoryMock
            ->method('createStream')
            ->willReturn($streamMock);

        // Create response bodies for: queue submit, status poll, result fetch
        $queueSubmitBody = $this->createMock(StreamInterface::class);
        $queueSubmitBody->method('__toString')->willReturn(json_encode(['request_id' => 'test-request-123']));

        $statusBody = $this->createMock(StreamInterface::class);
        $statusBody->method('__toString')->willReturn(json_encode(['status' => 'COMPLETED']));

        $resultBody = $this->createMock(StreamInterface::class);
        $resultBody->method('__toString')->willReturn(json_encode($finalResponseData));

        // Set up responses in order: submit, status, result
        $queueSubmitResponse = $this->createMock(ResponseInterface::class);
        $queueSubmitResponse->method('getStatusCode')->willReturn(200);
        $queueSubmitResponse->method('getBody')->willReturn($queueSubmitBody);

        $statusResponse = $this->createMock(ResponseInterface::class);
        $statusResponse->method('getStatusCode')->willReturn(200);
        $statusResponse->method('getBody')->willReturn($statusBody);

        $resultResponse = $this->createMock(ResponseInterface::class);
        $resultResponse->method('getStatusCode')->willReturn(200);
        $resultResponse->method('getBody')->willReturn($resultBody);

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls($queueSubmitResponse, $statusResponse, $resultResponse);
    }

    private function setupFailedRequest(int $statusCode, string $errorMessage = 'API Error'): void
    {
        $requestMock = $this->createMock(RequestInterface::class);
        $requestMock->method('withHeader')->willReturnSelf();
        $requestMock->method('withBody')->willReturnSelf();

        $this->requestFactoryMock
            ->method('createRequest')
            ->willReturn($requestMock);

        $streamMock = $this->createMock(StreamInterface::class);
        $this->streamFactoryMock
            ->method('createStream')
            ->willReturn($streamMock);

        $responseBodyMock = $this->createMock(StreamInterface::class);
        $responseBodyMock->method('__toString')->willReturn(json_encode([
            'detail' => $errorMessage,
        ]));

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn($statusCode);
        $responseMock->method('getBody')->willReturn($responseBodyMock);

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($responseMock);
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
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest([
            'images' => [['url' => 'https://example.com/image.png']],
        ]);

        $this->usageTrackerMock
            ->expects(self::once())
            ->method('trackUsage')
            ->with('image', 'fal:flux-schnell', self::anything());

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
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest([
            'images' => [
                ['url' => 'https://example.com/image.png'],
            ],
        ]);

        $this->usageTrackerMock
            ->expects(self::once())
            ->method('trackUsage')
            ->with('image', 'fal:flux-schnell', self::callback(fn($data) => isset($data['count'])));

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
        $this->extensionConfigMock
            ->method('get')
            ->with('nr_llm')
            ->willReturn('not-an-array');

        $subject = new FalImageService(
            $this->httpClientMock,
            $this->requestFactoryMock,
            $this->streamFactoryMock,
            $this->extensionConfigMock,
            $this->usageTrackerMock,
            $this->loggerMock,
        );

        self::assertFalse($subject->isAvailable());
    }

    #[Test]
    public function loadConfigurationHandlesMissingImageConfig(): void
    {
        $this->extensionConfigMock
            ->method('get')
            ->with('nr_llm')
            ->willReturn([]);

        $subject = new FalImageService(
            $this->httpClientMock,
            $this->requestFactoryMock,
            $this->streamFactoryMock,
            $this->extensionConfigMock,
            $this->usageTrackerMock,
            $this->loggerMock,
        );

        self::assertFalse($subject->isAvailable());
    }

    #[Test]
    public function loadConfigurationHandlesMissingFalConfig(): void
    {
        $this->extensionConfigMock
            ->method('get')
            ->with('nr_llm')
            ->willReturn(['image' => []]);

        $subject = new FalImageService(
            $this->httpClientMock,
            $this->requestFactoryMock,
            $this->streamFactoryMock,
            $this->extensionConfigMock,
            $this->usageTrackerMock,
            $this->loggerMock,
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
        $this->extensionConfigMock
            ->method('get')
            ->willThrowException(new RuntimeException('Config error'));

        $this->loggerMock
            ->expects(self::once())
            ->method('warning');

        $subject = new FalImageService(
            $this->httpClientMock,
            $this->requestFactoryMock,
            $this->streamFactoryMock,
            $this->extensionConfigMock,
            $this->usageTrackerMock,
            $this->loggerMock,
        );

        self::assertFalse($subject->isAvailable());
    }

    #[Test]
    public function loadConfigurationHandlesNumericTypes(): void
    {
        $this->extensionConfigMock
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
            $this->httpClientMock,
            $this->requestFactoryMock,
            $this->streamFactoryMock,
            $this->extensionConfigMock,
            $this->usageTrackerMock,
            $this->loggerMock,
        );

        self::assertTrue($subject->isAvailable());
    }
}
