<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Utility;

/**
 * Trait for stripping secrets from error messages before they are logged
 * or surfaced.
 *
 * HTTP client exceptions may include full URLs with query parameters
 * containing API keys (e.g., Gemini's `?key=...` pattern). This strips
 * sensitive query parameters so providers and the setup wizard never leak
 * a secret into logs or client-facing messages.
 */
trait ErrorMessageSanitizerTrait
{
    /**
     * Sanitize error messages to prevent leaking secrets (API keys in URLs, headers, etc.).
     */
    protected function sanitizeErrorMessage(string $message): string
    {
        // Strip query parameters that may contain API keys from URLs in the message
        return (string)preg_replace(
            '/([?&])(key|api_key|apikey|token|secret|access_token)=[^&\s]+/i',
            '$1$2=***',
            $message,
        );
    }
}
