<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\RemoteToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;

/**
 * A tool whose behaviour lives on a remote server, as an MCP tool does.
 *
 * The marker is the whole point: the trust-zone ceiling is enforced for a
 * {@see RemoteToolInterface} tool even where the install runs the gate in
 * observe mode (ADR-115). Unlike the real
 * {@see \Netresearch\NrLlm\Service\Tool\Mcp\McpTool} this double does not
 * require an admin, so a test can reach the zone branch without carrying a
 * backend user it is not testing.
 */
final readonly class FakeRemoteTool implements ToolInterface, RemoteToolInterface
{
    public function __construct(
        private string $name,
        private string $group = 'system',
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
        return $this->group;
    }
}
