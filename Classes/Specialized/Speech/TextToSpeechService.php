<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Speech;

use Exception;
use Netresearch\NrLlm\Service\UsageTrackerService;
use Netresearch\NrLlm\Specialized\Exception\ServiceConfigurationException;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Option\SpeechSynthesisOptions;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Text-to-speech synthesis service using OpenAI TTS.
 *
 * Provides high-quality speech synthesis with multiple voices and models.
 *
 * Features:
 * - Multiple voices (alloy, echo, fable, onyx, nova, shimmer)
 * - HD model option for higher quality
 * - Adjustable speech speed (0.25x to 4.0x)
 * - Multiple output formats (mp3, opus, aac, flac, wav, pcm)
 * - Maximum input of 4096 characters per request
 *
 * @see https://platform.openai.com/docs/guides/text-to-speech
 */
final class TextToSpeechService
{
    private const API_URL = 'https://api.openai.com/v1/audio/speech';
    private const DEFAULT_MODEL = 'tts-1';
    private const DEFAULT_VOICE = 'alloy';
    private const MAX_INPUT_LENGTH = 4096;

    /** Available voices with their characteristics. */
    private const VOICES = [
        'alloy' => 'Neutral and balanced',
        'echo' => 'Warm and conversational',
        'fable' => 'British-accented, expressive',
        'onyx' => 'Deep and authoritative',
        'nova' => 'Youthful and bright',
        'shimmer' => 'Soft and pleasant',
    ];

    /** Available models. */
    private const MODELS = [
        'tts-1' => 'Standard quality, low latency',
        'tts-1-hd' => 'High definition quality',
    ];

    /** Supported output formats. */
    private const FORMATS = ['mp3', 'opus', 'aac', 'flac', 'wav', 'pcm'];

    private string $apiKey = '';
    private string $baseUrl = '';
    /** @phpstan-ignore property.onlyWritten (intended for future HTTP client configuration) */
    private int $timeout = 60;

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
     * Synthesize speech from text.
     *
     * @param string                                      $text    Text to convert to speech (max 4096 chars)
     * @param SpeechSynthesisOptions|array<string, mixed> $options Synthesis options
     *
     * @throws ServiceUnavailableException
     *
     * @return SpeechSynthesisResult Speech synthesis result
     */
    public function synthesize(
        string $text,
        SpeechSynthesisOptions|array $options = [],
    ): SpeechSynthesisResult {
        $this->ensureAvailable();

        $options = $options instanceof SpeechSynthesisOptions
            ? $options
            : SpeechSynthesisOptions::fromArray($options);

        // Validate input
        $this->validateInput($text);

        // Build request
        $optionsArray = $options->toArray();
        $model = $optionsArray['model'] ?? self::DEFAULT_MODEL;
        $voice = $optionsArray['voice'] ?? self::DEFAULT_VOICE;
        $format = $optionsArray['response_format'] ?? 'mp3';
        $speed = $optionsArray['speed'] ?? 1.0;

        $payload = [
            'model' => $model,
            'input' => $text,
            'voice' => $voice,
            'response_format' => $format,
            'speed' => $speed,
        ];

        // Send request
        $audioContent = $this->sendRequest($payload);

        // Track usage
        $this->usageTracker->trackUsage('speech', 'tts:' . $model, [
            'characters' => mb_strlen($text),
            'voice' => $voice,
        ]);

        return new SpeechSynthesisResult(
            audioContent: $audioContent,
            format: $format,
            model: $model,
            voice: $voice,
            characterCount: mb_strlen($text),
            metadata: [
                'speed' => $speed,
            ],
        );
    }

