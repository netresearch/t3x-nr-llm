<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\BackendUserGrant;
use Netresearch\NrLlm\Domain\Enum\ServiceAccountScope;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\AiSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AiActorContext::class)]
final class AiActorContextTest extends TestCase
{
    #[Test]
    public function aBackendUserActorSurvivesTheQueueRoundTrip(): void
    {
        // ADR-083: the full identity — uid, admin flag AND group uids — must
        // survive serialisation, so a worker restores it instead of collapsing
        // to a bare backend-user id.
        $actor = AiActorContext::backendUser(9, isAdmin: true, backendGroupIds: [3, 4]);

        $restored = AiActorContext::fromArray($actor->toArray());

        self::assertSame(9, $restored->backendUserUid);
        self::assertTrue($restored->isAdmin);
        self::assertSame([3, 4], $restored->backendGroupIds);
        self::assertNull($restored->serviceAccount);
    }

    #[Test]
    public function aServiceAccountAndAnAnonymousActorSurviveTheRoundTrip(): void
    {
        $service = AiActorContext::fromArray(AiActorContext::serviceAccount('nightly-import')->toArray());
        self::assertTrue($service->isServiceAccount());
        self::assertSame('nightly-import', $service->serviceAccount);

        $anon = AiActorContext::fromArray(AiActorContext::anonymous()->toArray());
        self::assertFalse($anon->isAuthenticated());
        self::assertSame(0, $anon->backendUserUid);
    }

    #[Test]
    public function fromArrayFailsClosedAndNeverInventsPrivilege(): void
    {
        // A malformed / truncated row must never rebuild a MORE privileged actor
        // than was stored: unknown or wrong-typed fields degrade to the
        // least-privileged default.
        $actor = AiActorContext::fromArray([
            'backendUserUid'  => 'not-an-int',   // -> 0
            'isAdmin'         => '1',            // not true -> false
            'backendGroupIds' => [5, 'x', 6],    // non-ints filtered out
            'serviceAccount'  => '',            // empty -> null
        ]);

        self::assertSame(0, $actor->backendUserUid);
        self::assertFalse($actor->isAdmin);
        self::assertSame([5, 6], $actor->backendGroupIds);
        self::assertNull($actor->serviceAccount);
        self::assertFalse($actor->isAuthenticated());
    }

    #[Test]
    public function fromArrayOnAnEmptyRowIsAnonymous(): void
    {
        $actor = AiActorContext::fromArray([]);

        self::assertFalse($actor->isAdmin);
        self::assertFalse($actor->isServiceAccount());
        self::assertSame(0, $actor->backendUserUid);
        self::assertSame([], $actor->backendGroupIds);
    }

    #[Test]
    public function aServiceAccountIsAuthorisedSolelyByItsDeclaredScopes(): void
    {
        // ADR-110: a service account owns nothing, so it may do only what its
        // scopes name — and a scopeless one may do nothing (fail-closed).
        $scoped = AiActorContext::serviceAccount('cancel-sweep', [ServiceAccountScope::AGENT_CANCEL]);
        self::assertTrue($scoped->hasScope(ServiceAccountScope::AGENT_CANCEL));
        self::assertFalse($scoped->hasScope(ServiceAccountScope::AGENT_APPROVE));

        $scopeless = AiActorContext::serviceAccount('no-grants');
        self::assertFalse($scopeless->hasScope(ServiceAccountScope::AGENT_CANCEL));
    }

    #[Test]
    public function scopesGovernServiceAccountsOnlyNeverBackendUsers(): void
    {
        // A backend user is authorised by ownership/admin, never by scopes:
        // hasScope() is always false for them so an entry point cannot be
        // tricked into treating an interactive caller as a scoped automation.
        $admin = AiActorContext::backendUser(9, isAdmin: true);
        self::assertFalse($admin->hasScope(ServiceAccountScope::CONFIGURATION_USE));
    }

    #[Test]
    public function serviceAccountScopesSurviveTheQueueRoundTrip(): void
    {
        $actor = AiActorContext::serviceAccount('nightly-import', [
            ServiceAccountScope::CONVERSATION_ACCESS,
            ServiceAccountScope::CONFIGURATION_USE,
        ]);

        $restored = AiActorContext::fromArray($actor->toArray());

        self::assertTrue($restored->hasScope(ServiceAccountScope::CONVERSATION_ACCESS));
        self::assertTrue($restored->hasScope(ServiceAccountScope::CONFIGURATION_USE));
        self::assertFalse($restored->hasScope(ServiceAccountScope::AGENT_CANCEL));
    }

