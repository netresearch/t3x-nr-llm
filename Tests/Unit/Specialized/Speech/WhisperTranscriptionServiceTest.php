<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Speech;

use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Specialized\Exception\ServiceConfigurationException;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Exception\UnsupportedFormatException;
use Netresearch\NrLlm\Specialized\Option\TranscriptionOptions;
use Netresearch\NrLlm\Specialized\Speech\TranscriptionResult;
use Netresearch\NrLlm\Specialized\Speech\WhisperTranscriptionService;
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
#[CoversClass(WhisperTranscriptionService::class)]
class WhisperTranscriptionServiceTest extends AbstractUnitTestCase
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
    private function createSubject(array $config = []): WhisperTranscriptionService
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

        return new WhisperTranscriptionService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $this->usageTrackerStub,
            $this->loggerStub,
        );
    }

    private function createSubjectWithoutApiKey(): WhisperTranscriptionService
    {
        $this->extensionConfigMock
            ->method('get')
            ->with('nr_llm')
            ->willReturn([
                'providers' => [],
            ]);

        return new WhisperTranscriptionService(
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

        $this->expectException(ServiceUnavailableException::class);

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
            ->with('speech', 'whisper:transcription', []);

        $subject = new WhisperTranscriptionService(
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
            ->expects(self::atLeastOnce())
            ->method('trackUsage');

        $subject = new WhisperTranscriptionService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $usageTrackerMock,
            $this->loggerStub,
        );

        $subject->translateToEnglish($audioFile);
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

        $this->expectException(ServiceUnavailableException::class);

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
            ->method('get')
            ->with('nr_llm')
            ->willReturn('not-an-array');

        $subject = new WhisperTranscriptionService(
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

        $subject = new WhisperTranscriptionService(
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
            ->method('get')
            ->with('nr_llm')
            ->willReturn([
                'providers' => [
                    'openai' => [
                        'apiKey' => 'test-api-key',
                    ],
                ],
            ]);

        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock
            ->expects(self::once())
            ->method('error');

        $subject = new WhisperTranscriptionService(
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
}
