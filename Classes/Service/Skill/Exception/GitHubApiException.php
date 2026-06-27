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
     */
    public function __construct(string $message, int $code, public readonly bool $isRateLimit = false)
    {
        parent::__construct($message, $code);
    }

    public static function forStatus(string $url, int $status): self
    {
        return new self(sprintf('GitHub API request to "%s" failed with status %d', $url, $status), 1719500101);
    }

    public static function forRateLimit(int $resetEpoch): self
    {
        return new self(sprintf('GitHub API rate limit exceeded; resets at %d', $resetEpoch), 1719500102, true);
    }
}