    #[Test]
    public function fromArrayDropsUnknownScopeStrings(): void
    {
        // A tampered/older row must never yield a scope the process does not
        // define: unknown values are dropped, known ones survive (fail-closed).
        $actor = AiActorContext::fromArray([
            'serviceAccount' => 'partial',
            'scopes'         => ['agent:cancel', 'agent:root', 42, 'conversation:access'],
        ]);

        self::assertTrue($actor->hasScope(ServiceAccountScope::AGENT_CANCEL));
        self::assertTrue($actor->hasScope(ServiceAccountScope::CONVERSATION_ACCESS));
        self::assertFalse($actor->hasScope(ServiceAccountScope::AGENT_APPROVE));
    }

    #[Test]
    public function mayAccessSessionIsScopedForServiceAccountsAndOwnedForUsers(): void
    {
        $session = new AiSession(1, 'sess-uuid', 42, 'default', '', 0, 0, 0);

        self::assertTrue(AiActorContext::backendUser(42)->mayAccessSession($session), 'owner');
        self::assertFalse(AiActorContext::backendUser(7)->mayAccessSession($session), 'non-owner');
        self::assertTrue(AiActorContext::backendUser(7, isAdmin: true)->mayAccessSession($session), 'admin');
        self::assertTrue(
            AiActorContext::serviceAccount('w', [ServiceAccountScope::CONVERSATION_ACCESS])->mayAccessSession($session),
            'scoped service account',
        );
        self::assertFalse(
            AiActorContext::serviceAccount('w')->mayAccessSession($session),
            'scopeless service account is denied',
        );
    }

    #[Test]
    public function grantsSurviveTheQueueRoundTripAndUnknownValuesAreDropped(): void
    {
        $actor = AiActorContext::backendUser(9, grants: [BackendUserGrant::TASKS_USE]);

        $restored = AiActorContext::fromArray($actor->toArray());
        self::assertSame([BackendUserGrant::TASKS_USE], $restored->grants);

        // A tampered or future row can never yield a grant this process does
        // not define (fail-closed, same rule as scopes).
        $tampered = AiActorContext::fromArray([
            'backendUserUid' => 9,
            'grants'         => ['tasks_use', 'root', 42, null],
        ]);
        self::assertSame([BackendUserGrant::TASKS_USE], $tampered->grants);
    }

    #[Test]
    public function hasGrantIsImplicitForAdminsAndFailClosedOtherwise(): void
    {
        self::assertTrue(
            AiActorContext::backendUser(9, grants: [BackendUserGrant::TASKS_USE])->hasGrant(BackendUserGrant::TASKS_USE),
            'granted user',
        );
        self::assertFalse(
            AiActorContext::backendUser(9)->hasGrant(BackendUserGrant::TASKS_USE),
            'grantless user',
        );
        self::assertTrue(
            AiActorContext::backendUser(9, isAdmin: true)->hasGrant(BackendUserGrant::TASKS_USE),
            'admins hold every grant implicitly (ADR-130)',
        );
        self::assertFalse(
            AiActorContext::serviceAccount('w', [ServiceAccountScope::AGENT_APPROVE])->hasGrant(BackendUserGrant::AGENT_APPROVE),
            'grants never govern service accounts - their mechanism is scopes',
        );
        self::assertFalse(
            AiActorContext::anonymous()->hasGrant(BackendUserGrant::TASKS_USE),
            'anonymous caller',
        );
    }

    #[Test]
    public function theApproveGrantExtendsMayActOnRunToOtherUsersRunsOnly(): void
    {
        $someoneElsesRun = new AgentRun(1, 'uuid', 'waiting_for_approval', 0, '', 42, 0, false, 0, 0, 0, 0.0, '', '', 0, 0, 0, '{}');

        $approver = AiActorContext::backendUser(7, grants: [BackendUserGrant::AGENT_APPROVE]);

        // The human sibling of the AGENT_APPROVE scope (ADR-130): the granted
        // user may decide runs they do not own...
        self::assertTrue($approver->mayActOnRun($someoneElsesRun, ServiceAccountScope::AGENT_APPROVE));

        // ...but the grant covers ONLY approval - every other operation stays
        // owner-or-admin.
        self::assertFalse($approver->mayActOnRun($someoneElsesRun, ServiceAccountScope::AGENT_CANCEL));
        self::assertFalse($approver->mayActOnRun($someoneElsesRun, ServiceAccountScope::AGENT_READ));

        // And without the grant, a stranger stays denied.
        self::assertFalse(
            AiActorContext::backendUser(7)->mayActOnRun($someoneElsesRun, ServiceAccountScope::AGENT_APPROVE),
        );
    }
}
