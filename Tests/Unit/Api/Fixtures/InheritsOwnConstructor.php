<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api\Fixtures;

/**
 * Declares no constructor and inherits one from an own-namespace base.
 *
 * `new InheritsOwnConstructor('https://…')` is what a caller writes, so the
 * inherited signature is this class's contract and must appear in the frozen
 * surface — a required argument added to the base is a break here.
 */
final class InheritsOwnConstructor extends InheritedConstructorBase
{
    public function call(): string
    {
        return $this->endpoint;
    }
}
