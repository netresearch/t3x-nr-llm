<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api\Fixtures;

/**
 * An abstract base with a protected static abstract factory: the snapshot has
 * to record `static`, or making it static changes nothing there.
 */
abstract class AbstractBaseWithStaticAbstract
{
    abstract protected static function make(): string;
}
