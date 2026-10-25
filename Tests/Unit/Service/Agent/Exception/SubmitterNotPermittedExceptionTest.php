<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\Exception;

use Netresearch\NrLlm\Domain\Enum\PendingCallRefusalCause;
use Netresearch\NrLlm\Domain\Enum\ToolDenialReason;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Agent\Exception\SubmitterNotPermittedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The refusal says WHY in a form a surface can act on, not only in the log
 * message: a switched-off tool and a submitter whose permissions fall short need
 * different sentences, and before the accessors both read "not permitted".
 */
#[CoversClass(SubmitterNotPermittedException::class)]
final class SubmitterNotPermittedExceptionTest extends TestCase
{
    #[Test]
    public function aDeniedToolCarriesItsCauseAndThePolicysReason(): void
    {
        $exception = SubmitterNotPermittedException::forDeniedTool('run-1', AiActorContext::backendUser(2), 'touch_thing', ToolDenialReason::TOOL_DISABLED);

        self::assertSame(PendingCallRefusalCause::DENIED_TOOL, $exception->getCause());
        self::assertSame(ToolDenialReason::TOOL_DISABLED, $exception->getDenialReason());
    }

    #[Test]
    public function eachPolicyReasonIsCarriedAsGiven(): void
    {
        // Not a constant: the reason passed in is the reason read back.
        foreach ([ToolDenialReason::REQUIRES_ADMIN, ToolDenialReason::CONFIGURATION_GROUP, ToolDenialReason::TRUST_ZONE] as $reason) {
            $exception = SubmitterNotPermittedException::forDeniedTool('run-1', AiActorContext::backendUser(2), 'touch_thing', $reason);

            self::assertSame($reason, $exception->getDenialReason());
        }
    }

    #[Test]
    public function aSubmitterWithoutPermissionsCarriesNoPolicyReason(): void
    {
        $exception = SubmitterNotPermittedException::forSubmitterWithoutPermissions('run-1', AiActorContext::backendUser(2), 'touch_thing');

        self::assertSame(PendingCallRefusalCause::NO_BACKEND_PERMISSIONS, $exception->getCause());
        self::assertNull($exception->getDenialReason(), 'the policy was never asked');
    }

    #[Test]
    public function anUnreadableCallCarriesNoPolicyReason(): void
    {
        $exception = SubmitterNotPermittedException::forUnreadableCall('run-1', AiActorContext::backendUser(2));

        self::assertSame(PendingCallRefusalCause::UNREADABLE_CALL, $exception->getCause());
        self::assertNull($exception->getDenialReason(), 'there was no tool to judge');
    }

    #[Test]
    public function thePlainConstructorStillBuildsTheExceptionWithoutACause(): void
    {
        // Callers outside this extension construct it directly (for example in
        // their own tests); that keeps working and reports no cause.
        $exception = new SubmitterNotPermittedException('run-1', 'denied');

        self::assertSame('run-1', $exception->runUuid);
        self::assertSame('denied', $exception->getMessage());
        self::assertNull($exception->getCause());
        self::assertNull($exception->getDenialReason());
    }
}
