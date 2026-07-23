<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Service\Tool\Builtin\FluidResolveTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetFlexFormSchemaTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetFullTcaTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetTableSchemaTool;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Spec shape + input-validation branches of the schema/resolution tools that
 * don't need a TYPO3 bootstrap (ADR-045). The DB/TCA paths are covered
 * functionally.
 */
#[CoversClass(FluidResolveTool::class)]
#[CoversClass(GetTableSchemaTool::class)]
#[CoversClass(GetFullTcaTool::class)]
#[CoversClass(GetFlexFormSchemaTool::class)]
final class SchemaToolsSpecTest extends TestCase
{
    #[Test]
    public function specsExposeNamesAndRequiredParameters(): void
    {
        $access = new TableReadAccessService();

        self::assertSame('fluid_resolve', (new FluidResolveTool())->getSpec()->name);
        self::assertSame('get_full_tca', (new GetFullTcaTool($access))->getSpec()->name);

        $tableSchema = (new GetTableSchemaTool($access))->getSpec();
        self::assertSame('get_table_schema', $tableSchema->name);
        self::assertContains('table', $tableSchema->parameters['required'] ?? []);

        $flex = (new GetFlexFormSchemaTool($access))->getSpec();
        self::assertSame('get_flexform_schema', $flex->name);
        self::assertSame(['table', 'field'], $flex->parameters['required'] ?? []);
    }

    #[Test]
    public function fluidResolveRejectsTraversalMissingAndMalformedInput(): void
    {
        $tool    = new FluidResolveTool();
        $context = ToolExecutionContext::none();

        self::assertSame('Provide a template/partial/layout name.', $tool->execute(['name' => ''], $context)->content);
        self::assertSame('Invalid name.', $tool->execute(['name' => '../../etc/passwd'], $context)->content);
        self::assertStringContainsString('Provide the "extension"', $tool->execute(['name' => 'Foo'], $context)->content);
        self::assertSame('Invalid extension key.', $tool->execute(['name' => 'Foo', 'extension' => 'Bad Key!'], $context)->content);
    }
}
