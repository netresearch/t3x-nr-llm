<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\ValueObject\EditorAction;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\EditorActionInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;

/**
 * A writing tool that also declares itself an editor action (ADR-152).
 *
 * Separate from {@see FakeTool} rather than an optional flag on it: the
 * declaration is an interface, and a double that implements it always would
 * make every registry test look like an editor action.
 */
final readonly class FakeEditorActionTool implements ToolInterface, ToolEffectInterface, EditorActionInterface
{
    public function __construct(
        private string $name = 'fake_write',
        private string $group = 'editing',
        private EditorAction $action = new EditorAction(
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.update_page_metadata.label',
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.update_page_metadata.description',
            'nrllm-editor-action-page-metadata',
            ['pages'],
        ),
    ) {}

    public function getEditorAction(): EditorAction
    {
        return $this->action;
    }

    public function getEffect(): ToolEffect
    {
        return ToolEffect::IDEMPOTENT_WRITE;
    }

    public function getSpec(): ToolSpec
    {
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
        return $this->group;
    }
}
