<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Speech;

use Exception;
use Netresearch\NrLlm\Service\UsageTrackerService;
use Netresearch\NrLlm\Specialized\Exception\ServiceConfigurationException;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Exception\UnsupportedFormatException;
use Netresearch\NrLlm\Specialized\Option\TranscriptionOptions;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Whisper speech-to-text transcription service.
 *
 * Provides audio transcription via OpenAI's Whisper API.
 *
 * Features:
 * - Supports multiple audio formats (mp3, mp4, mpeg, mpga, m4a, wav, webm)
 * - Multiple output formats (json, text, srt, vtt, verbose_json)
 * - Language detection and specification
 * - Word-level timestamps (verbose mode)
 * - Prompt guidance for improved accuracy
 *
 * @see https://platform.openai.com/docs/guides/speech-to-text
 */
final class WhisperTranscriptionService
{
    private const string API_URL = 'https://api.openai.com/v1/audio';
    private const string DEFAULT_MODEL = 'whisper-1';
    private const MAX_FILE_SIZE = 25 * 1024 * 1024; // 25 MB

    /** Supported input audio formats. */
    private const array SUPPORTED_FORMATS = [
        'flac', 'mp3', 'mp4', 'mpeg', 'mpga', 'm4a', 'ogg', 'wav', 'webm',
    ];

    /** Supported output response formats. */
    private const array RESPONSE_FORMATS = [
        'json', 'text', 'srt', 'vtt', 'verbose_json',
    ];

    private string $apiKey = '';
    private string $baseUrl = '';
    
