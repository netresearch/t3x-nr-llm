<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api\Fixtures;

/**
 * RunnerPort with an optional parameter: every implementation without it breaks.
 */
interface RunnerPortWithOption
{
    public function run(string $a, int $limit = 0): string;
}