    /**
     * Synthesize speech and save to file.
     *
     * @param string                                      $text       Text to convert to speech
     * @param string                                      $outputPath Path to save audio file
     * @param SpeechSynthesisOptions|array<string, mixed> $options    Synthesis options
     *
     * @return SpeechSynthesisResult Speech synthesis result
     */
    public function synthesizeToFile(
        string $text,
        string $outputPath,
        SpeechSynthesisOptions|array $options = [],
    ): SpeechSynthesisResult {
        $result = $this->synthesize($text, $options);

        if (!$result->saveToFile($outputPath)) {
            throw new ServiceUnavailableException(
                sprintf('Failed to save audio to file: %s', $outputPath),
                'speech',
                ['provider' => 'tts'],
            );
        }

        return $result;
    }

    /**
     * Synthesize long text by splitting into chunks.
     *
     * For texts longer than 4096 characters, splits at sentence boundaries
     * and synthesizes each chunk separately.
     *
     * @param string                                      $text    Long text to convert
     * @param SpeechSynthesisOptions|array<string, mixed> $options Synthesis options
     *
     * @return array<int, SpeechSynthesisResult> Array of results for each chunk
     */
    public function synthesizeLong(
        string $text,
        SpeechSynthesisOptions|array $options = [],
    ): array {
        $this->ensureAvailable();

        $options = $options instanceof SpeechSynthesisOptions
            ? $options
            : SpeechSynthesisOptions::fromArray($options);

        // If text fits in single request, use normal synthesis
        if (mb_strlen($text) <= self::MAX_INPUT_LENGTH) {
            return [$this->synthesize($text, $options)];
        }

        // Split into chunks at sentence boundaries
        $chunks = $this->splitTextIntoChunks($text);
        $results = [];

        foreach ($chunks as $chunk) {
            $results[] = $this->synthesize($chunk, $options);
        }

        return $results;
    }

    /**
     * Get available voices.
     *
     * @return array<string, string> Voice ID => description
     */
    public function getAvailableVoices(): array
    {
        return self::VOICES;
    }

    /**
     * Get available models.
     *
     * @return array<string, string> Model ID => description
     */
    public function getAvailableModels(): array
    {
        return self::MODELS;
    }

    /**
     * Get supported output formats.
     *
     * @return array<int, string>
     */
    public function getSupportedFormats(): array
    {
        return self::FORMATS;
    }

    /**
     * Get maximum input length.
     */
    public function getMaxInputLength(): int
    {
        return self::MAX_INPUT_LENGTH;
    }

