<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api\Fixtures;

/**
 * Stands in for `Specialized\AbstractSpecializedService`: an own-namespace,
 * NOT-`@api` base whose public constructor is the effective constructor of
 * every subclass that declares none.
 */
abstract class InheritedConstructorBase
{
    public function __construct(protected readonly string $endpoint) {}
}
