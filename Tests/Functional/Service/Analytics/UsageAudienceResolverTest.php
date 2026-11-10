<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Analytics;

use Netresearch\NrLlm\Service\Analytics\UsageAudience;
use Netresearch\NrLlm\Service\Analytics\UsageAudienceResolver;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Whose AI usage a backend user may see in the group usage view (ADR-277).
 *
 * The fixture (GroupUsageVisibility.csv) assigns "News team" (51) to the
 * head editors (50). "News seniors" (55) includes 51 as a subgroup, so its
 * members are members of 51 in TYPO3's sense; "Shared base" (53) is a
 * subgroup OF 51, so its members are not.
 */
#[CoversClass(UsageAudienceResolver::class)]
#[CoversClass(UsageAudience::class)]
final class UsageAudienceResolverTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('GroupUsageVisibility.csv');
    }

    #[Test]
    public function aHolderSeesTheMembersOfHisAssignedGroupAndOfTheGroupsThatIncludeIt(): void
    {
        $audience = $this->audienceOf(70);

        self::assertTrue($audience->permitted);
        self::assertFalse($audience->everyone);
        // 71 is in News team; 73 is in News seniors, which includes News team.
        self::assertSame([71, 73], $audience->beUserUids);
        self::assertSame(['News team'], array_column($audience->assignedGroups, 'title'));
    }

    #[Test]
    public function aMemberOfASubgroupOfTheAssignedGroupIsNotAMember(): void
    {
        // base-dave (74) is only in "Shared base", which News team includes:
        // News team's members inherit Shared base, not the other way round.
        self::assertNotContains(74, $this->audienceOf(70)->beUserUids);
    }

    #[Test]
    public function aHiddenIncludingGroupMakesNoMember(): void
    {
        // hiddengroup-hank (76) is in a hidden group that includes News team.
        self::assertNotContains(76, $this->audienceOf(70)->beUserUids);
    }

    #[Test]
    public function administratorsCommandLineUsersAndDisabledUsersAreNeverInTheAudience(): void
    {
        $uids = $this->audienceOf(70)->beUserUids;

        self::assertNotContains(1, $uids, 'An administrator in News team stays out.');
        self::assertNotContains(81, $uids, '_cli_scheduler in News team stays out.');
        self::assertNotContains(75, $uids, 'A disabled member stays out.');
    }

    #[Test]
    public function theHolderHimselfIsNotInTheAudienceWhenHeIsNoMember(): void
    {
        self::assertNotContains(70, $this->audienceOf(70)->beUserUids);
    }

    #[Test]
    public function grantAndAssignmentReachAHolderThroughAnIncludedGroup(): void
    {
        // nested-nick (82) is in a group that includes Head editors.
        self::assertSame([71, 73], $this->audienceOf(82)->beUserUids);
    }

    #[Test]
    public function theAssignmentIsTheUnionOverAllTheHoldersGroups(): void
    {
        // union-uwe (79): the grant comes from 57, the assignment (Sports
        // team) from 58, which carries no grant itself.
        $audience = $this->audienceOf(79);

        self::assertTrue($audience->permitted);
        self::assertSame([72, 77], $audience->beUserUids);
    }

    #[Test]
    public function aHolderWithoutAnAssignmentSeesNobody(): void
    {
        $audience = $this->audienceOf(78);

        self::assertTrue($audience->permitted);
        self::assertFalse($audience->everyone);
        self::assertSame([], $audience->beUserUids);
        self::assertSame([], $audience->assignedGroups);
    }

    #[Test]
    public function anAssignedGroupWithoutMembersShowsNobody(): void
    {
        $audience = $this->audienceOf(80);

        self::assertTrue($audience->permitted);
        self::assertSame([], $audience->beUserUids);
        self::assertSame(['Empty team'], array_column($audience->assignedGroups, 'title'));
    }

    #[Test]
    public function aUserWithoutTheGrantIsDenied(): void
    {
        foreach ([77, 83] as $uid) {
            $audience = $this->audienceOf($uid);

            self::assertFalse($audience->permitted, (string) $uid);
            self::assertFalse($audience->everyone, (string) $uid);
            self::assertSame([], $audience->beUserUids, (string) $uid);
        }
    }

    #[Test]
    public function anAdministratorSeesEveryone(): void
    {
        $audience = $this->audienceOf(1);

        self::assertTrue($audience->permitted);
        self::assertTrue($audience->everyone);
    }

    #[Test]
    public function noBackendUserIsDenied(): void
    {
        $audience = $this->resolver()->forUser(null);

        self::assertFalse($audience->permitted);
        self::assertFalse($audience->everyone);
    }

    private function audienceOf(int $uid): UsageAudience
    {
        return $this->resolver()->forUser($this->setUpBackendUser($uid));
    }

    private function resolver(): UsageAudienceResolver
    {
        return $this->getService(UsageAudienceResolver::class);
    }
}
