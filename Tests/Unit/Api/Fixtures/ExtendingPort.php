<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api\Fixtures;

use Countable;

/**
 * The same port extending Countable: every implementation now has to count.
 */
interface ExtendingPort extends Countable
{
    public function send(string $text): void;
}
