<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill\Exception;

use RuntimeException;

final class SkillParseException extends RuntimeException
{
    public static function forReason(string $path, string $reason): self
    {
        return new self(sprintf('Cannot parse skill "%s": %s', $path, $reason), 1719500000);
    }
}
