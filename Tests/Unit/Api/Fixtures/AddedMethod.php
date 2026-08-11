<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api\Fixtures;

/**
 * NarrowConstructor plus one extra method — the additive counter-example.
 *
 * Nothing an existing caller uses moved, so the diff must classify this as
 * additive and say the snapshot may simply be regenerated.
 */
final readonly class AddedMethod
{
    public function __construct(public string $name) {}

    public function label(): string
    {
        return $this->name;
    }

    public function shout(): string
    {
        return strtoupper($this->name);
    }
}
