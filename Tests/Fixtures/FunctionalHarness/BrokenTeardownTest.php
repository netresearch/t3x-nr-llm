<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\FunctionalHarness;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * Deliberate lifecycle leak; executed only by the harness child process.
 */
#[CoversNothing]
final class BrokenTeardownTest extends IsolatedFunctionalFixture
{
    protected bool $initializeDatabase = false;

    #[Test]
    public function successfulSetupPrecedesTheDeliberateTeardownFailure(): void
    {
        self::assertTrue(true);
        error_reporting(error_reporting() ^ E_USER_WARNING);
    }
}
