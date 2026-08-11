<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api\Fixtures;

use RuntimeException;

/**
 * Declares no constructor and inherits one from OUTSIDE this repository.
 *
 * The counterpart to InheritsOwnConstructor: `\RuntimeException` stands in for
 * TYPO3 core's `AbstractEntity`, whose inherited members differ between the
 * 13.4 and 14.x legs. Recording that signature would make the snapshot depend
 * on which matrix cell rendered it, so it stays out.
 */
final class InheritsForeignConstructor extends RuntimeException
{
    public function detail(): string
    {
        return $this->getMessage();
    }
}
