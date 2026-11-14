<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Scope;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Tool\Scope\EffectiveAssignmentScope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

#[CoversClass(EffectiveAssignmentScope::class)]
final class EffectiveAssignmentScopeTest extends TestCase
{
    #[Test]
    public function theWorkspaceIsTheOneTheRunRecordedElseTheOneOfItsUser(): void
    {
        $user            = new BackendUserAuthentication();
        $user->workspace = 3;

        self::assertSame(5, EffectiveAssignmentScope::workspaceOf(AiActorContext::backendUser(1, false, [], [], 5), $user));
        self::assertSame(0, EffectiveAssignmentScope::workspaceOf(AiActorContext::backendUser(1, false, [], [], 0), $user), 'a run that recorded live is live');
        self::assertSame(3, EffectiveAssignmentScope::workspaceOf(AiActorContext::backendUser(1), $user), "a run that recorded none takes its user's");
        self::assertSame(0, EffectiveAssignmentScope::workspaceOf(AiActorContext::backendUser(1), null));
    }
}
