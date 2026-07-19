<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Utility;

/**
 * Trait for redacting credential-bearing URLs from error messages (and, via the
 * secret guardrails, model output) before they are logged or surfaced.
 *
 * HTTP client exceptions may include the full request URL (e.g., Gemini's
 * `?key=...` pattern). This redacts two shapes:
 * - the values of the well-known credential query parameters (`key`, `api_key`,
 *   `apikey`, `token`, `secret`, `access_token`);
 * - the password in a `scheme://user:password@host` userinfo component
 *   (database/service connection strings such as `postgres://…`, `redis://…`).
 * It deliberately does NOT scrub other secret material such as header values —
 * callers must not write raw header dumps into error messages in the first place.
 */
trait ErrorMessageSanitizerTrait
{
    /**
     * Redact credential query parameters and connection-string passwords from
     * URLs in the message.
     */
    protected function sanitizeErrorMessage(string $message): string
    {
        return (string)preg_replace(
            [
                // credential query parameters (?key=, ?token=, …)
                '/([?&])(key|api_key|apikey|token|secret|access_token)=[^&\s]+/i',
                // the password in a `scheme://user:password@host` userinfo (the
                // username may be empty, e.g. `redis://:password@host`). Uses a ~
                // delimiter because the pattern itself contains '#'.
                '~(\b[a-z][a-z0-9+.\-]*://[^:/?#\s@]*):[^@/?#\s]+@~i',
            ],
            [
                '$1$2=***',
                '$1:***@',
            ],
            $message,
        );
    }
}
