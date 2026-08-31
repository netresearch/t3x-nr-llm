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
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * A previewing approval tool whose preview can be MOVED between the suspend and
 * the resume — the double ADR-184's fence is written against.
 *
 * Deliberately not readonly: the point is that the world changed while the run
 * waited, and a test states that by changing what the preview reads. It also
 * records the context it was previewed with, so a test can assert the re-preview
 * ran under the run's actor and not the approver's (ADR-083).
 */
final class ShiftingPreviewTool implements ToolInterface, RequiresApprovalInterface, ToolPreviewInterface
{
    /** @var list<ToolExecutionContext> */
    public array $previewContexts = [];

    public int $executions = 0;

    /**
     * @param list<string> $lines what the preview says right now
     */
    public function __construct(
        private readonly string $name,
        public array $lines = ['2 reference(s) → 3, appended last'],
        public bool $throwOnPreview = false,
    ) {}

    public function mayViewerReadPreview(array $arguments, BackendUserAuthentication $viewer): bool
    {
        return true;
    }

    public function previewCall(array $arguments, ToolExecutionContext $context): array
    {
        $this->previewContexts[] = $context;

        if ($this->throwOnPreview) {
            throw new RuntimeException('the record vanished', 1788154900);
        }

        return $this->lines;
    }

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function($this->name, 'shifting ' . $this->name, ['type' => 'object', 'properties' => []]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        ++$this->executions;

        return ToolResult::text('WROTE');
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
