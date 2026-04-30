<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized;

use Netresearch\NrLlm\Specialized\Exception\ServiceConfigurationException;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;

/**
 * Multipart/form-data body construction for specialised services
 * that upload files (REC #7).
 *
 * Currently consumed by `WhisperTranscriptionService` (audio
 * transcription file upload) and `DallEImageService` (image edit /
 * variation file upload). JSON-only services (`FalImageService`,
 * `TextToSpeechService`, `DeepLTranslator`) do not use this trait
 * — keeping multipart out of `AbstractSpecializedService` means
 * those three services do not pay the trait's footprint.
 *
 * Consumers must `use` `AbstractSpecializedService` (this trait
 * relies on its `requestFactory`, `streamFactory`, `buildAuthHeaders()`,
 * `buildEndpointUrl()`, and `executeRequest()` members).
 *
 * Part shapes:
 * - File part:    `['name' => string, 'filename' => string, 'content' => string, 'contentType' => string]`
 *                 (`contentType` is optional; defaults to `application/octet-stream`).
 * - Field part:   `['name' => string, 'value' => string|int|float]`.
 */
trait MultipartBodyBuilderTrait
{
    /**
     * Send a multipart/form-data request and return the decoded
     * response. Boundary is generated per-call via `uniqid()` with a
     * `nrllm-` prefix to keep the wire payload self-identifying in
     * captured traffic.
     *
     * @param list<array<string, mixed>> $parts Each part as a file or field shape (see trait docblock)
     *
     * @throws ServiceUnavailableException
     * @throws ServiceConfigurationException
     *
     * @return array<string, mixed>
     */
    protected function sendMultipartRequest(string $endpoint, array $parts): array
    {
        $boundary = 'nrllm-' . uniqid('', true);
        $body     = $this->encodeMultipartBody($parts, $boundary);
        $url      = $this->buildEndpointUrl($endpoint);

        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Content-Type', 'multipart/form-data; boundary=' . $boundary);

        foreach ($this->buildAuthHeaders() as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $request = $request->withBody($this->streamFactory->createStream($body));

        return $this->executeRequest($request);
    }

    /**
     * Encode parts into a multipart/form-data body. Public-ish (it's
     * `protected`) so subclasses that need to inspect or test the
     * exact wire body can call it directly without firing a real
     * HTTP request.
     *
     * @param list<array<string, mixed>> $parts
     */
    protected function encodeMultipartBody(array $parts, string $boundary): string
    {
        $body = '';
        foreach ($parts as $part) {
            $name = isset($part['name']) && is_string($part['name']) ? $part['name'] : '';
            if ($name === '') {
                continue;
            }

            $body .= "--{$boundary}\r\n";

            $isFile = isset($part['filename']);
            if ($isFile) {
                $filename    = is_string($part['filename'] ?? null) ? $part['filename'] : '';
                $content     = is_string($part['content'] ?? null) ? $part['content'] : '';
                $contentType = is_string($part['contentType'] ?? null) ? $part['contentType'] : 'application/octet-stream';
                $body .= sprintf(
                    "Content-Disposition: form-data; name=\"%s\"; filename=\"%s\"\r\n",
                    $name,
                    $filename,
                );
                $body .= "Content-Type: {$contentType}\r\n\r\n";
                $body .= $content . "\r\n";
                continue;
            }

            $value = $part['value'] ?? '';
            $stringValue = is_scalar($value) ? (string)$value : '';
            $body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
            $body .= $stringValue . "\r\n";
        }

        $body .= "--{$boundary}--\r\n";

        return $body;
    }
}
