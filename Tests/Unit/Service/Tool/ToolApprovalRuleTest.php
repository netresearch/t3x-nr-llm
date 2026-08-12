<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\RemoteApprovalInterface;
use Netresearch\NrLlm\Service\Tool\RemoteToolInterface;
use Netresearch\NrLlm\Service\Tool\RequiresApprovalInterface;
use Netresearch\NrLlm\Service\Tool\ToolApprovalRule;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The one approval predicate (ADR-157).
 *
 * The rule in isolation. Its three consumers are covered where they act:
 * `ToolLoopServiceTest::suspendsForAWriteDeclaringBuiltinWithoutTheApprovalMarker()`
 * and its siblings for the loop's scan,
 * `ToolRegistryTest::aRemoteToolThatDECLARESApprovalAndRequiresInputIsRejected()`
 * for the boot validation, and `GovernanceTabRenderTest` for the simulation's
 * approval axis. Three callers, so the extraction is not a speculative
 * abstraction — it is the copy that had already drifted, removed.
 */
#[CoversClass(ToolApprovalRule::class)]
final class ToolApprovalRuleTest extends TestCase
{
    #[Test]
    public function anUnknownToolBindsNothing(): void
    {
        // The loop refuses an unregistered name outright; turning it into a
        // suspend would ask an operator to approve something that cannot run.
        self::assertFalse(ToolApprovalRule::requiresApproval(null));
    }

    #[Test]
    public function theExplicitMarkerBinds(): void
    {
        self::assertTrue(ToolApprovalRule::requiresApproval(
            new class extends ApprovalRuleFixtureTool implements RequiresApprovalInterface {},
        ));
    }

    #[Test]
    public function aDeclaredWriteBinds(): void
    {
        self::assertTrue(ToolApprovalRule::requiresApproval($this->localTool(ToolEffect::IDEMPOTENT_WRITE)));
    }

    #[Test]
    public function aReadOnlyToolDoesNot(): void
    {
        self::assertFalse(ToolApprovalRule::requiresApproval($this->localTool(ToolEffect::READ_ONLY)));
    }

    #[Test]
    public function aRemoteToolIsNotJudgedOnItsEffect(): void
    {
        // McpTool declares NON_IDEMPOTENT_WRITE for every imported tool, a pure
        // search included: the value is a fail-closed assumption about an
        // unknown body, not the tool's statement about itself (ADR-134).
        self::assertFalse(ToolApprovalRule::requiresApproval($this->remoteTool(ToolEffect::NON_IDEMPOTENT_WRITE)));
    }

    #[Test]
    public function aRemoteDeclarationIsHonouredInBothDirections(): void
    {
        self::assertTrue(ToolApprovalRule::requiresApproval($this->remoteDeclaringTool(true)));
        self::assertFalse(ToolApprovalRule::requiresApproval($this->remoteDeclaringTool(false)));
    }

    private function localTool(ToolEffect $effect): ToolInterface
    {
        return new class ($effect) extends ApprovalRuleFixtureTool implements ToolEffectInterface {
            public function __construct(private readonly ToolEffect $effect) {}

            public function getEffect(): ToolEffect
            {
                return $this->effect;
            }
        };
    }

    private function remoteTool(ToolEffect $effect): ToolInterface
    {
        return new class ($effect) extends ApprovalRuleFixtureTool implements ToolEffectInterface, RemoteToolInterface {
            public function __construct(private readonly ToolEffect $effect) {}

            public function getEffect(): ToolEffect
            {
                return $this->effect;
            }
        };
    }

    private function remoteDeclaringTool(bool $declared): ToolInterface
    {
        return new class ($declared) extends ApprovalRuleFixtureTool implements ToolEffectInterface, RemoteApprovalInterface {
            public function __construct(private readonly bool $declared) {}

            public function getEffect(): ToolEffect
            {
                // Always a write, as McpTool declares: the point is that the
                // operator's declaration decides instead.
                return ToolEffect::NON_IDEMPOTENT_WRITE;
            }

            public function requiresApproval(): bool
            {
                return $this->declared;
            }
        };
    }
}

/**
 * The boilerplate half of a tool, so each fixture above states only the part
 * the rule reads.
 *
 * @internal
 */
abstract class ApprovalRuleFixtureTool implements ToolInterface
{
    public function getSpec(): ToolSpec
    {
        return ToolSpec::function('fixture', 'a tool', ['type' => 'object', 'properties' => []]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        return ToolResult::text('ok');
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function requiresAdmin(): bool
    {
        return false;
    }

    public function getGroup(): string
    {
        return 'test';
    }
}
