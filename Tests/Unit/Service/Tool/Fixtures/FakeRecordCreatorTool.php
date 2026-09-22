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
use Netresearch\NrLlm\Service\Tool\RecordCreatorInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use RuntimeException;

/**
 * A writing tool that declares the tables it creates records in (ADR-197).
 *
 * `$tables === null` makes the declaration throw, `$specThrows` makes
 * `getSpec()` throw as well — the two failures the generic creator must turn
 * into a refusal rather than an exception or a skipped declaration.
 */
final readonly class FakeRecordCreatorTool implements ToolInterface, ToolEffectInterface, RecordCreatorInterface
{
    /**
     * @param list<non-empty-string>|null $tables
     */
    public function __construct(
        private string $name = 'fake_creator',
        private ?array $tables = [],
        private bool $specThrows = false,
    ) {}

    public function getCreatedTables(): array
    {
        if ($this->tables === null) {
            throw new RuntimeException('declaration unreadable', 1790000001);
        }

        return $this->tables;
    }

    public function getEffect(): ToolEffect
    {
        return ToolEffect::NON_IDEMPOTENT_WRITE;
    }

    public function getSpec(): ToolSpec
    {
        if ($this->specThrows) {
            throw new RuntimeException('spec unreadable', 1790000002);
        }

        return ToolSpec::function(
            $this->name,
            'model-facing description of ' . $this->name,
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
        return false;
    }

    public function requiresAdmin(): bool
    {
        return false;
    }

    public function getGroup(): string
    {
        return 'editing';
    }
}
