<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Speech;

use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Specialized\Exception\ServiceConfigurationException;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Option\SpeechSynthesisOptions;
use Netresearch\NrLlm\Specialized\Speech\SpeechSynthesisResult;
use Netresearch\NrLlm\Specialized\Speech\TextToSpeechService;
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

#[CoversClass(TextToSpeechService::class)]
class TextToSpeechServiceTest extends AbstractUnitTestCase
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
            $this->httpClientMock,
            $this->requestFactoryMock,
            $this->streamFactoryMock,
            $this->extensionConfigMock,
            $this->usageTrackerMock,
            $this->loggerMock,
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
            $this->httpClientMock,
            $this->requestFactoryMock,
            $this->streamFactoryMock,
            $this->extensionConfigMock,
            $this->usageTrackerMock,
            $this->loggerMock,
        );
    }

    private function setupSuccessfulRequest(string $audioContent = 'audio-binary-content'): void
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
        $responseBodyMock->method('__toString')->willReturn($audioContent);

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('getBody')->willReturn($responseBodyMock);

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($responseMock);
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
            'error' => ['message' => $errorMessage],
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
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest();

        $this->usageTrackerMock
            ->expects(self::once())
            ->method('trackUsage')
            ->with(
                'speech',
                self::stringStartsWith('tts:'),
                self::callback(fn($data) => isset($data['characters'])),
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

        self::assertIsArray($result);
        self::assertCount(1, $result);
        self::assertInstanceOf(SpeechSynthesisResult::class, $result[0]);
    }

    #[Test]
    public function synthesizeLongSplitsLongText(): void
    {
        $subject = $this->createSubject();
        $this->setupSuccessfulRequest();

        // Create text longer than 4096 characters
        $longText = str_repeat('Hello world. ', 400); // ~5200 chars

        // Reset mock for multiple calls
        $this->httpClientMock = $this->createMock(ClientInterface::class);

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
        $responseBodyMock->method('__toString')->willReturn('audio-chunk');

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('getBody')->willReturn($responseBodyMock);

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($responseMock);

        // Need to recreate subject with new mock
        $this->extensionConfigMock = $this->createMock(ExtensionConfiguration::class);
        $this->extensionConfigMock
            ->method('get')
            ->willReturn([
                'providers' => [
                    'openai' => [
                        'apiKey' => 'test-api-key',
                    ],
                ],
            ]);

        $subject = new TextToSpeechService(
            $this->httpClientMock,
            $this->requestFactoryMock,
            $this->streamFactoryMock,
            $this->extensionConfigMock,
            $this->usageTrackerMock,
            $this->loggerMock,
        );

        $result = $subject->synthesizeLong($longText);

        self::assertIsArray($result);
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
        $this->extensionConfigMock
            ->method('get')
            ->willThrowException(new RuntimeException('Config error'));

        $this->loggerMock
            ->expects(self::once())
            ->method('warning');

        $subject = new TextToSpeechService(
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
        self::assertContainsOnlyInstancesOf(SpeechSynthesisResult::class, $result);
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

        $requestMock = $this->createMock(RequestInterface::class);
        $requestMock->method('withHeader')->willReturnSelf();
        $requestMock->method('withBody')->willReturnSelf();

        $this->requestFactoryMock
            ->method('createRequest')
            ->willReturn($requestMock);

        $bodyMock = $this->createMock(StreamInterface::class);
        $bodyMock->method('__toString')->willReturn('{}');

        $this->streamFactoryMock
            ->method('createStream')
            ->willReturn($bodyMock);

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(429);
        $responseMock->method('getBody')->willReturn($bodyMock);

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($responseMock);

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('rate limit exceeded');

        $subject->synthesize('Test text');
    }

    #[Test]
    public function sendRequestHandlesGenericApiError(): void
    {
        $subject = $this->createSubject();

        $requestMock = $this->createMock(RequestInterface::class);
        $requestMock->method('withHeader')->willReturnSelf();
        $requestMock->method('withBody')->willReturnSelf();

        $this->requestFactoryMock
            ->method('createRequest')
            ->willReturn($requestMock);

        $bodyMock = $this->createMock(StreamInterface::class);
        $bodyMock->method('__toString')->willReturn('{"error": {"message": "Server error"}}');

        $this->streamFactoryMock
            ->method('createStream')
            ->willReturn($bodyMock);

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(500);
        $responseMock->method('getBody')->willReturn($bodyMock);

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($responseMock);

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
    public function loadConfigurationHandlesNonArrayProviders(): void
    {
        $this->extensionConfigMock
            ->method('get')
            ->with('nr_llm')
            ->willReturn([
                'providers' => 'not-an-array',
            ]);

        $subject = new TextToSpeechService(
            $this->httpClientMock,
            $this->requestFactoryMock,
            $this->streamFactoryMock,
            $this->extensionConfigMock,
            $this->usageTrackerMock,
            $this->loggerMock,
        );

        self::assertFalse($subject->isAvailable());
    }
}
