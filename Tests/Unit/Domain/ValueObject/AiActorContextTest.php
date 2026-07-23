<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
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
}
