<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\FluidResolveTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Functional tests for FluidResolveTool (ADR-045): resolves a real template of
 * the loaded extension and reports a missing one as unresolved.
 */
#[CoversClass(FluidResolveTool::class)]
final class FluidResolveToolTest extends AbstractFunctionalTestCase
{
    private FluidResolveTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new FluidResolveTool();
    }

    #[Test]
    public function resolvesAnExistingTemplateOfTheExtension(): void
    {
        $output = $this->tool->execute([
            'name'      => 'Backend/Playground/List',
            'extension' => 'nr_llm',
        ], ToolExecutionContext::none())->content;

        self::assertStringContainsString('[x]', $output);
        self::assertStringContainsString('Backend/Playground/List.html', $output);
        self::assertStringContainsString('Resolved:', $output);
        self::assertStringNotContainsString('(none', $output);
    }

    #[Test]
    public function reportsAMissingTemplateAsUnresolved(): void
    {
        $output = $this->tool->execute([
            'name'      => 'Backend/DoesNotExistAnywhere',
            'extension' => 'nr_llm',
        ], ToolExecutionContext::none())->content;

        self::assertStringContainsString('[ ]', $output);
        self::assertStringContainsString('Resolved: (none', $output);
    }
}
