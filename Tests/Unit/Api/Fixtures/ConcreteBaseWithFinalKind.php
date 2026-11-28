<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api\Fixtures;

/**
 * ConcreteBaseWithPublicKind with kind() made final: a subclass that
 * overrides it no longer compiles.
 */
class ConcreteBaseWithFinalKind
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
