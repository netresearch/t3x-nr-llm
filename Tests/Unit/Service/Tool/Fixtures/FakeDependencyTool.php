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
use Netresearch\NrLlm\Service\Tool\DependencyCheckpoint;
use Netresearch\NrLlm\Service\Tool\DependencyToolInterface;
use Netresearch\NrLlm\Service\Tool\Exception\ToolDependencyPendingException;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;

/**
 * A tool that hands its call to an external operation that has not
 * finished (ADR-281): every call suspends the run for a dependency.
 */
final readonly class FakeDependencyTool implements DependencyToolInterface, ToolEffectInterface
{
    public function __construct(private string $name = 'delegate') {}

    public function getEffect(): ToolEffect
    {
        return ToolEffect::READ_ONLY;
    }

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function($this->name, 'desc of ' . $this->name, ['type' => 'object', 'properties' => []]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        throw new ToolDependencyPendingException(
            new DependencyCheckpoint(DependencyCheckpoint::KIND_A2A_TASK, '', '', 1, 1, 'task-1', 1, 0, 0, 600, 1, 0),
            'handed to a remote agent',
        );
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
