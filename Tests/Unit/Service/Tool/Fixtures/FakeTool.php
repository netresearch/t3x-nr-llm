<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures;

use Netresearch\NrLlm\Domain\ValueObject\ToolArtifact;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;

/**
 * Minimal in-memory ToolInterface double used by the ToolRegistry unit tests.
 *
 * Carries a configurable name (the registry index key), a canned result and an
 * optional list of run-only artifacts so a test can assert both the
 * lookup-by-name path and the execute() contract (including the artifact
 * channel) without touching the database or a real provider.
 */
final readonly class FakeTool implements ToolInterface
{
    /**
     * @param list<ToolArtifact> $artifacts
     */
    public function __construct(
        private string $name,
        private string $result = 'ok',
        private bool $enabledByDefault = true,
        private bool $requiresAdmin = false,
        private string $group = 'test',
        private array $artifacts = [],
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            $this->name,
            'desc of ' . $this->name,
            ['type' => 'object', 'properties' => []],
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        return ToolResult::text($this->result, ...$this->artifacts);
    }

    public function isEnabledByDefault(): bool
    {
        return $this->enabledByDefault;
    }

    public function requiresAdmin(): bool
    {
        return $this->requiresAdmin;
    }

    public function getGroup(): string
    {
        return $this->group;
    }
}
