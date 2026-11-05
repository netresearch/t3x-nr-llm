<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures;

use ArrayObject;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\RefusesBeforeApprovalInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use RuntimeException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * A declared write that may refuse a call before the approval (ADR-262), as a
 * redirect writer does in a draft workspace: `execute()` asks the same method
 * first, the preview shows the refusal. Each write that happens is counted.
 */
final readonly class RefusingWriteTool implements ToolInterface, ToolEffectInterface, ToolPreviewInterface, RefusesBeforeApprovalInterface
{
    public const REFUSAL = 'Refused: no draft of this table.';

    /**
     * @param ArrayObject<int, string> $writes the calls that wrote, by their argument `id`
     */
    public function __construct(
        private string $name,
        private bool $refuses,
        private ArrayObject $writes,
        private bool $throws = false,
    ) {}

    public function refusalBeforeApproval(array $arguments, ToolExecutionContext $context): ?ToolResult
    {
        if ($this->throws) {
            throw new RuntimeException('cannot tell', 1799262001);
        }

        return $this->refuses ? ToolResult::error(self::REFUSAL) : null;
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

    public function previewCall(array $arguments, ToolExecutionContext $context): array
    {
        return $this->refusalBeforeApproval($arguments, $context) instanceof ToolResult
            ? ['would be refused']
            : ['would write'];
    }

    public function mayViewerReadPreview(array $arguments, BackendUserAuthentication $viewer): bool
    {
        return true;
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
