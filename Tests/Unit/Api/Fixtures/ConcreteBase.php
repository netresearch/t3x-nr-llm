<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api\Fixtures;

/**
 * AbstractBase made concrete: nothing a subclass or caller relies on goes away.
 */
class ConcreteBase
{
    public function label(): string
    {
        return 'base';
    }
}
