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
 * Deliberately invalid package; executed only by the harness child process.
 */
#[CoversNothing]
final class BrokenBootstrapTest extends IsolatedFunctionalFixture
{
    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['audit_missing_extension'];

    #[Test]
    public function bootstrapMustFinishBeforeThisBodyRuns(): void
    {
        self::assertTrue(true);
    }
}
