<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures;

use LogicException;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\ValueObject\PendingWriteTarget;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\PendingTargetInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;

/**
 * A write tool that names the pending target of a call from its `uid` and
 * `fields` arguments (ADR-214), or throws there when told to — the contract
 * breach the inbox must survive.
 */
final readonly class TargetNamingWriteTool implements ToolInterface, ToolEffectInterface, PendingTargetInterface
{
    public function __construct(
        private string $name = 'set_title',
        private bool $throwOnTarget = false,
    ) {}

    public function pendingTarget(array $arguments): ?PendingWriteTarget
    {
        if ($this->throwOnTarget) {
            throw new LogicException('A pending target that reads what it must not.', 1791600999);
        }

        $fields = $arguments['fields'] ?? [];

        return PendingWriteTarget::fromArguments('pages', $arguments['uid'] ?? null, is_array($fields) ? array_values($fields) : []);
    }

    public function getEffect(): ToolEffect
    {
        return ToolEffect::IDEMPOTENT_WRITE;
    }

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function($this->name, 'desc', ['type' => 'object', 'properties' => []]);
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        return ToolResult::text('written');
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
