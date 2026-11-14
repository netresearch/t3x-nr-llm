<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures;

use Closure;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\DependencyToolInterface;
use Netresearch\NrLlm\Service\Tool\Scope\ScopedWrite;
use Netresearch\NrLlm\Service\Tool\Scope\ScopedWriteInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;

/**
 * A dependency tool (one that may hand its work to an external operation) whose claim is whatever the test says, built from the call's
 * arguments, and whose execution is whatever the test says.
 */
final readonly class ScopedWriteFakeDependencyTool implements DependencyToolInterface, ToolEffectInterface, ScopedWriteInterface
{
    /**
     * @param Closure(array<string, mixed>): ?ScopedWrite                          $claim
     * @param Closure(array<string, mixed>, ToolExecutionContext): ToolResult|null $run
     */
    public function __construct(
        private string $name,
        private Closure $claim,
        private ?Closure $run = null,
    ) {}

    public function getEffect(): ToolEffect
    {
        return ToolEffect::IDEMPOTENT_WRITE;
    }

    public function scopedWrite(array $arguments): ?ScopedWrite
    {
        return ($this->claim)($arguments);
    }

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function($this->name, 'scoped write ' . $this->name, ['type' => 'object', 'properties' => []]);
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        return $this->run instanceof Closure ? ($this->run)($arguments, $context) : ToolResult::text('ok');
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
