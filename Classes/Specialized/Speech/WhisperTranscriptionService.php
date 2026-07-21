<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Speech;

use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Provider\Middleware\Usage\SpecializedUsageIntent;
use Netresearch\NrLlm\Specialized\AbstractSpecializedService;
use Netresearch\NrLlm\Specialized\Exception\ServiceConfigurationException;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Exception\SpecializedServiceException;
use Netresearch\NrLlm\Specialized\Exception\UnsupportedFormatException;
use Netresearch\NrLlm\Specialized\MultipartBodyBuilderTrait;
use Netresearch\NrLlm\Specialized\Option\TranscriptionOptions;
use Throwable;

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
 * Owns its own multipart-request execution rather than using the
 * trait's `sendMultipartRequest()` because Whisper returns raw
 * `string` bodies for the `text`/`srt`/`vtt` response formats — the
 * base's `executeRequest()` always JSON-decodes. Only the body
 * construction (`encodeMultipartBody`) is shared via the trait.
 *
 * @see https://platform.openai.com/docs/guides/speech-to-text
 */
final class WhisperTranscriptionService extends AbstractSpecializedService
{
    use MultipartBodyBuilderTrait;

    private const API_URL = 'https://api.openai.com/v1/audio';
    private const DEFAULT_MODEL = 'whisper-1';
    private const MAX_FILE_SIZE = 25 * 1024 * 1024; // 25 MB

    /** Supported input audio formats. */
    private const SUPPORTED_FORMATS = [
        'flac', 'mp3', 'mp4', 'mpeg', 'mpga', 'm4a', 'ogg', 'wav', 'webm',
    ];

    /** Supported output response formats. */
    private const RESPONSE_FORMATS = [
        'json', 'text', 'srt', 'vtt', 'verbose_json',
    ];

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

        $this->validateAudioFile($audioPath);
        $this->enforceBudget($options->getBeUserUid(), $options->getPlannedCost(), $options->configuration);

        $response = $this->runLifecycle(
            $this->transcriptionContext($options),
            fn(): array|string => $this->sendTranscriptionRequest($audioPath, $options),
        );

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

