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
use Netresearch\NrLlm\Service\Tool\RequiresApprovalInterface;
use Netresearch\NrLlm\Service\Tool\RequiresInputInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\UnattendedToolFilter;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnattendedToolFilter::class)]
final class UnattendedToolFilterTest extends TestCase
{
    #[Test]
    public function aReadOnlyToolRunsUnattended(): void
    {
        self::assertSame(['read'], $this->filter()->unattended(['read']));
    }

    #[Test]
    public function aToolWithADeclaredWriteIsLeftOut(): void
    {
        self::assertSame([], $this->filter()->unattended(['write', 'idempotent-write']));
    }

    #[Test]
    public function aToolMarkedForApprovalIsLeftOut(): void
    {
        self::assertSame([], $this->filter()->unattended(['marked']));
    }

    #[Test]
    public function aToolAskingForInputIsLeftOut(): void
    {
        self::assertSame([], $this->filter()->unattended(['asks-input']));
    }

    #[Test]
    public function anUnknownNameIsLeftOut(): void
    {
        self::assertSame([], $this->filter()->unattended(['ghost']));
    }

    #[Test]
    public function theOrderOfTheGivenNamesIsKept(): void
    {
        self::assertSame(
            ['second-read', 'read'],
            $this->filter()->unattended(['second-read', 'write', 'ghost', 'asks-input', 'read', 'marked']),
        );
    }

    private function filter(): UnattendedToolFilter
    {
        return new UnattendedToolFilter(new ToolRegistry([
            new FakeTool('read'),
            new FakeTool('second-read'),
            new FakeTool('write', effect: ToolEffect::NON_IDEMPOTENT_WRITE),
            new FakeTool('idempotent-write', effect: ToolEffect::IDEMPOTENT_WRITE),
            new class implements ToolInterface, RequiresApprovalInterface {
                public function getSpec(): ToolSpec
                {
                    return ToolSpec::function('marked', 'a read that asks for approval', ['type' => 'object', 'properties' => []]);
                }

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
            },
            new class implements ToolInterface, RequiresInputInterface {
                public function getSpec(): ToolSpec
                {
                    return ToolSpec::function('asks-input', 'a read that asks for typed input', ['type' => 'object', 'properties' => []]);
                }

                public function getInputSchema(): array
                {
                    return ['type' => 'object', 'required' => ['date'], 'properties' => ['date' => ['type' => 'string']]];
                }

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
            },
        ]));
    }
}