    /**
     * Load configuration from extension settings.
     */
    private function loadConfiguration(): void
    {
        try {
            $config = $this->extensionConfiguration->get('nr_llm');

            // Use OpenAI API key for TTS
            $this->apiKey = (string)($config['providers']['openai']['apiKey'] ?? '');
            $this->baseUrl = (string)($config['speech']['tts']['baseUrl'] ?? self::API_URL);
            $this->timeout = (int)($config['speech']['tts']['timeout'] ?? 60);
        } catch (Exception $e) {
            $this->logger->warning('Failed to load TTS configuration', [
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
            throw ServiceUnavailableException::notConfigured('speech', 'tts');
        }
    }

    /**
     * Validate input text.
     *
     * @throws ServiceUnavailableException
     */
    private function validateInput(string $text): void
    {
        if (empty(trim($text))) {
            throw new ServiceUnavailableException(
                'Input text cannot be empty',
                'speech',
                ['provider' => 'tts'],
            );
        }

        if (mb_strlen($text) > self::MAX_INPUT_LENGTH) {
            throw new ServiceUnavailableException(
                sprintf(
                    'Input text exceeds maximum length of %d characters. Use synthesizeLong() for longer texts.',
                    self::MAX_INPUT_LENGTH,
                ),
                'speech',
                ['provider' => 'tts', 'length' => mb_strlen($text)],
            );
        }
    }

    /**
     * Split text into chunks at sentence boundaries.
     *
     * @return array<int, string>
     */
    private function splitTextIntoChunks(string $text): array
    {
        $chunks = [];
        $currentChunk = '';

        // Split into sentences (simple approach)
        $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        if ($sentences === false) {
            // Fallback: split by length
            return str_split($text, self::MAX_INPUT_LENGTH) ?: [$text];
        }

        foreach ($sentences as $sentence) {
            // If single sentence is too long, split it
            if (mb_strlen($sentence) > self::MAX_INPUT_LENGTH) {
                if ($currentChunk !== '') {
                    $chunks[] = $currentChunk;
                    $currentChunk = '';
                }
                // Split long sentence by commas or force-split
                $subChunks = $this->splitLongSentence($sentence);
                foreach ($subChunks as $subChunk) {
                    $chunks[] = $subChunk;
                }
                continue;
            }

            // Check if adding this sentence exceeds limit
            $testChunk = $currentChunk === '' ? $sentence : $currentChunk . ' ' . $sentence;

            if (mb_strlen($testChunk) <= self::MAX_INPUT_LENGTH) {
                $currentChunk = $testChunk;
            } else {
                // Save current chunk and start new one
                if ($currentChunk !== '') {
                    $chunks[] = $currentChunk;
                }
                $currentChunk = $sentence;
            }
        }

        // Add remaining chunk
        if ($currentChunk !== '') {
            $chunks[] = $currentChunk;
        }

        return $chunks;
    }

    /**
     * Split a long sentence into smaller chunks.
     *
     * @return array<int, string>
     */
    private function splitLongSentence(string $sentence): array
    {
        // Try to split at commas first
        $parts = explode(', ', $sentence);

        if (count($parts) > 1) {
            $chunks = [];
            $currentChunk = '';

            foreach ($parts as $i => $part) {
                $separator = $i < count($parts) - 1 ? ', ' : '';
                $testChunk = $currentChunk === '' ? $part . $separator : $currentChunk . $part . $separator;

                if (mb_strlen($testChunk) <= self::MAX_INPUT_LENGTH) {
                    $currentChunk = $testChunk;
                } else {
                    if ($currentChunk !== '') {
                        $chunks[] = rtrim($currentChunk, ', ');
                    }
                    $currentChunk = $part . $separator;
                }
            }

            if ($currentChunk !== '') {
                $chunks[] = rtrim($currentChunk, ', ');
            }

            return $chunks;
        }

        // Force-split if no natural break points
        return str_split($sentence, self::MAX_INPUT_LENGTH) ?: [$sentence];
    }

    /**
     * Send synthesis request.
     *
     * @param array<string, mixed> $payload Request payload
     *
     * @throws ServiceUnavailableException
     *
     * @return string Binary audio content
     */
    private function sendRequest(array $payload): string
    {
        $request = $this->requestFactory->createRequest('POST', $this->baseUrl)
            ->withHeader('Authorization', 'Bearer ' . $this->apiKey)
            ->withHeader('Content-Type', 'application/json');

        $body = $this->streamFactory->createStream(json_encode($payload, JSON_THROW_ON_ERROR));
        $request = $request->withBody($body);

        try {
            $response = $this->httpClient->sendRequest($request);
            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                return (string)$response->getBody();
            }

            $responseBody = (string)$response->getBody();
            $error = json_decode($responseBody, true) ?? [];
            $errorMessage = $error['error']['message'] ?? 'Unknown TTS API error';

            $this->logger->error('TTS API error', [
                'status_code' => $statusCode,
                'error' => $errorMessage,
            ]);

            throw match ($statusCode) {
                401, 403 => ServiceConfigurationException::invalidApiKey('speech', 'tts'),
                429 => new ServiceUnavailableException('TTS API rate limit exceeded', 'speech', ['provider' => 'tts']),
                default => new ServiceUnavailableException('TTS API error: ' . $errorMessage, 'speech', ['provider' => 'tts']),
            };
        } catch (ServiceUnavailableException|ServiceConfigurationException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->logger->error('TTS API connection error', [
                'exception' => $e->getMessage(),
            ]);

            throw new ServiceUnavailableException(
                'Failed to connect to TTS API: ' . $e->getMessage(),
                'speech',
                ['provider' => 'tts'],
                0,
                $e,
            );
        }
    }
}
