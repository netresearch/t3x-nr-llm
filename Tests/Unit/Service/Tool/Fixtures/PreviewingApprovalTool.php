<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\RequiresApprovalInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use RuntimeException;

/**
 * A tool that pauses for approval AND offers a preview (ADR-136).
 *
 * Three shapes in one double, because all three are behaviour the loop must
 * handle: lines derived from the call's arguments (the normal case), an empty
 * preview, and a preview that throws. The thrown message is deliberately
 * distinctive so a test can assert it never reaches the persisted state.
 */
final readonly class PreviewingApprovalTool implements ToolInterface, RequiresApprovalInterface, ToolPreviewInterface
{
    /**
     * @param list<string>|null $lines null ⇒ derive one line from the arguments
     */
    public function __construct(
        private string $name,
        private ?array $lines = null,
        private bool $throw = false,
    ) {}

    public function previewCall(array $arguments, ToolExecutionContext $context): array
    {
        if ($this->throw) {
            throw new RuntimeException('boom with secret', 1784600136);
        }

        $uid = $arguments['uid'] ?? 0;

        return $this->lines ?? ['would touch page ' . (is_numeric($uid) ? (int)$uid : 0)];
    }

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function($this->name, 'previewable ' . $this->name, ['type' => 'object', 'properties' => []]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        return ToolResult::text('DONE');
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
