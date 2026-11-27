<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api\Fixtures;

/**
 * AbstractBase plus one PROTECTED abstract method: a subclass outside the
 * package has to implement it as well, so the diff must call it breaking.
 */
abstract class AbstractBaseWithProtectedAbstract
{
    public function label(): string
    {
        return 'base';
    }

    abstract protected function kind(): string;
}
