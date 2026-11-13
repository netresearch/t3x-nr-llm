<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Domain\Enum\BackendUserGrant;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aInboundClient;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSelfServiceLimits;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSelfServicePolicy;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolverInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * What the policy withdraws, narrows and refuses (ADR-294), each limit in both
 * directions: the allowed record passes, the forbidden one does not.
 */
#[CoversClass(A2aSelfServicePolicy::class)]
final class A2aSelfServicePolicyTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private const DAY = 86400;

    /**
     * @param array<string, mixed> $settings
     */
    private static function owner(array $settings = [], bool $grant = true, bool $admin = false, int $workspace = 3): BackendUserAuthentication
    {
        $user             = new BackendUserAuthentication();
        $user->user       = ['uid' => 20, 'admin' => $admin ? 1 : 0];
        $user->workspace  = $workspace;
        $user->userGroups = [['uid' => 30, 'custom_options' => $grant ? BackendUserGrant::A2A_TOKEN_SELF_SERVICE->permissionValue() : ''] + $settings];

        return $user;
    }

    private static function policy(?BackendUserAuthentication $owner): A2aSelfServicePolicy
    {
        return new A2aSelfServicePolicy(new class ($owner) implements ActingBackendUserResolverInterface {
            public int $calls = 0;

            public function __construct(private readonly ?BackendUserAuthentication $user) {}

            public function resolve(AiActorContext $actor): ?BackendUserAuthentication
            {
                ++$this->calls;

                return $this->user;
            }
        });
    }

    /**
     * @param list<int> $profiles
     */
    private static function client(bool $selfService = true, int $workspace = 3, int $expires = self::NOW + 30 * self::DAY, array $profiles = [1, 2]): A2aInboundClient
    {
        return new A2aInboundClient(5, 'Agent', 'p', 'h', 20, $workspace, $profiles, 4, true, $expires, $selfService, self::NOW);
    }

    /**
     * @return array<string, mixed>
     */
    private static function relaxed(): array
    {
        return [A2aSelfServiceLimits::FIELD_PROFILES => '1,2'];
    }

    #[Test]
    public function aRecordWithinTheLimitsPassesUnchanged(): void
    {
        $client = self::client();

        self::assertEquals($client, self::policy(self::owner(self::relaxed()))->constrain($client, self::NOW));
    }

    #[Test]
    public function aTechnicalUserRecordIsNeverTouchedAndNeverAsksForTheOwner(): void
    {
        $resolver = new class implements ActingBackendUserResolverInterface {
            public int $calls = 0;

            public function resolve(AiActorContext $actor): ?BackendUserAuthentication
            {
                ++$this->calls;

                return null;
            }
        };
        $client = self::client(false, 0, 0, [9]);

        self::assertSame($client, (new A2aSelfServicePolicy($resolver))->constrain($client, self::NOW));
        self::assertSame(0, $resolver->calls);
    }

    #[Test]
    public function aRecordWhoseOwnerLostThePermissionIsWithdrawn(): void
    {
        $withdrawn = self::policy(self::owner(self::relaxed(), false))->constrain(self::client(), self::NOW);

        self::assertFalse($withdrawn->isActive(self::NOW));
        self::assertNotNull($withdrawn->withdrawnReason);
    }

    #[Test]
    public function aRecordWhoseOwnerBecameAnAdministratorOrCannotBeFoundIsWithdrawn(): void
    {
        self::assertNotNull(self::policy(self::owner(self::relaxed(), true, true))->constrain(self::client(), self::NOW)->withdrawnReason);
        self::assertNotNull(self::policy(null)->constrain(self::client(), self::NOW)->withdrawnReason);
    }

    #[Test]
    public function theOwnerMustResolveInTheExactPinnedWorkspace(): void
    {
        self::assertNotNull(self::policy(self::owner(self::relaxed(), true, false, 4))->constrain(self::client(true, 3), self::NOW)->withdrawnReason);
        self::assertNull(self::policy(self::owner(self::relaxed(), true, false, 3))->constrain(self::client(true, 3), self::NOW)->withdrawnReason);
    }

    #[Test]
    public function liveIsAllowedOnlyByAGroupThatSaysSo(): void
    {
        $live = self::client(true, 0);

        self::assertNotNull(self::policy(self::owner(self::relaxed(), true, false, 0))->constrain($live, self::NOW)->withdrawnReason);
        self::assertNull(self::policy(self::owner(self::relaxed() + [A2aSelfServiceLimits::FIELD_ALLOW_LIVE => 1], true, false, 0))->constrain($live, self::NOW)->withdrawnReason);
    }

    #[Test]
    public function anExpiryBeyondTheMaximumWithdrawsTheRecordAndWithinItDoesNot(): void
    {
        $far = self::client(true, 3, self::NOW + 91 * self::DAY);

        self::assertNotNull(self::policy(self::owner(self::relaxed()))->constrain($far, self::NOW)->withdrawnReason);
        self::assertNull(self::policy(self::owner(self::relaxed() + [A2aSelfServiceLimits::FIELD_MAX_EXPIRY_DAYS => 120]))->constrain($far, self::NOW)->withdrawnReason);
    }

    #[Test]
    public function aRecordWithoutExpiryNeedsAGroupThatAllowsIt(): void
    {
        $never = self::client(true, 3, 0);

        self::assertNotNull(self::policy(self::owner(self::relaxed()))->constrain($never, self::NOW)->withdrawnReason);
        self::assertNull(self::policy(self::owner(self::relaxed() + [A2aSelfServiceLimits::FIELD_ALLOW_NO_EXPIRY => 1]))->constrain($never, self::NOW)->withdrawnReason);
    }

    #[Test]
    public function anExpiredRecordIsInactiveForThatReasonAlone(): void
    {
        $expired = self::policy(self::owner(self::relaxed()))->constrain(self::client(true, 3, self::NOW - 1), self::NOW);

        self::assertNull($expired->withdrawnReason);
        self::assertFalse($expired->isActive(self::NOW));
    }

    #[Test]
    public function theProfilesTheGroupsNoLongerAllowAreTakenOutOfTheRecord(): void
    {
        $narrowed = self::policy(self::owner([A2aSelfServiceLimits::FIELD_PROFILES => '2,7']))->constrain(self::client(true, 3, self::NOW + 30 * self::DAY, [1, 2, 3]), self::NOW);

        self::assertSame([2], $narrowed->profileUids);
        self::assertTrue($narrowed->mayUse(2));
        self::assertFalse($narrowed->mayUse(1));
        self::assertTrue($narrowed->isActive(self::NOW));
    }

    #[Test]
    public function theWriterRefusesWhatTheRequestWouldWithdrawOrCut(): void
    {
        $policy = self::policy(self::owner(self::relaxed()));

        self::assertNull($policy->refusal(self::client(), self::NOW));
        self::assertNotNull($policy->refusal(self::client(true, 3, self::NOW + 91 * self::DAY), self::NOW), 'expiry');
        self::assertNotNull($policy->refusal(self::client(true, 3, 0), self::NOW), 'no expiry');
        self::assertNotNull($policy->refusal(self::client(true, 0), self::NOW), 'live');
        self::assertNotNull($policy->refusal(self::client(true, 3, self::NOW + 30 * self::DAY, [1, 9]), self::NOW), 'a profile that is not allowed is refused, not cut');
        self::assertNotNull($policy->refusal(self::client(true, 3, self::NOW + 30 * self::DAY, []), self::NOW), 'at least one profile');
    }
}
