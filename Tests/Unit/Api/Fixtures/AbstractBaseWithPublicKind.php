<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api\Fixtures;

/**
 * AbstractBaseWithAbstractMethod with a body for kind(), still abstract: the
 * method only loses `abstract`, which breaks nobody.
 */
abstract class AbstractBaseWithPublicKind
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
