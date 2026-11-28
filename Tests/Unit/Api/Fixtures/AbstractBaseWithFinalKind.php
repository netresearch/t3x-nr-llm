<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api\Fixtures;

/**
 * AbstractBaseWithAbstractMethod with a final body for kind(): every
 * subclass that implemented kind() now fails, because it may not override it.
 */
abstract class AbstractBaseWithFinalKind
{
    public function label(): string
    {
        return 'base';
    }

    final public function kind(): string
    {
        return 'base';
    }
}
