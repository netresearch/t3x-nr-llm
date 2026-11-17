<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\A2a\Runtime;

use Netresearch\NrLlm\Service\Agent\A2a\Runtime\A2aDelegationGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Spec 051 A2A-R2: the per-installation rule for nr_mcp_agent.
 */
#[CoversClass(A2aDelegationGate::class)]
final class A2aDelegationGateTest extends TestCase
{
    #[Test]
    public function withoutNrMcpAgentTheToolsAreOffered(): void
    {
        self::assertNull(A2aDelegationGate::refusalFor(null, null));
        self::assertNull((new A2aDelegationGate(null, static fn (): ?string => null))->refusal());
    }

    /**
     * nr_mcp_agent 0.39.0 follows a run that waits for a remote agent and
     * shows its kept answer; every earlier version marks the chat failed.
     */
    #[Test]
    public function theFirstReleaseThatHandlesTheWaitOpensTheGate(): void
    {
        self::assertSame('0.39.0', A2aDelegationGate::FIRST_COMPATIBLE_MCP_AGENT);
        self::assertNull((new A2aDelegationGate(null, static fn (): string => '0.39.0'))->refusal());
        self::assertNull((new A2aDelegationGate(null, static fn (): string => '0.40.1'))->refusal());
        self::assertStringContainsString('from nr_mcp_agent 0.39.0 on', (string) (new A2aDelegationGate(null, static fn (): string => '0.38.5'))->refusal());
        self::assertNotNull((new A2aDelegationGate(null, static fn (): string => ''))->refusal());
    }

    /**
     * The shapes TYPO3 reports: 13.4 with Composer keeps Composer's pretty
     * version (`v0.39.0`), 14.3 in classic mode appends the state from
     * ext_emconf.php (`0.39.0-alpha`). Both are the release 0.39.0.
     */
    #[Test]
    public function theVersionIsComparedAsTheReleaseTypo3Reports(): void
    {
        self::assertNull(A2aDelegationGate::refusalFor('v0.39.0', '0.39.0'));
        self::assertNull(A2aDelegationGate::refusalFor('0.39.0-alpha', '0.39.0'));
        self::assertNull(A2aDelegationGate::refusalFor('V1.0.0', '0.39.0'));
        self::assertNotNull(A2aDelegationGate::refusalFor('v0.38.5', '0.39.0'));
        self::assertNotNull(A2aDelegationGate::refusalFor('0.38.5-alpha', '0.39.0'));
    }

    /**
     * A branch checkout names no release, so the gate stays closed: failing
     * closed is the rule for every version it cannot read.
     */
    #[Test]
    public function aBranchCheckoutKeepsTheGateClosed(): void
    {
        self::assertNotNull(A2aDelegationGate::refusalFor('dev-main', '0.39.0'));
    }

    #[Test]
    public function withoutACompatibleReleaseEveryLoadedVersionWithholdsThem(): void
    {
        self::assertStringContainsString('no nr_mcp_agent release does yet', (string) A2aDelegationGate::refusalFor('9.9.9', null));
    }

    #[Test]
    public function aCompatibleVersionOpensTheGateAndAnOlderOrUnknownOneDoesNot(): void
    {
        self::assertNull(A2aDelegationGate::refusalFor('2.5.0', '2.5.0'));
        self::assertNull(A2aDelegationGate::refusalFor('2.6.1', '2.5.0'));
        self::assertStringContainsString('from nr_mcp_agent 2.5.0 on', (string) A2aDelegationGate::refusalFor('2.4.9', '2.5.0'));
        self::assertNotNull(A2aDelegationGate::refusalFor('', '2.5.0'));
    }
}
