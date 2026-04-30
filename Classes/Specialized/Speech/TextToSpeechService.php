<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Speech;

use Netresearch\NrLlm\Specialized\AbstractSpecializedService;
use Netresearch\NrLlm\Specialized\Exception\ServiceConfigurationException;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Option\SpeechSynthesisOptions;
use Throwable;

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
 * Owns its own request execution because TTS returns raw binary
 * audio bytes — the base's `executeRequest()` always JSON-decodes.
 * Everything else (config loading, availability, auth headers,
 * endpoint construction) comes from `AbstractSpecializedService`.
 *
 * @see https://platform.openai.com/docs/guides/text-to-speech
 */
final class TextToSpeechService extends AbstractSpecializedService
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

        $this->validateInput($text);

        $optionsArray = $options->toArray();
        $modelValue = $optionsArray['model'] ?? null;
        $model = is_string($modelValue) ? $modelValue : self::DEFAULT_MODEL;
        $voiceValue = $optionsArray['voice'] ?? null;
        $voice = is_string($voiceValue) ? $voiceValue : self::DEFAULT_VOICE;
        $formatValue = $optionsArray['response_format'] ?? null;
        $format = is_string($formatValue) ? $formatValue : 'mp3';
        $speedValue = $optionsArray['speed'] ?? null;
        $speed = is_float($speedValue) || is_int($speedValue) ? (float)$speedValue : 1.0;

        $payload = [
            'model' => $model,
            'input' => $text,
            'voice' => $voice,
            'response_format' => $format,
            'speed' => $speed,
        ];

        $audioContent = $this->sendBinaryRequest($payload);

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

        if (mb_strlen($text) <= self::MAX_INPUT_LENGTH) {
            return [$this->synthesize($text, $options)];
        }

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

    protected function getServiceDomain(): string
    {
        return 'speech';
    }

    protected function getServiceProvider(): string
    {
        return 'tts';
    }

    protected function getDefaultBaseUrl(): string
    {
        return self::API_URL;
    }

    protected function getDefaultTimeout(): int
    {
        return 60;
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function loadServiceConfiguration(array $config): void
    {
        $providers = $config['providers'] ?? null;
        if (is_array($providers)) {
            $openai = $providers['openai'] ?? null;
            if (is_array($openai)) {
                $apiKey = $openai['apiKey'] ?? '';
                $this->apiKey = is_string($apiKey) ? $apiKey : '';
            }
        }

        $this->baseUrl = self::API_URL;
        $speech = $config['speech'] ?? null;
        if (is_array($speech)) {
            $tts = $speech['tts'] ?? null;
            if (is_array($tts)) {
                $baseUrl = $tts['baseUrl'] ?? null;
                $this->baseUrl = is_string($baseUrl) ? $baseUrl : self::API_URL;
                $timeout = $tts['timeout'] ?? null;
                $this->timeout = is_numeric($timeout) ? (int)$timeout : $this->getDefaultTimeout();
            }
        }
    }

    protected function buildAuthHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->apiKey];
    }

    protected function getProviderLabel(): string
    {
        return 'TTS';
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

        $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        if ($sentences === false) {
            return str_split($text, self::MAX_INPUT_LENGTH) ?: [$text];
        }

        foreach ($sentences as $sentence) {
            if (mb_strlen($sentence) > self::MAX_INPUT_LENGTH) {
                if ($currentChunk !== '') {
                    $chunks[] = $currentChunk;
                    $currentChunk = '';
                }
                $subChunks = $this->splitLongSentence($sentence);
                foreach ($subChunks as $subChunk) {
                    $chunks[] = $subChunk;
                }
                continue;
            }

            $testChunk = $currentChunk === '' ? $sentence : $currentChunk . ' ' . $sentence;

            if (mb_strlen($testChunk) <= self::MAX_INPUT_LENGTH) {
                $currentChunk = $testChunk;
            } else {
                if ($currentChunk !== '') {
                    $chunks[] = $currentChunk;
                }
                $currentChunk = $sentence;
            }
        }

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

        return str_split($sentence, self::MAX_INPUT_LENGTH) ?: [$sentence];
    }

    /**
     * Send synthesis request and return the binary audio body.
     * Cannot use the base's `sendJsonRequest()` because that one
     * JSON-decodes the response — TTS gives us raw bytes.
     *
     * @param array<string, mixed> $payload
     *
     * @throws ServiceUnavailableException
     */
    private function sendBinaryRequest(array $payload): string
    {
        $request = $this->requestFactory->createRequest('POST', $this->buildEndpointUrl(''))
            ->withHeader('Content-Type', 'application/json');
        foreach ($this->buildAuthHeaders() as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        $request = $request->withBody(
            $this->streamFactory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)),
        );

        try {
            $response = $this->httpClient->sendRequest($request);
            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                return (string)$response->getBody();
            }

            $errorMessage = $this->decodeErrorMessage((string)$response->getBody());

            $this->logger->error('TTS API error', [
                'status_code' => $statusCode,
                'error'       => $errorMessage,
            ]);

            throw $this->mapErrorStatus($statusCode, $errorMessage);
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
