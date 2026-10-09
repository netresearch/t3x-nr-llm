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
use Netresearch\NrLlm\Service\Tool\StructuredPreviewInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use RuntimeException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * A tool with preview lines AND a structured preview (ADR-214, item 9).
 *
 * The entries it returns are fixed; whether the read-side gate lets the viewer
 * see the lines, and whether the structured preview throws, are switches.
 */
final readonly class StructuredPreviewingTool implements ToolInterface, RequiresApprovalInterface, ToolPreviewInterface, StructuredPreviewInterface
{
    /**
     * @param list<mixed> $entries what structuredPreview() returns; a test may slip in a non-entry
     */
    public function __construct(
        private string $name,
        private array $entries,
        private bool $viewerMayRead = true,
        private bool $throw = false,
    ) {}

    public function structuredPreview(array $arguments, BackendUserAuthentication $reader): array
    {
        if ($this->throw) {
            throw new RuntimeException('structured preview broke', 1791700101);
        }

        // @phpstan-ignore return.type (intentionally unchecked: a test passes a wrong shape to prove the factory drops it)
        return $this->entries;
    }

    public function mayViewerReadPreview(array $arguments, BackendUserAuthentication $viewer): bool
    {
        return $this->viewerMayRead;
    }

    public function previewCall(array $arguments, ToolExecutionContext $context): array
    {
        return ['would write'];
    }

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function($this->name, 'structured ' . $this->name, ['type' => 'object', 'properties' => []]);
    }

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
