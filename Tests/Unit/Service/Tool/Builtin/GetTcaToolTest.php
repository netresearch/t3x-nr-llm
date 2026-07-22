<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Service\Tool\Builtin\GetTcaTool;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

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

    private mixed $beUserBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tcaBackup = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];

        // get_tca self-enforces the acting user's tables_select rights; an admin
        // passes every check (isAdmin() short-circuits), so set one up to assert
        // the schema-listing contract.
        $this->beUserBackup = $GLOBALS['BE_USER'] ?? null;
        $adminUser          = new BackendUserAuthentication();
        $adminUser->user    = ['uid' => 1, 'admin' => 1];
        $GLOBALS['BE_USER'] = $adminUser;

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
        if ($this->beUserBackup === null) {
            unset($GLOBALS['BE_USER']);
        } else {
            $GLOBALS['BE_USER'] = $this->beUserBackup;
        }
        parent::tearDown();
    }

    #[Test]
    public function getSpecDeclaresGetTcaFunctionWithOptionalTable(): void
    {
        $spec = (new GetTcaTool(new TableReadAccessService()))->getSpec();

        self::assertSame('get_tca', $spec->name);
        self::assertSame('object', $spec->parameters['type'] ?? null);
        $properties = $spec->parameters['properties'] ?? null;
        self::assertIsArray($properties);
        self::assertArrayHasKey('table', $properties);
    }

    #[Test]
    public function withoutTableArgumentListsSortedTableNames(): void
    {
        $output = (new GetTcaTool(new TableReadAccessService()))->execute([])->content;

        self::assertStringContainsString('TCA tables (2):', $output);
        // Sorted: pages before tt_content.
        self::assertStringContainsString("pages\ntt_content", $output);
    }

    #[Test]
    public function withTableArgumentReturnsColumnNamesAndTypes(): void
    {
        $output = (new GetTcaTool(new TableReadAccessService()))->execute(['table' => 'pages'])->content;

        self::assertStringContainsString('TCA columns for pages:', $output);
        self::assertStringContainsString('title: input', $output);
        self::assertStringContainsString('doktype: select', $output);
        // A column without config.type falls back to "?", never an error.
        self::assertStringContainsString('broken: ?', $output);
    }

    #[Test]
    public function unknownTableReturnsNeutralString(): void
    {
        self::assertSame('Unknown TCA table.', (new GetTcaTool(new TableReadAccessService()))->execute(['table' => 'no_such_table'])->content);
    }

    #[Test]
    public function theExtensionsOwnTablesAreNotDescribedEvenToAnAdmin(): void
    {
        // BackendUserAuthentication::check() returns true for every table for an
        // admin, so the raw permission check happily described the extension's
        // own vault-key-bearing configuration tables. The shared table policy —
        // which its sibling get_full_tca always used — denies them for everyone
        // (ADR-093). The neutral string never confirms the table exists.
        $GLOBALS['TCA']['tx_nrllm_provider'] = ['columns' => ['api_key' => ['config' => ['type' => 'input']]]];

        $tool = new GetTcaTool(new TableReadAccessService());

        self::assertSame('Unknown TCA table.', $tool->execute(['table' => 'tx_nrllm_provider'])->content);
        self::assertStringNotContainsString('tx_nrllm_provider', $tool->execute([])->content);
        // A normal table is still described in full — the gate must not over-block.
        self::assertStringContainsString('title', $tool->execute(['table' => 'pages'])->content);
    }
}
