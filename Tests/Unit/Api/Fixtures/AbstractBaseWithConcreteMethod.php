<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api\Fixtures;

/**
 * AbstractBase plus one concrete method: additive, a subclass inherits it.
 */
abstract class AbstractBaseWithConcreteMethod
{
    public function label(): string
    {
        return 'base';
    }

    public function kind(): string
    {
        return 'base';
    }
}
