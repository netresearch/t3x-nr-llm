<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Domain\Enum\BackendUserGrant;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSelfServiceLimits;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The merge of the limits on the groups of a user (ADR-294): every zero is the
 * strict value, a group without the grant contributes nothing, and each limit
 * is tested in both directions.
 */
#[CoversClass(A2aSelfServiceLimits::class)]
final class A2aSelfServiceLimitsTest extends TestCase
{
    private const NOW = 1_800_000_000;

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    private function group(array $settings = [], bool $grant = true): array
    {
        return ['custom_options' => $grant ? 'tx_nrllm_grants:tasks_use,' . BackendUserGrant::A2A_TOKEN_SELF_SERVICE->permissionValue() : 'tx_nrllm_grants:tasks_use'] + $settings;
    }

    #[Test]
    public function aUserWithoutAGroupThatHoldsTheGrantMayDoNothing(): void
    {
        self::assertFalse(A2aSelfServiceLimits::fromGroups([])->permitted);
        self::assertFalse(A2aSelfServiceLimits::fromGroups([$this->group([], false)])->permitted);
        self::assertNotSame('', A2aSelfServiceLimits::fromGroups([])->deniedReason);
    }

    #[Test]
    public function theGrantAloneAllowsTheStrictToken(): void
    {
        $limits = A2aSelfServiceLimits::fromGroups([$this->group()]);

        self::assertTrue($limits->permitted);
        self::assertFalse($limits->allowLive);
        self::assertSame(90, $limits->maxExpiryDays);
        self::assertFalse($limits->allowNoExpiry);
        self::assertSame([], $limits->profileUids);
    }

    #[Test]
    public function aRelaxationOfAGroupWithoutTheGrantChangesNothing(): void
    {
        $relaxed = [
            A2aSelfServiceLimits::FIELD_ALLOW_LIVE      => 1,
            A2aSelfServiceLimits::FIELD_ALLOW_NO_EXPIRY => 1,
            A2aSelfServiceLimits::FIELD_MAX_EXPIRY_DAYS => 365,
            A2aSelfServiceLimits::FIELD_PROFILES        => '5,6',
        ];

        $limits = A2aSelfServiceLimits::fromGroups([$this->group(), $this->group($relaxed, false)]);

        self::assertFalse($limits->allowLive);
        self::assertFalse($limits->allowNoExpiry);
        self::assertSame(90, $limits->maxExpiryDays);
        self::assertSame([], $limits->profileUids);

        // And the same relaxations on a group that does hold the grant apply.
        $held = A2aSelfServiceLimits::fromGroups([$this->group($relaxed)]);
        self::assertTrue($held->allowLive);
        self::assertTrue($held->allowNoExpiry);
        self::assertSame(365, $held->maxExpiryDays);
        self::assertSame([5, 6], $held->profileUids);
    }

    #[Test]
    public function theGroupsOfAUserAreMergedAsDocumented(): void
    {
        $limits = A2aSelfServiceLimits::fromGroups([
            $this->group([A2aSelfServiceLimits::FIELD_MAX_EXPIRY_DAYS => 30, A2aSelfServiceLimits::FIELD_PROFILES => '1,2']),
            $this->group([A2aSelfServiceLimits::FIELD_MAX_EXPIRY_DAYS => 60, A2aSelfServiceLimits::FIELD_ALLOW_LIVE => 1, A2aSelfServiceLimits::FIELD_PROFILES => '2, 3,x,-4,0']),
            $this->group(),
        ]);

        self::assertSame(60, $limits->maxExpiryDays, 'the largest value a group sets');
        self::assertTrue($limits->allowLive, 'any group');
        self::assertSame([1, 2, 3], $limits->profileUids, 'the union, junk dropped');
    }

    #[Test]
    public function aGroupThatSetsNoMaximumDoesNotWidenWhatAnotherSets(): void
    {
        self::assertSame(30, A2aSelfServiceLimits::fromGroups([$this->group([A2aSelfServiceLimits::FIELD_MAX_EXPIRY_DAYS => 30]), $this->group()])->maxExpiryDays);
        self::assertSame(90, A2aSelfServiceLimits::fromGroups([$this->group(), $this->group()])->maxExpiryDays);
    }

    #[Test]
    public function theMaximumNeverExceedsTheCeilingWhateverTheDatabaseHolds(): void
    {
        self::assertSame(730, A2aSelfServiceLimits::fromGroups([$this->group([A2aSelfServiceLimits::FIELD_MAX_EXPIRY_DAYS => 99999])])->maxExpiryDays);
        self::assertSame(90, A2aSelfServiceLimits::fromGroups([$this->group([A2aSelfServiceLimits::FIELD_MAX_EXPIRY_DAYS => -5])])->maxExpiryDays);
        self::assertSame(90, A2aSelfServiceLimits::fromGroups([$this->group([A2aSelfServiceLimits::FIELD_MAX_EXPIRY_DAYS => 'many'])])->maxExpiryDays);
    }

    #[Test]
    public function anExpiryIsWithinTheMaximumOrItIsNot(): void
    {
        $limits = A2aSelfServiceLimits::fromGroups([$this->group()]);
        $day    = 86400;

        self::assertTrue($limits->allowsExpiry(self::NOW + 89 * $day, self::NOW));
        self::assertTrue($limits->allowsExpiry(self::NOW + 90 * $day, self::NOW), 'the maximum itself');
        self::assertFalse($limits->allowsExpiry(self::NOW + 90 * $day + 1, self::NOW));
        self::assertFalse($limits->allowsExpiry(0, self::NOW), 'never needs an explicit allowance');
        self::assertFalse($limits->allowsExpiry(self::NOW - 1, self::NOW));
    }

    #[Test]
    public function noExpiryIsAllowedOnlyByAnExplicitChoice(): void
    {
        $limits = A2aSelfServiceLimits::fromGroups([$this->group([A2aSelfServiceLimits::FIELD_ALLOW_NO_EXPIRY => 1])]);

        self::assertTrue($limits->allowsExpiry(0, self::NOW));
        self::assertTrue($limits->allowsExpiry(self::NOW + 1000 * 86400, self::NOW));
    }
}
