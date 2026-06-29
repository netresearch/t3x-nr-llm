<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures;

use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ToolInterface;

/**
 * Minimal in-memory ToolInterface double used by the ToolRegistry unit tests.
 *
 * Carries a configurable name (the registry index key) and a canned result so
 * a test can assert both the lookup-by-name path and the execute() contract
 * without touching the database or a real provider.
 */
final readonly class FakeTool implements ToolInterface
{
    public function __construct(
        private string $name,
        private string $result = 'ok',
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
    public function execute(array $arguments): string
    {
        return $this->result;
    }
}
