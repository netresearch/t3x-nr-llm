<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill\Exception;

use RuntimeException;

final class HostNotAllowedException extends RuntimeException
{
    public static function forUrl(string $url): self
    {
        return new self(sprintf('URL "%s" is not on the GitHub host allowlist', $url), 1719500100);
    }
}
