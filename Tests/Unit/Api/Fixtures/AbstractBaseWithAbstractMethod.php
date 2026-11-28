<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api\Fixtures;

/**
 * AbstractBase plus one abstract method: every subclass outside the package
 * has to implement it, so the diff must classify it as breaking.
 */
abstract class AbstractBaseWithAbstractMethod
{
    public function label(): string
    {
        return 'base';
    }

    abstract public function kind(): string;
}
