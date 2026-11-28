<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api\Fixtures;

use Psr\Log\LoggerAwareInterface;

/**
 * AbstractBase that implements a third-party interface and leaves its method
 * open: every subclass outside nr_llm now has to implement setLogger(), and
 * the snapshot does not render methods declared outside nr_llm or PHP.
 */
abstract class AbstractBaseImplementingLoggerAware implements LoggerAwareInterface
{
    public function label(): string
    {
        return 'base';
    }
}
