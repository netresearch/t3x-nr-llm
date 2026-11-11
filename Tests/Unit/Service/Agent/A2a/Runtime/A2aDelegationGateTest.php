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

    #[Test]
    public function everyLoadedVersionWithholdsThemWhileNoReleaseIsCompatible(): void
    {
        self::assertNull(A2aDelegationGate::FIRST_COMPATIBLE_MCP_AGENT);
        self::assertStringContainsString('no nr_mcp_agent release does yet', (string) A2aDelegationGate::refusalFor('9.9.9', null));
        self::assertNotNull((new A2aDelegationGate(null, static fn (): string => '1.0.0'))->refusal());
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
