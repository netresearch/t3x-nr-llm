<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api\Fixtures;

/**
 * NarrowConstructor plus one required constructor argument.
 *
 * `$limit` is promoted private on purpose: a public promotion would also add
 * a property line, and then the rendering difference would no longer isolate
 * the constructor. This is exactly the shape that passed the snapshot
 * silently before constructors were recorded — every `new NarrowConstructor()`
 * in consumer code stops working, and nothing in the rendered surface moved.
 */
final readonly class WidenedConstructor
{
    public function __construct(
        public string $name,
        private int $limit,
    ) {}

    public function label(): string
    {
        return $this->name . ':' . $this->limit;
    }
}
