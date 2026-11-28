<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api\Fixtures;

/**
 * AbstractBase that starts to implement StandalonePort without implementing
 * send(): every subclass now has to.
 */
abstract class AbstractBaseImplementingPort implements StandalonePort
{
    public function label(): string
    {
        return 'base';
    }
}
