<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill\Exception;

use RuntimeException;

final class GitHubApiException extends RuntimeException
{
    /**
     * @param bool $isRateLimit True only for rate-limit failures, which must stay fatal even inside
     *                          per-file / per-repo error isolation (re-thrown to abort the whole sync).
     * @param int  $status      The HTTP status code that triggered the failure (0 when not transport-derived).
     *                          Callers inspect this to distinguish e.g. a 404 (file gone) from a transient error.
     */
    public function __construct(
        string $message,
        int $code,
        public readonly bool $isRateLimit = false,
        public readonly int $status = 0,
    ) {
        parent::__construct($message, $code);
    }

    public static function forStatus(string $url, int $status): self
    {
        return new self(sprintf('GitHub API request to "%s" failed with status %d', $url, $status), 1719500101, false, $status);
    }

    /**
     * A 2xx response whose body is not the expected JSON (malformed JSON or a
     * non-object payload). Distinct from {@see forStatus()} so a broken/garbled
     * upstream response is not mistaken for a transport status (e.g. a 404).
     */
    public static function forMalformedResponse(string $url): self
    {
        return new self(sprintf('GitHub API response from "%s" was not valid JSON', $url), 1751280201, false, 0);
    }

    public static function forRateLimit(int $resetEpoch): self
    {
        return new self(sprintf('GitHub API rate limit exceeded; resets at %d', $resetEpoch), 1719500102, true, 429);
    }
}
