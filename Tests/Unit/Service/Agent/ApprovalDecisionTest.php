<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent;

use Netresearch\NrLlm\Domain\Enum\ApprovalDenialReason;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Agent\ApprovalDecision;
use Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApprovalDecision::class)]
final class ApprovalDecisionTest extends TestCase
{
    /**
     * ADR-214: "apply" and "another variant" cannot both be the answer. A
     * reason beside an approval is refused at construction, for each case.
     */
    #[Test]
    public function anApprovalCarriesNoDenialReason(): void
    {
        foreach (ApprovalDenialReason::cases() as $reason) {
            try {
                new ApprovalDecision(true, 9, 'digest', $reason);
                self::fail('An approval accepted the reason ' . $reason->value . '.');
            } catch (InvalidArgumentException $e) {
                self::assertSame(1791600201, $e->getCode());
            }
        }
    }

    /**
     * The other direction: a denial carries any case or none, and an approval
     * without one is what every existing caller builds.
     */
    #[Test]
    public function aDenialCarriesAReasonOrNoneAndAnApprovalNone(): void
    {
        foreach (ApprovalDenialReason::cases() as $reason) {
            self::assertSame($reason, (new ApprovalDecision(false, 9, 'digest', $reason))->denialReason);
        }

        self::assertNull((new ApprovalDecision(false, 9, 'digest'))->denialReason);
        self::assertNull((new ApprovalDecision(true, 9, 'digest'))->denialReason);
        self::assertTrue((new ApprovalDecision(true, 9, 'digest'))->approved);
    }

    /**
     * The token the model reads is declared once on the @api loop interface,
     * and each case of the closed type has exactly one.
     */
    #[Test]
    public function everyReasonHasItsTokenOnTheLoopInterface(): void
    {
        self::assertSame(
            [ToolLoopServiceInterface::DENIAL_REASON_VARIANT, ToolLoopServiceInterface::DENIAL_REASON_SKIP],
            array_map(static fn(ApprovalDenialReason $reason): string => $reason->value, ApprovalDenialReason::cases()),
        );
    }
}
