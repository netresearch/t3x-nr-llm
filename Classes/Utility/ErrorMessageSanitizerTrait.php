<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Utility;

/**
 * Trait for redacting known credential query parameters from error
 * messages before they are logged or surfaced.
 *
 * HTTP client exceptions may include the full request URL (e.g., Gemini's
 * `?key=...` pattern). This redacts the values of the well-known credential
 * query parameters (`key`, `api_key`, `apikey`, `token`, `secret`,
 * `access_token`) in any URL embedded in the message. It deliberately does
 * NOT scrub other secret material such as header values — callers must not
 * write raw header dumps into error messages in the first place.
 */
trait ErrorMessageSanitizerTrait
{
    /**
     * Redact well-known credential query parameters from URLs in the message.
     */
    protected function sanitizeErrorMessage(string $message): string
    {
        return (string)preg_replace(
            '/([?&])(key|api_key|apikey|token|secret|access_token)=[^&\s]+/i',
            '$1$2=***',
            $message,
        );
    }
}
