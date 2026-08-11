<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use LogicException;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\RemoteApprovalInterface;
use Netresearch\NrLlm\Service\Tool\RemoteToolInterface;
use Netresearch\NrLlm\Service\Tool\RequiresApprovalInterface;
use Netresearch\NrLlm\Service\Tool\RequiresInputInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolRegistry::class)]
final class ToolRegistryTest extends TestCase
{
    #[Test]
    public function collectsAndLooksUpByName(): void
    {
        $a = new FakeTool('alpha');
        $b = new FakeTool('beta');
        $r = new ToolRegistry([$a, $b]);

        self::assertSame($a, $r->get('alpha'));
        self::assertNull($r->get('missing'));
        self::assertSame(['alpha', 'beta'], $r->names());
    }

    #[Test]
    public function specsReturnsAllOrFilteredByAllowList(): void
    {
        $r = new ToolRegistry([new FakeTool('alpha'), new FakeTool('beta')]);

        self::assertSame(['alpha', 'beta'], array_map(static fn(ToolSpec $s): string => $s->name, $r->specs()));
        self::assertSame(['beta'], array_map(static fn(ToolSpec $s): string => $s->name, $r->specs(['beta'])));
        self::assertSame([], $r->specs(['unknown'])); // unknown declared names dropped
        self::assertSame([], $r->specs([]));          // explicit empty allow-list => no tools
    }

    #[Test]
    public function duplicateToolNameThrows(): void
    {
        $this->expectException(LogicException::class);
        self::assertInstanceOf(ToolRegistry::class, new ToolRegistry([new FakeTool('dup'), new FakeTool('dup')]));
    }

    #[Test]
    public function aToolThatIsBothApprovalAndInputGatedIsRejected(): void
    {
        // ADR-105 M1: the combination is unsupported — the approval-resume path
        // carries no user input and would silently drop the mandatory data. The
        // code pins WHICH check answers: the explicit marker is the shared
        // rule's first branch, so the one condition below covers it too and the
        // inline marker check the registry used to carry is gone (ADR-157).
        $dualMarker = new class implements ToolInterface, RequiresApprovalInterface, RequiresInputInterface {
            public function getSpec(): ToolSpec
            {
                return ToolSpec::function('dual', 'both markers', ['type' => 'object', 'properties' => []]);
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

            /**
             * @return array<string, mixed>
             */
            public function getInputSchema(): array
            {
                return ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]];
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1786226400);
        self::assertInstanceOf(ToolRegistry::class, new ToolRegistry([$dualMarker]));
    }

    #[Test]
    public function aToolThatDeclaresAWriteAndRequiresInputIsRejected(): void
    {
        // ADR-134: a declared write binds the approval scan, which runs BEFORE
        // the input scan. Registered, such a tool would suspend for approval,
        // be refused by resume() for the input it never received, and suspend
        // again on the model's next attempt — one operator decision per cycle
        // and never an execution. Rejected at the container boot instead.
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1786226400);
        self::assertInstanceOf(ToolRegistry::class, new ToolRegistry([$this->inputTool('create_page', ToolEffect::IDEMPOTENT_WRITE)]));
    }

    #[Test]
    public function anInputToolThatDeclaresNoWriteStaysRegistrable(): void
    {
        // The control for the ban above: it keys on the WRITE, not on the input
        // marker. A read-only tool that asks the user for data is the case
        // ADR-105 was built for and must keep working.
        $tool = $this->inputTool('ask_user', ToolEffect::READ_ONLY);

        self::assertSame($tool, (new ToolRegistry([$tool]))->get('ask_user'));
    }

    #[Test]
    public function aRemoteToolThatDeclaresAWriteAndRequiresInputStaysRegistrable(): void
    {
        // The ban asks ToolApprovalRule, which exempts a remote tool carrying no
        // approval declaration, so it can never reject a tool the approval scan
        // would let through. McpTool declares NON_IDEMPOTENT_WRITE for every
        // imported tool, a pure search included (ADR-134).
        $remote = new class implements ToolInterface, RequiresInputInterface, ToolEffectInterface, RemoteToolInterface {
            public function getSpec(): ToolSpec
            {
                return ToolSpec::function('remote_writer', 'a remote tool that asks for input', ['type' => 'object', 'properties' => []]);
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

            /**
             * @return array<string, mixed>
             */
            public function getInputSchema(): array
            {
                return ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]];
            }

            public function getEffect(): ToolEffect
            {
                return ToolEffect::NON_IDEMPOTENT_WRITE;
            }
        };

        self::assertSame($remote, (new ToolRegistry([$remote]))->get('remote_writer'));
    }

    #[Test]
    public function aRemoteToolThatDECLARESApprovalAndRequiresInputIsRejected(): void
    {
        // The gap the shared rule closed (ADR-157). The old inline copy exempted
        // every remote tool, so this one stayed registrable while the loop's
        // scan — which honours RemoteApprovalInterface — would suspend it for
        // approval and never deliver its input. Both now ask ToolApprovalRule.
        $remote = new class implements ToolInterface, RequiresInputInterface, ToolEffectInterface, RemoteApprovalInterface {
            public function getSpec(): ToolSpec
            {
                return ToolSpec::function('remote_declaring', 'a remote tool the operator marked as needing approval', ['type' => 'object', 'properties' => []]);
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

            /**
             * @return array<string, mixed>
             */
            public function getInputSchema(): array
            {
                return ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]];
            }

            public function getEffect(): ToolEffect
            {
                return ToolEffect::NON_IDEMPOTENT_WRITE;
            }

            public function requiresApproval(): bool
            {
                return true;
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1786226400);
        self::assertInstanceOf(ToolRegistry::class, new ToolRegistry([$remote]));
    }

    /**
     * A local tool that asks the user for typed input and declares an effect.
     */
    private function inputTool(string $name, ToolEffect $effect): ToolInterface
    {
        return new class ($name, $effect) implements ToolInterface, RequiresInputInterface, ToolEffectInterface {
            public function __construct(
                private readonly string $name,
                private readonly ToolEffect $effect,
            ) {}

            public function getSpec(): ToolSpec
            {
                return ToolSpec::function($this->name, 'asks the user for input', ['type' => 'object', 'properties' => []]);
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

            /**
             * @return array<string, mixed>
             */
            public function getInputSchema(): array
            {
                return ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]];
            }

            public function getEffect(): ToolEffect
            {
                return $this->effect;
            }
        };
    }
}
