<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Utility;

use Netresearch\NrVault\Secret\SecretPatternLibrary;

/**
 * Trait for redacting credential-bearing URLs from error messages before they are
 * logged or surfaced.
 *
 * HTTP client exceptions may include the full request URL (e.g., Gemini's
 * `?key=...` pattern). This masks the two URL shapes in nr-vault's shared
 * catalogue (ADR-123, nr-vault ADR-031):
 * - the values of credential query parameters, including vendor-prefixed names
 *   such as `client_secret` and `x_api_key`;
 * - the password in a `scheme://user:password@host` userinfo component
 *   (database/service connection strings such as `postgres://…`, `redis://…`).
 *
 * It deliberately covers ONLY those two, not the full shape catalogue: this runs
 * on error messages, where the aim is to strip the credential a client library
 * put into a URL, not to scan arbitrary prose. Callers that want the whole
 * catalogue use {@see SecretShapeRedactorTrait}. It also does not scrub header
 * values — callers must not write raw header dumps into error messages at all.
 */
trait ErrorMessageSanitizerTrait
{
    /**
     * Redact credential query parameters and connection-string passwords from
     * URLs in the message.
     *
     * The patterns come from the shared catalogue, so the bounding that keeps them
     * from running off the end of the URL is maintained in one place. Unbounded,
     * they ate the rest of the message: a `?token=…` inside a JSON payload
     * swallowed the closing quote and the following key, and a URL carrying a port
     * followed later by an address collapsed into a single fabricated
     * `https://host:***(at)example.org` — inventing a credentialled request to a
     * host that was never contacted.
     */
    protected function sanitizeErrorMessage(string $message): string
    {
        $sanitised = $message;

        foreach (SecretPatternLibrary::urlCredentials() as $pattern) {
            if ($pattern->inlinePattern === null) {
                continue;
            }

            $result = preg_replace($pattern->inlinePattern, $pattern->inlineReplacement, $sanitised);

            // Keep the last good text: a pattern that gives up on a pathological
            // message must not blank the message entirely.
            if (\is_string($result)) {
                $sanitised = $result;
            }
        }

        return $sanitised;
    }
}