    private int $timeout = 120; // Transcription can take longer

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly UsageTrackerService $usageTracker,
        private readonly LoggerInterface $logger,
    ) {
        $this->loadConfiguration();
    }

    /**
     * Check if service is available.
     */
    public function isAvailable(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Transcribe audio file to text.
     *
     * @param string                                    $audioPath Path to audio file
     * @param TranscriptionOptions|array<string, mixed> $options   Transcription options
     *
     * @throws ServiceUnavailableException
     * @throws UnsupportedFormatException
     *
     * @return TranscriptionResult Transcription result
     */
    public function transcribe(
        string $audioPath,
        TranscriptionOptions|array $options = [],
    ): TranscriptionResult {
        $this->ensureAvailable();

        $options = $options instanceof TranscriptionOptions
            ? $options
            : TranscriptionOptions::fromArray($options);

        // Validate file
        $this->validateAudioFile($audioPath);

        // Build multipart request
        $response = $this->sendTranscriptionRequest($audioPath, $options);

        // Parse response based on format
        return $this->parseTranscriptionResponse($response, $options);
    }

    /**
     * Transcribe audio from binary content.
     *
     * @param string                                    $audioContent Binary audio content
     * @param string                                    $filename     Original filename (for format detection)
     * @param TranscriptionOptions|array<string, mixed> $options      Transcription options
     *
     * @return TranscriptionResult Transcription result
     */
    public function transcribeFromContent(
        string $audioContent,
        string $filename,
        TranscriptionOptions|array $options = [],
    ): TranscriptionResult {
        $this->ensureAvailable();

        $options = $options instanceof TranscriptionOptions
            ? $options
            : TranscriptionOptions::fromArray($options);

        // Validate content size
        if (strlen($audioContent) > self::MAX_FILE_SIZE) {
            throw new UnsupportedFormatException(
                sprintf('Audio content exceeds maximum size of %d MB', self::MAX_FILE_SIZE / 1024 / 1024),
                'speech',
            );
        }

        // Validate format from filename
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, self::SUPPORTED_FORMATS, true)) {
            throw UnsupportedFormatException::audioFormat($extension);
        }

        // Build multipart request with content
        $response = $this->sendTranscriptionRequestFromContent($audioContent, $filename, $options);

        return $this->parseTranscriptionResponse($response, $options);
    }

    /**
     * Translate audio to English text.
     *
     * Uses OpenAI's translation endpoint to transcribe and translate
     * non-English audio directly to English text.
     *
     * @param string                                    $audioPath Path to audio file
     * @param TranscriptionOptions|array<string, mixed> $options   Transcription options
     *
     * @return TranscriptionResult Transcription result (always in English)
     */
    public function translateToEnglish(
        string $audioPath,
        TranscriptionOptions|array $options = [],
    ): TranscriptionResult {
        $this->ensureAvailable();

        $options = $options instanceof TranscriptionOptions
            ? $options
            : TranscriptionOptions::fromArray($options);

        $this->validateAudioFile($audioPath);

        $response = $this->sendTranslationRequest($audioPath, $options);

        $result = $this->parseTranscriptionResponse($response, $options);

        // Track usage
        $this->usageTracker->trackUsage('speech', 'whisper:translation', [
            'file_size' => filesize($audioPath),
        ]);

        return $result;
    }

    /**
     * Get supported audio formats.
     *
     * @return array<int, string>
     */
    public function getSupportedFormats(): array
    {
        return self::SUPPORTED_FORMATS;
    }

    /**
     * Get supported response formats.
     *
     * @return array<int, string>
     */
    public function getSupportedResponseFormats(): array
    {
        return self::RESPONSE_FORMATS;
    }

    /**
     * Load configuration from extension settings.
     */
    private function loadConfiguration(): void
    {
        try {
            $config = $this->extensionConfiguration->get('nr_llm');
            if (!is_array($config)) {
                return;
            }

            // Use OpenAI API key for Whisper
            /** @var array{providers?: array{openai?: array{apiKey?: string}}, speech?: array{whisper?: array{baseUrl?: string, timeout?: int}}} $config */
            $this->apiKey = (string)($config['providers']['openai']['apiKey'] ?? '');
            $this->baseUrl = (string)($config['speech']['whisper']['baseUrl'] ?? self::API_URL);
            $this->timeout = (int)($config['speech']['whisper']['timeout'] ?? 120);
        } catch (Exception $e) {
            $this->logger->warning('Failed to load Whisper configuration', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Ensure service is available.
     *
     * @throws ServiceUnavailableException
     */
    private function ensureAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw ServiceUnavailableException::notConfigured('speech', 'whisper');
        }
    }

    /**
     * Validate audio file.
     *
     * @throws UnsupportedFormatException
     */
    private function validateAudioFile(string $path): void
    {
        if (!file_exists($path)) {
            throw new UnsupportedFormatException(
                sprintf('Audio file not found: %s', $path),
                'speech',
            );
        }

        $fileSize = filesize($path);
        if ($fileSize === false || $fileSize > self::MAX_FILE_SIZE) {
            throw new UnsupportedFormatException(
                sprintf('Audio file exceeds maximum size of %d MB', self::MAX_FILE_SIZE / 1024 / 1024),
                'speech',
            );
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, self::SUPPORTED_FORMATS, true)) {
            throw UnsupportedFormatException::audioFormat($extension);
        }
    }

    /**
     * Send transcription request.
     *
     * @return array<string, mixed>|string Response data
     */
    private function sendTranscriptionRequest(
        string $audioPath,
        TranscriptionOptions $options,
    ): array|string {
        $url = rtrim($this->baseUrl, '/') . '/transcriptions';

        $boundary = 'whisper' . uniqid();
        $body = $this->buildMultipartBody($audioPath, $options, $boundary);

        return $this->sendMultipartRequest($url, $body, $boundary, $options);
    }

    /**
     * Send transcription request from content.
     *
     * @return array<string, mixed>|string Response data
     */
    private function sendTranscriptionRequestFromContent(
        string $audioContent,
        string $filename,
        TranscriptionOptions $options,
    ): array|string {
        $url = rtrim($this->baseUrl, '/') . '/transcriptions';

        $boundary = 'whisper' . uniqid();
        $body = $this->buildMultipartBodyFromContent($audioContent, $filename, $options, $boundary);

        return $this->sendMultipartRequest($url, $body, $boundary, $options);
    }

    /**
     * Send translation request.
     *
     * @return array<string, mixed>|string Response data
     */
    private function sendTranslationRequest(
        string $audioPath,
        TranscriptionOptions $options,
    ): array|string {
        $url = rtrim($this->baseUrl, '/') . '/translations';

        $boundary = 'whisper' . uniqid();
        $body = $this->buildMultipartBody($audioPath, $options, $boundary);

        return $this->sendMultipartRequest($url, $body, $boundary, $options);
    }

    /**
     * Build multipart form body from file.
     */
    private function buildMultipartBody(
        string $audioPath,
        TranscriptionOptions $options,
        string $boundary,
    ): string {
        $body = '';

        // Add file
        $filename = basename($audioPath);
        $content = file_get_contents($audioPath);
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
        $body .= "Content-Type: application/octet-stream\r\n\r\n";
        $body .= $content . "\r\n";

        // Add model
        $model = $options->model ?? self::DEFAULT_MODEL;
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"model\"\r\n\r\n";
        $body .= $model . "\r\n";

        // Add optional parameters
        if ($options->language !== null) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"language\"\r\n\r\n";
            $body .= $options->language . "\r\n";
        }

        if ($options->format !== null) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"response_format\"\r\n\r\n";
            $body .= $options->format . "\r\n";
        }

        if ($options->prompt !== null) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"prompt\"\r\n\r\n";
            $body .= $options->prompt . "\r\n";
        }

        if ($options->temperature !== null) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"temperature\"\r\n\r\n";
            $body .= (string)$options->temperature . "\r\n";
        }

        $body .= "--{$boundary}--\r\n";

        return $body;
    }

    /**
     * Build multipart form body from content.
     */
    private function buildMultipartBodyFromContent(
        string $audioContent,
        string $filename,
        TranscriptionOptions $options,
        string $boundary,
    ): string {
        $body = '';

        // Add file content
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
        $body .= "Content-Type: application/octet-stream\r\n\r\n";
        $body .= $audioContent . "\r\n";

        // Add model
        $model = $options->model ?? self::DEFAULT_MODEL;
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"model\"\r\n\r\n";
        $body .= $model . "\r\n";

        // Add optional parameters (same as file version)
        if ($options->language !== null) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"language\"\r\n\r\n";
            $body .= $options->language . "\r\n";
        }

        if ($options->format !== null) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"response_format\"\r\n\r\n";
            $body .= $options->format . "\r\n";
        }

        if ($options->prompt !== null) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"prompt\"\r\n\r\n";
            $body .= $options->prompt . "\r\n";
        }

        if ($options->temperature !== null) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"temperature\"\r\n\r\n";
            $body .= (string)$options->temperature . "\r\n";
        }

        $body .= "--{$boundary}--\r\n";

        return $body;
    }

    /**
     * Send multipart request.
     *
     * @throws ServiceUnavailableException
     *
     * @return array<string, mixed>|string Response data
     */
    private function sendMultipartRequest(
        string $url,
        string $body,
        string $boundary,
        TranscriptionOptions $options,
    ): array|string {
        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Authorization', 'Bearer ' . $this->apiKey)
            ->withHeader('Content-Type', 'multipart/form-data; boundary=' . $boundary);

        $request = $request->withBody($this->streamFactory->createStream($body));

        try {
            $response = $this->httpClient->sendRequest($request);
            $statusCode = $response->getStatusCode();
            $responseBody = (string)$response->getBody();

            if ($statusCode >= 200 && $statusCode < 300) {
                // Track usage for successful requests
                $this->usageTracker->trackUsage('speech', 'whisper:transcription', []);

                // Return raw text for text/srt/vtt formats
                $format = $options->format ?? 'json';
                if (in_array($format, ['text', 'srt', 'vtt'], true)) {
                    return $responseBody;
                }

                /** @var array<string, mixed> */
                return json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
            }

            /** @var array{error?: array{message?: string}} $error */
            $error = json_decode($responseBody, true) ?? [];
            $errorMessage = is_string($error['error']['message'] ?? null)
                ? $error['error']['message']
                : 'Unknown Whisper API error';

            $this->logger->error('Whisper API error', [
                'status_code' => $statusCode,
                'error' => $errorMessage,
            ]);

            throw match ($statusCode) {
                401, 403 => ServiceConfigurationException::invalidApiKey('speech', 'whisper'),
                429 => new ServiceUnavailableException('Whisper API rate limit exceeded', 'speech', ['provider' => 'whisper']),
                default => new ServiceUnavailableException('Whisper API error: ' . $errorMessage, 'speech', ['provider' => 'whisper']),
            };
        } catch (ServiceUnavailableException|ServiceConfigurationException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->logger->error('Whisper API connection error', [
                'exception' => $e->getMessage(),
            ]);

            throw new ServiceUnavailableException(
                'Failed to connect to Whisper API: ' . $e->getMessage(),
                'speech',
                ['provider' => 'whisper'],
                0,
                $e,
            );
        }
    }

    /**
     * Parse transcription response.
     *
     * @param array<string, mixed>|string $response Response data
     */
    private function parseTranscriptionResponse(
        array|string $response,
        TranscriptionOptions $options,
    ): TranscriptionResult {
        $format = $options->format ?? 'json';

        // Handle text formats
        if (is_string($response)) {
            return new TranscriptionResult(
                text: $response,
                language: $options->language ?? 'en',
                metadata: ['format' => $format],
            );
        }

        // Handle JSON formats
        $text = is_string($response['text'] ?? null) ? $response['text'] : '';
        $language = is_string($response['language'] ?? null)
            ? $response['language']
            : ($options->language ?? 'en');
        $duration = isset($response['duration']) && is_numeric($response['duration'])
            ? (float)$response['duration']
            : null;

        // Parse segments for verbose_json
        /** @var array<int, Segment>|null $segments */
        $segments = null;
        if ($format === 'verbose_json' && isset($response['segments']) && is_array($response['segments'])) {
            /** @var array<int, array<string, mixed>> $responseSegments */
            $responseSegments = $response['segments'];
            $segments = array_map(
                Segment::fromWhisperResponse(...),
                $responseSegments,
            );
        }

        return new TranscriptionResult(
            text: $text,
            language: $language,
            duration: $duration,
            segments: $segments,
            metadata: [
                'format' => $format,
                'task' => is_string($response['task'] ?? null) ? $response['task'] : 'transcribe',
            ],
        );
    }
}
