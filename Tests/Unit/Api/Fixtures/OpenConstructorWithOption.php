<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api\Fixtures;

/**
 * OpenConstructor with an optional constructor parameter: a constructor is no
 * override target, so neither callers nor subclasses break.
 */
class OpenConstructorWithOption
{
    public function __construct(public string $name, public int $limit = 0) {}
}
