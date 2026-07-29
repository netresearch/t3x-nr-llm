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
 * - the values of credential query parameters, including vendor-prefixed names
 *   such as `client_secret` and `x_api_key`;
 * - the password in a `scheme://user:password@host` userinfo component
 *   (database/service connection strings such as `postgres://…`, `redis://…`).
 * It deliberately does NOT scrub other secret material such as header values —
 * callers must not write raw header dumps into error messages in the first place.
 */
trait ErrorMessageSanitizerTrait
{
    /**
     * Parameter names whose value is a credential.
     *
     * The optional leading `(?:[a-z0-9]+[_\-])?` group is what makes
     * `client_secret` — the name RFC 6749 §2.3.1 defines — match at all. Without
     * it the alternation had to match immediately after the `?` or `&`, so an
     * OAuth client secret in a query string passed through untouched. A name like
     * `monkey` still cannot match: the prefixed form needs a `_`/`-` separator,
     * and the bare form has to account for the whole name.
     */
    private const CREDENTIAL_PARAMETER_PATTERN = '/([?&])((?:[a-z0-9]+[_\-])?(?:api[_\-]?key|apikey|access[_\-]?token|refresh[_\-]?token|id[_\-]?token|client[_\-]?secret|auth[_\-]?token|key|secret|token|password|passwd|pwd|credential|signature))=[^&\s"\'<>{}\[\](),;#]+/i';

    /**
     * The password of a `scheme://user:password@host` userinfo. The username may
     * be empty (`redis://:password@host`). A `~` delimiter is used because the
     * pattern itself contains `#`.
     */
    private const USERINFO_PASSWORD_PATTERN = '~(\b[a-z][a-z0-9+.\-]*://[^:/?#\s@]*):[^@/?#\s"\'<>{}\[\](),;]+@~i';

    /**
     * Redact credential query parameters and connection-string passwords from
     * URLs in the message.
     *
     * Both value classes stop at structural characters, not merely at `&` and
     * whitespace. Bounded only by those, the patterns ran off the end of the URL
     * and ate the rest of the line: a `?token=…` inside a JSON payload swallowed
     * the closing quote and the following key. Worse, a URL with a port followed
     * later by an e-mail address was read as one giant userinfo component: a JSON
     * message holding both a `https://example.com:8080` url and a contact address
     * collapsed into a single `https://example.com:***(at)example.org`. That does
     * not merely lose the port and the contact field, it fabricates a
     * credentialled URL to a host that was never contacted, misleading whoever
     * reads the message.
     */
    protected function sanitizeErrorMessage(string $message): string
    {
        return (string)preg_replace(
            [
                self::CREDENTIAL_PARAMETER_PATTERN,
                self::USERINFO_PASSWORD_PATTERN,
            ],
            [
                '$1$2=***',
                '$1:***@',
            ],
            $message,
        );
    }
}
