<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api\Fixtures;

/**
 * A user of the private-hook traits, so static analysis reads them.
 */
final class UsesTraitWithPrivateAbstract
{
    use TraitWithPrivateAbstract;
    use TraitWithoutPrivateAbstract {
        TraitWithoutPrivateAbstract::label insteadof TraitWithPrivateAbstract;
    }

    private function secret(): string
    {
        return 'base';
    }
}
