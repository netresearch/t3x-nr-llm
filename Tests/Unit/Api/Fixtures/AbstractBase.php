<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api\Fixtures;

/**
 * An abstract base with one concrete method — the "before" of a subclass break.
 */
abstract class AbstractBase
{
    public function label(): string
    {
        return 'base';
    }
}
