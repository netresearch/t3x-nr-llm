<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api\Fixtures;

use Countable;

/**
 * ConcreteBase that also implements Countable, with the method: additive.
 */
class CountableBase implements Countable
{
    public function label(): string
    {
        return 'base';
    }

    public function count(): int
    {
        return 1;
    }
}
