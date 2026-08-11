<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures;

use Netresearch\NrLlm\Domain\ValueObject\EditorAction;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\EditorActionInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;

/**
 * A tool that declares itself an editor action and gets the declaration wrong
 * (ADR-152) — the case a third-party extension produces, not one of ours.
 *
 * The throw is the REAL one: {@see EditorAction} refuses an empty record-type
 * list with code 1786406402. Enabled by default, so a test can assert that the
 * runtime gate still offers the tool while the module renders it undecorated.
 */
final readonly class FakeMalformedEditorActionTool implements ToolInterface, EditorActionInterface
{
    public function __construct(private string $name = 'broken_declaration') {}

    public function getEditorAction(): EditorAction
    {
        return new EditorAction(
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.update_page_metadata.label',
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.update_page_metadata.description',
            'nrllm-editor-action-page-metadata',
            [],
        );
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
        return true;
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
