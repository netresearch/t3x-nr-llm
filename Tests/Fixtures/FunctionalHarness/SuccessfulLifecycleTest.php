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
 * Success/absent-database control; executed only by the harness child process.
 */
#[CoversNothing]
final class SuccessfulLifecycleTest extends IsolatedFunctionalFixture
{
    protected bool $initializeDatabase = false;

    #[Test]
    public function executesOneAssertionAfterSuccessfulSetup(): void
    {
        self::assertTrue(true);
    }
}
