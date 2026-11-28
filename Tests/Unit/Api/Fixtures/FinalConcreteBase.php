<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api\Fixtures;

/**
 * ConcreteBase made final: a subclass outside nr_llm no longer compiles.
 */
final class FinalConcreteBase
{
    public function label(): string
    {
        return 'base';
    }
}
