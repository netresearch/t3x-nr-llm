<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures;

use ArrayObject;
use Closure;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\RefusesBeforeApprovalInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;

/**
 * A declared write that may refuse a call before the approval and has no
 * preview (ADR-328).
 */
final readonly class RefusingWithoutPreviewTool implements ToolInterface, ToolEffectInterface, RefusesBeforeApprovalInterface
{
    /**
     * @param Closure(): bool          $refuses
     * @param ArrayObject<int, string> $writes
     */
    public function __construct(
        private string $name,
        private Closure $refuses,
        private ArrayObject $writes,
    ) {}

    public function refusalBeforeApproval(array $arguments, ToolExecutionContext $context): ?ToolResult
    {
        return ($this->refuses)() ? ToolResult::error(RefusingWriteTool::REFUSAL) : null;
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $refusal = $this->refusalBeforeApproval($arguments, $context);
        if ($refusal instanceof ToolResult) {
            return $refusal;
        }

        $id             = $arguments['id'] ?? '';
        $this->writes[] = is_string($id) ? $id : '';

        return ToolResult::text('WROTE');
    }

    public function getEffect(): ToolEffect
    {
        return ToolEffect::IDEMPOTENT_WRITE;
    }

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function($this->name, 'refusing ' . $this->name, ['type' => 'object', 'properties' => []]);
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
