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
    public static function forStatus(string $url, int $status): self
    {
        return new self(sprintf('GitHub API request to "%s" failed with status %d', $url, $status), 1719500101);
    }

    public static function forRateLimit(int $resetEpoch): self
    {
        return new self(sprintf('GitHub API rate limit exceeded; resets at %d', $resetEpoch), 1719500102);
    }
}
