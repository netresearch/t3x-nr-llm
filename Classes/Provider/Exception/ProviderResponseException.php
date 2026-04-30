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
 *   instead of swallowing it as a generic 500;
 * - decide whether to retry (5xx) or surface to the user (4xx);
 * - log the upstream `responseBody` separately from the user-facing
 *   message (the message is already sanitised — we strip
 *   `?api_key=...` from URLs before it enters the exception, see
 *   `AbstractProvider::sanitizeErrorMessage()`).
 *
 * The previous constructor signature `(string $message, int $code = 0,
 * ?Throwable $previous = null)` is preserved for back-compat: existing
 * callers that passed the HTTP status as the second arg keep working —
 * `httpStatus` defaults to that value when the explicit named arg is
 * not supplied.
 */
final class ProviderResponseException extends ProviderException
{
    public readonly int $httpStatus;

    public function __construct(
        string $message,
        int $httpStatus = 0,
        public readonly string $responseBody = '',
        public readonly string $endpoint = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
        $this->httpStatus   = $httpStatus;
    }
}
