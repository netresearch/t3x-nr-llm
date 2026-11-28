<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api\Fixtures;

/**
 * A non-final class whose method is final.
 */
class OpenRunnerFinalMethod
{
    final public function run(string $a): string
    {
        return $a;
    }
}
