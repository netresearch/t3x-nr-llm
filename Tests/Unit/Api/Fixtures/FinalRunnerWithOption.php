<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api\Fixtures;

/**
 * FinalRunner with an optional parameter: nobody can override run().
 */
final class FinalRunnerWithOption
{
    public function run(string $a, int $limit = 0): string
    {
        return $a . $limit;
    }
}
