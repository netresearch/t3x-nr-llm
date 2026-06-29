<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Service\Tool\Builtin\GetTcaTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GetTcaTool.
 *
 * Drives the tool against a controlled $GLOBALS['TCA'] so the schema-only
 * contract (table listing, column types, neutral unknown-table string) is
 * asserted without a database.
 */
#[CoversClass(GetTcaTool::class)]
final class GetTcaToolTest extends TestCase
{
    /** @var array<array-key, mixed> */
    private array $tcaBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tcaBackup = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];

        $GLOBALS['TCA'] = [
            'pages' => [
                'columns' => [
                    'title'   => ['config' => ['type' => 'input']],
                    'doktype' => ['config' => ['type' => 'select']],
                    'broken'  => ['label' => 'no config here'],
                ],
            ],
            'tt_content' => [
                'columns' => [
                    'header' => ['config' => ['type' => 'input']],
                ],
            ],
        ];
    }

    protected function tearDown(): void
    {
        $GLOBALS['TCA'] = $this->tcaBackup;
        parent::tearDown();
    }

    #[Test]
    public function getSpecDeclaresGetTcaFunctionWithOptionalTable(): void
    {
        $spec = (new GetTcaTool())->getSpec();

        self::assertSame('get_tca', $spec->name);
        self::assertSame('object', $spec->parameters['type'] ?? null);
        $properties = $spec->parameters['properties'] ?? null;
        self::assertIsArray($properties);
        self::assertArrayHasKey('table', $properties);
    }

    #[Test]
    public function withoutTableArgumentListsSortedTableNames(): void
    {
        $output = (new GetTcaTool())->execute([]);

        self::assertStringContainsString('TCA tables (2):', $output);
        // Sorted: pages before tt_content.
        self::assertStringContainsString("pages\ntt_content", $output);
    }

    #[Test]
    public function withTableArgumentReturnsColumnNamesAndTypes(): void
    {
        $output = (new GetTcaTool())->execute(['table' => 'pages']);

        self::assertStringContainsString('TCA columns for pages:', $output);
        self::assertStringContainsString('title: input', $output);
        self::assertStringContainsString('doktype: select', $output);
        // A column without config.type falls back to "?", never an error.
        self::assertStringContainsString('broken: ?', $output);
    }

    #[Test]
    public function unknownTableReturnsNeutralString(): void
    {
        self::assertSame('Unknown TCA table.', (new GetTcaTool())->execute(['table' => 'no_such_table']));
    }
}