        if (strlen($audioContent) > self::MAX_FILE_SIZE) {
            throw new UnsupportedFormatException(
                sprintf('Audio content exceeds maximum size of %d MB', self::MAX_FILE_SIZE / 1024 / 1024),
                'speech',
            );
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, self::SUPPORTED_FORMATS, true)) {
            throw UnsupportedFormatException::audioFormat($extension);
        }

        $this->enforceBudget($options->getBeUserUid(), $options->getPlannedCost(), $options->configuration);

        $response = $this->runLifecycle(
            $this->transcriptionContext($options),
            fn(): array|string => $this->sendTranscriptionRequestFromContent($audioContent, $filename, $options),
        );

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
        $this->enforceBudget($options->getBeUserUid(), $options->getPlannedCost(), $options->configuration);

        $response = $this->runLifecycle(
            $this->transcriptionContext($options),
            fn(): array|string => $this->sendTranslationRequest($audioPath, $options),
        );

        return $this->parseTranscriptionResponse($response, $options);
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
     * OpenAI transcription vocabulary: whisper-* plus *-transcribe members
     * of newer families (gpt-4o-transcribe, ...).
     */
    protected function acceptsModelId(string $modelId): bool
    {
        return str_starts_with($modelId, 'whisper-') || str_ends_with($modelId, '-transcribe');
    }

    protected function getModelCapability(): ModelCapability
    {
        return ModelCapability::TRANSCRIPTION;
    }

    protected function getServiceDomain(): string
    {
        return 'speech';
    }

    protected function getServiceProvider(): string
    {
        return 'whisper';
    }

    protected function getDefaultBaseUrl(): string
    {
        return self::API_URL;
    }

    protected function getDefaultTimeout(): int
    {
        // Transcription can take longer than other operations.
        return 120;
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function loadServiceConfiguration(array $config): void
    {
        $this->loadOpenAiServiceConfiguration($config, ['speech', 'whisper']);
    }

    protected function getProviderLabel(): string
    {
        return 'Whisper';
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
        $content = file_get_contents($audioPath);
        if ($content === false) {
            throw new ServiceUnavailableException(
                sprintf('Failed to read audio file: %s', $audioPath),
                'speech',
                ['audioPath' => $audioPath],
            );
        }
        $parts = $this->buildTranscriptionParts(basename($audioPath), $content, $options);

        return $this->dispatchMultipart('transcriptions', $parts, $options);
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
        $parts = $this->buildTranscriptionParts($filename, $audioContent, $options);

        return $this->dispatchMultipart('transcriptions', $parts, $options);
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
        $content = file_get_contents($audioPath);
        if ($content === false) {
            throw new ServiceUnavailableException(
                sprintf('Failed to read audio file: %s', $audioPath),
                'speech',
                ['audioPath' => $audioPath],
            );
        }
        $parts = $this->buildTranscriptionParts(basename($audioPath), $content, $options);

        return $this->dispatchMultipart('translations', $parts, $options);
    }

    /**
     * Compose the multipart parts list shared by transcription /
     * translation paths. The file part comes first; optional fields
     * follow only when set on the options object.
     *
     * @return list<array<string, mixed>>
     */
    private function buildTranscriptionParts(
        string $filename,
        string $audioContent,
        TranscriptionOptions $options,
    ): array {
        $parts = [
            [
                'name'        => 'file',
                'filename'    => $filename,
                'content'     => $audioContent,
                'contentType' => 'application/octet-stream',
            ],
            [
                'name'  => 'model',
                'value' => $options->model ?? self::DEFAULT_MODEL,
            ],
        ];

        if ($options->language !== null) {
            $parts[] = ['name' => 'language', 'value' => $options->language];
        }
        if ($options->format !== null) {
            $parts[] = ['name' => 'response_format', 'value' => $options->format];
        }
        if ($options->prompt !== null) {
            $parts[] = ['name' => 'prompt', 'value' => $options->prompt];
        }
        if ($options->temperature !== null) {
            $parts[] = ['name' => 'temperature', 'value' => (string)$options->temperature];
        }

        return $parts;
    }

    /**
     * Send the multipart request and return either the JSON-decoded
     * body (for `json` / `verbose_json`) or the raw string body
     * (`text` / `srt` / `vtt`). Whisper's response shape is
     * format-dependent so the base's strict-array `executeRequest()`
     * does not fit; this method owns the request lifecycle while
     * still using the trait's `encodeMultipartBody()` for the wire
     * payload and the base's secure client for audited auth injection.
     *
     * @param list<array<string, mixed>> $parts
     *
     * @throws ServiceUnavailableException
     * @throws ServiceConfigurationException
     *
     * @return array<string, mixed>|string
     */
    private function dispatchMultipart(string $endpoint, array $parts, TranscriptionOptions $options): array|string
    {
        $boundary = 'whisper-' . uniqid('', true);
        $body     = $this->encodeMultipartBody($parts, $boundary);
        $url      = $this->buildEndpointUrl($endpoint);

        $this->setAuditContext(sprintf(
            '%s, %s',
            $options->model ?? self::DEFAULT_MODEL,
            $endpoint === 'translations' ? 'translate' : 'transcribe',
        ));

        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Content-Type', 'multipart/form-data; boundary=' . $boundary);
        foreach ($this->getAdditionalHeaders() as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        $request = $request->withBody($this->streamFactory->createStream($body));

        // Fail closed before the try (see AbstractSpecializedService, ADR-099).
        $this->assertWithinLifecycle();

        try {
            $response = $this->getSecureClient()->sendRequest($request);
            $statusCode = $response->getStatusCode();
            $responseBody = (string)$response->getBody();

            if ($statusCode >= 200 && $statusCode < 300) {
                $format = $options->format ?? 'json';
                if (in_array($format, ['text', 'srt', 'vtt'], true)) {
                    // Raw-string formats expose no duration; WhisperUsageExtractor
                    // records the request with no audio seconds (ADR-100).
                    return $responseBody;
                }

                // `verbose_json` reports the audio duration the extractor bills;
                // plain `json` does not — the extractor reads what the response
                // exposes.
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);

                return $decoded;
            }

            $errorMessage = $this->decodeErrorMessage($responseBody);

            $this->logger->error('Whisper API error', [
                'status_code' => $statusCode,
                'error'       => $errorMessage,
            ]);

            throw $this->mapErrorStatus($statusCode, $errorMessage);
        } catch (SpecializedServiceException $e) {
            // Pass any already-typed failure through unchanged — re-wrapping a 429
            // (ServiceQuotaExceededException) as a connection error below would
            // erase its classification (ADR-095).
            throw $e;
        } catch (Throwable $e) {
            // Log the full Throwable server-side (the chained cause below carries
            // the detail); surface only a static message so connection details,
            // endpoint URLs or SSL state never reach the caller.
            $this->logger->error('Whisper API connection error', ['exception' => $e]);

            throw new ServiceUnavailableException(
                'Failed to connect to Whisper API',
                'speech',
                ['provider' => 'whisper'],
                0,
                $e,
            );
        }
    }

    /**
     * Build the dispatch context carrying the usage intent (ADR-100): the model
     * and attribution WhisperUsageExtractor needs. The audio duration and cost
     * come from the response the extractor reads; a request whose format exposes
     * no duration records no audio seconds, exactly as before.
     */
    private function transcriptionContext(TranscriptionOptions $options): ProviderCallContext
    {
        $model = $options->model ?? self::DEFAULT_MODEL;

        return ProviderCallContext::forService(ProviderOperation::Transcription, $this->getServiceProvider(), $model)
            ->withMetadata([SpecializedUsageIntent::METADATA_KEY => new SpecializedUsageIntent(
                modelId: $model,
                modelUid: $this->resolveModelUid($model),
                configurationUid: $this->resolveConfigurationUid($options->configuration),
                beUserUid: $options->getBeUserUid(),
            )]);
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

        if (is_string($response)) {
            return new TranscriptionResult(
                text: $response,
                language: $options->language ?? 'en',
                metadata: ['format' => $format],
            );
        }

        $text = is_string($response['text'] ?? null) ? $response['text'] : '';
        $language = is_string($response['language'] ?? null)
            ? $response['language']
            : ($options->language ?? 'en');
        $duration = isset($response['duration']) && is_numeric($response['duration'])
            ? (float)$response['duration']
            : null;

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
