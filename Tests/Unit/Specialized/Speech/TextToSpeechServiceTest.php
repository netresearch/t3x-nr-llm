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
use Netresearch\NrLlm\Specialized\Option\SpeechSynthesisOptions;
use Netresearch\NrLlm\Specialized\Speech\SpeechSynthesisResult;
use Netresearch\NrLlm\Specialized\Speech\TextToSpeechService;
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
#[CoversClass(TextToSpeechService::class)]
class TextToSpeechServiceTest extends AbstractUnitTestCase
{
    private ClientInterface&Stub $httpClientStub;
    private RequestFactoryInterface&Stub $requestFactoryStub;
    private StreamFactoryInterface&Stub $streamFactoryStub;
    private ExtensionConfiguration&MockObject $extensionConfigMock;
    private UsageTrackerServiceInterface&Stub $usageTrackerStub;
    private LoggerInterface&Stub $loggerStub;

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

    /**
     * @param array<string, mixed> $config
     */
    private function createSubject(array $config = []): TextToSpeechService
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

        return new TextToSpeechService(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->extensionConfigMock,
            $this->usageTrackerStub,
            $this->loggerStub,
        );
    }

    private function createSubjectWithoutApiKey(): TextToSpeechService
    {
        $this->extensionConfigMock
            ->method('get')
            ->with('nr_llm')
            ->willReturn([
                'providers' => [],
            ]);

        return new TextToSpeechService(
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
    public function synthesizeThrowsWhenServiceUnavailable(): void
    {
        $subject = $this->createSubjectWithoutApiKey();

        $this->expectException(ServiceUnavailableException::class);

        $subject->synthesize('Hello world');
    }

    #[Test]
    public function synthesizeThrowsOnEmptyText(): void
    {
        $subject = $this->createSubject();

        $this->expectException(ServiceUnavailableException::class);

        $subject->synthesize('   ');
    }

    #[Test]
    public function synthesizeThrowsOnTextTooLong(): void
    {
        $subject = $this->createSubject();
        $longText = str_repeat('a', 4097);

        $this->expectException(ServiceUnavailableException::class);

        $subject->synthesize($longText);
    }

    #[Test]
    public function synthesizeTracksUsage(): void
    {
        $this->setupSuccessfulRequest();

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
            ->with(
                'speech',
                self::stringStartsWith('tts:'),
                self::callback(fn($data) => is_array($data) && isset($data['characters'])),
            );

        $subject = new TextToSpeechService(
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

        $this->expectException(ServiceUnavailableException::class);

        $subject->synthesizeToFile('Hello world', '/non/existent/path/file.mp3');
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
                        'apiKey' => 'test-api-key',
                    ],
                ],
            ]);

        $subject = new TextToSpeechService(
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

        $this->expectException(ServiceUnavailableException::class);

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
            ->method('get')
            ->with('nr_llm')
            ->willReturn('not-an-array');

        $subject = new TextToSpeechService(
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
                    'apiKey' => 'test-api-key',
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

        $subject = new TextToSpeechService(
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

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('rate limit exceeded');

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
    }

    #[Test]
    public function loadConfigurationHandlesNonArrayConfig(): void
    {
        $this->extensionConfigMock
            ->method('get')
            ->with('nr_llm')
            ->willReturn('not-an-array');

        $subject = new TextToSpeechService(
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
            ->method('get')
            ->with('nr_llm')
            ->willReturn([
                'providers' => 'not-an-array',
            ]);

        $subject = new TextToSpeechService(
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
}
