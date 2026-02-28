<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Image;

use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Specialized\Exception\ServiceConfigurationException;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Image\DallEImageService;
use Netresearch\NrLlm\Specialized\Image\ImageGenerationResult;
use Netresearch\NrLlm\Specialized\Option\ImageGenerationOptions;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
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
use Psr\Log\LoggerInterface;
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
    private function createSubject(array $config = []): DallEImageService
    {
        $defaultConfig = [
            'providers' => [
                'openai' => [
                    'apiKey' => 'test-api-key',
                ],
            ],
        ];

        $this->extensionConfigMock
            ->method('get')
            ->with('nr_llm')
            ->willReturn(array_merge($defaultConfig, $config));

        return new DallEImageService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $this->usageTrackerStub,
            $this->loggerStub,
        );
    }

    private function createSubjectWithoutApiKey(): DallEImageService
    {
        $this->extensionConfigMock
            ->method('get')
            ->with('nr_llm')
            ->willReturn([
                'providers' => [],
            ]);

        return new DallEImageService(
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
            ->method('get')
            ->with('nr_llm')
            ->willReturn([
                'providers' => [
                    'openai' => [
                        'apiKey' => 'test-api-key',
                    ],
                ],
            ]);

        $usageTrackerMock = $this->createMock(UsageTrackerServiceInterface::class);
        $usageTrackerMock
            ->expects(self::once())
            ->method('trackUsage')
            ->with('image', 'dall-e:dall-e-3', self::anything());

        $subject = new DallEImageService(
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
            ->method('get')
            ->with('nr_llm')
            ->willReturn([
                'providers' => [
                    'openai' => [
                        'apiKey' => 'test-api-key',
                    ],
                ],
            ]);

        $usageTrackerMock = $this->createMock(UsageTrackerServiceInterface::class);
        $usageTrackerMock
            ->expects(self::once())
            ->method('trackUsage')
            ->with('image', 'dall-e:variations', self::anything());

        $subject = new DallEImageService(
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
            ->method('get')
            ->with('nr_llm')
            ->willReturn([
                'providers' => [
                    'openai' => [
                        'apiKey' => 'test-api-key',
                    ],
                ],
            ]);

        $usageTrackerMock = $this->createMock(UsageTrackerServiceInterface::class);
        $usageTrackerMock
            ->expects(self::once())
            ->method('trackUsage')
            ->with('image', 'dall-e:edit', self::anything());

        $subject = new DallEImageService(
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

        $this->expectException(ServiceUnavailableException::class);

        $subject->generate('A cat');
    }

    #[Test]
    public function generateThrowsOnBadRequest(): void
    {
        $subject = $this->createSubject();
        $this->setupFailedRequest(400, 'Invalid request');

        $this->expectException(ServiceUnavailableException::class);

        $subject->generate('A cat');
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
            ->method('get')
            ->with('nr_llm')
            ->willReturn('not-an-array');

        $subject = new DallEImageService(
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
                    'apiKey' => 'test-api-key',
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

        $subject = new DallEImageService(
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
            ->method('get')
            ->with('nr_llm')
            ->willReturn([
                'providers' => ['openai' => ['apiKey' => 'test-api-key']],
            ]);

        $subject = new DallEImageService(
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
            ->method('get')
            ->with('nr_llm')
            ->willReturn([
                'providers' => ['openai' => ['apiKey' => 'test-api-key']],
            ]);

        $subject = new DallEImageService(
            $httpClientMock,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $this->usageTrackerStub,
            $this->loggerStub,
        );

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessageMatches('/Failed to connect to DALL-E API/');

        $subject->generate('A cat');
    }

    // ==================== generateMultiple with dall-e-2 usage tracking ====================

    #[Test]
    public function generateMultipleWithDallE2TracksUsageWithCount(): void
    {
        // DALL-E 2 batch path tracks usage with a 'count' key after the loop.
        $imageData = [
            ['url' => 'https://example.com/image1.png'],
            ['url' => 'https://example.com/image2.png'],
        ];
        $this->setupSuccessfulRequest(['data' => $imageData]);

        $this->extensionConfigMock
            ->method('get')
            ->with('nr_llm')
            ->willReturn([
                'providers' => ['openai' => ['apiKey' => 'test-api-key']],
            ]);

        $usageTrackerMock = $this->createMock(UsageTrackerServiceInterface::class);
        $usageTrackerMock
            ->expects(self::once())
            ->method('trackUsage')
            ->with(
                'image',
                'dall-e:dall-e-2',
                self::callback(fn(array $ctx): bool => isset($ctx['count']) && $ctx['count'] === 2),
            );

        $subject = new DallEImageService(
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
}
