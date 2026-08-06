<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Enum;

use Netresearch\NrLlm\Domain\Enum\BackendUserGrant;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class BackendUserGrantTest extends TestCase
{
    #[Test]
    public function valuesListsEveryCase(): void
    {
        self::assertSame(['tasks_use', 'agent_approve'], BackendUserGrant::values());
    }

    #[Test]
    public function isValidAcceptsKnownAndRejectsUnknownValues(): void
    {
        self::assertTrue(BackendUserGrant::isValid('tasks_use'));
        self::assertFalse(BackendUserGrant::isValid('root'));
        self::assertFalse(BackendUserGrant::isValid(''));
    }

    #[Test]
    public function permissionValuesCarryThePrefixAndNoStrippedCharacters(): void
    {
        foreach (BackendUserGrant::cases() as $grant) {
            self::assertSame('tx_nrllm_grants:' . $grant->value, $grant->permissionValue());
            // TYPO3 strips `:|,` from custom permission ITEM keys when
            // rendering the be_groups select — a value containing one would
            // be stored mangled and silently deny (ADR-130). The single
            // colon separating prefix and value is the core's own separator.
            self::assertDoesNotMatchRegularExpression('/[:|,]/', $grant->value);
        }
    }
}
