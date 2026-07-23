<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\GetTableSchemaTool;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Functional tests for GetTableSchemaTool (ADR-045): the schema surfaces
 * relations (the value over get_tca), denies sensitive tables even for admins,
 * and withholds credential-field detail.
 */
#[CoversClass(GetTableSchemaTool::class)]
final class GetTableSchemaToolTest extends AbstractFunctionalTestCase
{
    private GetTableSchemaTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $this->tool = new GetTableSchemaTool(new TableReadAccessService());

        // A synthetic table gives a version-stable relation + sensitive field
        // to assert against.
        self::assertIsArray($GLOBALS['TCA']);
        $GLOBALS['TCA']['tx_demo_thing'] = [
            'ctrl'    => ['title' => 'Demo Thing', 'label' => 'name'],
            'columns' => [
                'name'     => ['config' => ['type' => 'input']],
                'author'   => ['config' => ['type' => 'select', 'foreign_table' => 'be_users']],
                'api_key'  => ['config' => ['type' => 'input']],
            ],
        ];
    }

    #[Test]
    public function describesFieldsAndSurfacesRelationsAndWithholdsSecrets(): void
    {
        $user = $this->setUpBackendUser(1);

        $output = $this->tool->execute(
            ['table' => 'tx_demo_thing'],
            ToolExecutionContext::fromBackendUser($user),
        )->content;

        self::assertStringContainsString('Table: tx_demo_thing', $output);
        self::assertStringContainsString('name: input', $output);
        // Relation target + kind — the point of this tool.
        self::assertStringContainsString('author: select, → be_users (select)', $output);
        // Credential-ish column: name + type only, no further detail.
        self::assertStringContainsString('api_key: input [sensitive — detail withheld]', $output);
    }

    #[Test]
    public function describesRealCoreTable(): void
    {
        $user = $this->setUpBackendUser(1);

        $output = $this->tool->execute(
            ['table' => 'tt_content'],
            ToolExecutionContext::fromBackendUser($user),
        )->content;

        self::assertStringContainsString('Table: tt_content', $output);
        self::assertStringContainsString('Fields:', $output);
        self::assertStringContainsString('CType:', $output);
    }

    #[Test]
    public function deniesSensitiveTableEvenForAdmin(): void
    {
        $user = $this->setUpBackendUser(1);

        self::assertSame('Table not found or not permitted.', $this->tool->execute(
            ['table' => 'be_users'],
            ToolExecutionContext::fromBackendUser($user),
        )->content);
    }

    #[Test]
    public function deniesWithoutBackendUser(): void
    {
        self::assertSame('Table not found or not permitted.', $this->tool->execute(
            ['table' => 'tt_content'],
            ToolExecutionContext::none(),
        )->content);
    }

    #[Test]
    public function resolvesLllTitleAndReportsIntegerVersioning(): void
    {
        $user = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');

        self::assertIsArray($GLOBALS['TCA']);
        $GLOBALS['TCA']['tx_demo_ver'] = [
            'ctrl' => [
                'title'        => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.description',
                // Legacy integer form must still be recognised as workspace-capable.
                'versioningWS' => 2,
            ],
            'columns' => ['name' => ['config' => ['type' => 'input']]],
        ];

        $output = $this->tool->execute(
            ['table' => 'tx_demo_ver'],
            ToolExecutionContext::fromBackendUser($user),
        )->content;

        self::assertStringContainsString('Title: Description', $output);
        self::assertStringNotContainsString('LLL:', $output);
        self::assertStringContainsString('workspace-capable', $output);
    }
}
