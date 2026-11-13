<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\RemoteApprovalInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;

/**
 * A remote tool that declares a write and needs approval, as an MCP tool does:
 * it changes things outside TYPO3's workspaces.
 */
final readonly class FakeRemoteWriteTool implements ToolInterface, RemoteApprovalInterface, ToolEffectInterface
{
    public function __construct(private string $name) {}

    public function getEffect(): ToolEffect
    {
        return ToolEffect::NON_IDEMPOTENT_WRITE;
    }

    public function requiresApproval(): bool
    {
        return true;
    }

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function($this->name, 'remote ' . $this->name, ['type' => 'object', 'properties' => []]);
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
        return 'system';
    }
}
