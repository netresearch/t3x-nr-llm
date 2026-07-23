<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

#[CoversClass(ToolExecutionContext::class)]
final class ToolExecutionContextTest extends TestCase
{
    #[Test]
    public function noneHasNoActingUserAndIsNotAdmin(): void
    {
        // The fail-closed context: a service account / anonymous run. A
        // user-scoped tool sees no acting user and must refuse.
        $context = ToolExecutionContext::none();

        self::assertNull($context->actingBackendUser());
        self::assertFalse($context->isAdmin());
        self::assertFalse($context->actor->isAuthenticated());
    }

    #[Test]
    public function fromBackendUserDerivesUidAdminAndGroups(): void
    {
        $user = self::createStub(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(true);
        $user->user           = ['uid' => 5, 'admin' => 1];
        $user->userGroupsUID  = [7, 8];

        $context = ToolExecutionContext::fromBackendUser($user);

        self::assertSame($user, $context->actingBackendUser());
        self::assertTrue($context->isAdmin());
        self::assertSame(5, $context->actor->backendUserUid);
        self::assertTrue($context->actor->isAdmin);
        self::assertSame([7, 8], $context->actor->backendGroupIds);
    }

    #[Test]
    public function fromBackendUserOfANonAdminIsNotAdmin(): void
    {
        $user = self::createStub(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(false);
        $user->user          = ['uid' => 9];
        $user->userGroupsUID = ['3', 'not-a-group', 0, 4];

        $context = ToolExecutionContext::fromBackendUser($user);

        self::assertFalse($context->isAdmin());
        self::assertSame(9, $context->actor->backendUserUid);
        // Non-numeric and non-positive group ids are dropped.
        self::assertSame([3, 4], $context->actor->backendGroupIds);
    }
}
