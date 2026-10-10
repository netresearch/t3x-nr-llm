<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Configuration;

use Netresearch\NrLlm\Domain\Model\SkillSource;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class SkillLeaseFieldsTest extends TestCase
{
    #[Test]
    public function leaseOwnershipCannotBeEditedInFormEngineOrReadFromTheSourceModel(): void
    {
        $tca = require dirname(__DIR__, 3) . '/Configuration/TCA/tx_nrllm_skill_source.php';
        self::assertIsArray($tca);
        self::assertIsArray($tca['columns']);
        foreach (['sync_lock_token', 'sync_lock_version'] as $field) {
            self::assertArrayNotHasKey($field, $tca['columns']);
        }

        $properties = (new SkillSource())->_getProperties();
        self::assertArrayNotHasKey('syncLockToken', $properties);
        self::assertArrayNotHasKey('syncLockVersion', $properties);
    }
}
