<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Exception;

use Throwable;

/**
 * Thrown when a provider returns a non-2xx response we can decode.
 *
 * Carries typed `httpStatus`, `responseBody`, and `endpoint` properties
 * so callers (controllers, log sinks, retry-decision logic) can branch
 * on the actual HTTP semantics rather than re-parsing the message
 * string. Useful for example to:
 *
 * - return the upstream HTTP status from a backend AJAX endpoint
 *   instead of swallowing it as a generic 500 (4xx and 5xx alike;
 *   e.g. OpenRouter's default branch wraps server errors here too);
 * - decide whether to retry (5xx) or surface to the user (4xx);
 * - log the upstream `responseBody` separately from the user-facing
 *   message (the message is already sanitised — we strip
 *   `?api_key=...` from URLs before it enters the exception, see
 *   `AbstractProvider::sanitizeErrorMessage()`).
 *
 * Constructor parameter order matches the previous
 * `(string $message, int $httpStatus = 0, ?Throwable $previous = null)`
 * positional signature. The two new typed fields (`responseBody`,
 * `endpoint`) are positioned **after** `$previous` so existing callers
 * passing `($message, $status, $previous)` keep working without
 * silent type confusion. New callers should pass them by name.
 *
 * Endpoint sanitisation: any query string is stripped before storage
 * so providers like Gemini (which embed the API key in the URL via
 * `?key=<secret>`) don't accidentally leak credentials through
 * exception logging or telemetry.
 */
final class ProviderResponseException extends ProviderException
{
    public readonly int $httpStatus;
    public readonly string $endpoint;

    public function __construct(
        string $message,
        int $httpStatus = 0,
        ?Throwable $previous = null,
        public readonly string $responseBody = '',
        string $endpoint = '',
    ) {
        parent::__construct($message, $httpStatus, $previous);
        $this->httpStatus = $httpStatus;
        $this->endpoint   = self::sanitizeEndpoint($endpoint);
    }

    /**
     * Strip the query string and anything past it. Gemini and similar
     * providers ship the API key as `?key=<secret>` on the URL; the
     * endpoint field is meant for diagnostic purposes only and must
     * never leak credentials downstream.
     */
    private static function sanitizeEndpoint(string $endpoint): string
    {
        $queryStart = strpos($endpoint, '?');
        if ($queryStart === false) {
            return $endpoint;
        }
        return substr($endpoint, 0, $queryStart);
    }
}
