<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api\Fixtures;

/**
 * AbstractBaseWithProtectedAbstract with a body for the protected kind(),
 * still abstract. The snapshot does not render concrete protected methods,
 * so this reads like a deleted hook.
 */
abstract class AbstractBaseWithProtectedKind
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
