<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api\Fixtures;

/**
 * The "before" side of the constructor-break fixture pair.
 *
 * Its sibling WidenedConstructor differs from it in the constructor and in
 * nothing else — same public property, same method signature — so a rendering
 * difference between the two can only come from the constructor.
 */
final readonly class NarrowConstructor
{
    public function __construct(public string $name) {}

    public function label(): string
    {
        return $this->name;
    }
}
