<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api\Fixtures;

/**
 * AbstractBaseWithProtectedAbstract made concrete: it implements kind(), so
 * nothing a subclass or caller relies on goes away.
 */
class ConcreteBaseWithProtectedKind
{
    public function label(): string
    {
        return 'base';
    }

    protected function kind(): string
    {
        return 'base';
    }
}
