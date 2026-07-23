<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\ValidateTcaTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Functional tests for ValidateTcaTool (ADR-046): structural findings on a
 * deliberately broken synthetic table, a clean bill for a correct one, and
 * the usual neutral denial for sensitive tables.
 */
#[CoversClass(ValidateTcaTool::class)]
final class ValidateTcaToolTest extends AbstractFunctionalTestCase
{
    private ToolInterface $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');

        self::assertIsArray($GLOBALS['TCA']);
        $GLOBALS['TCA']['tx_demo_broken'] = [
            'ctrl' => [
                'title' => 'Broken Demo',
                'label' => 'missing_label_column',
            ],
            'columns' => [
                'name'   => ['config' => ['type' => 'input']],
                'author' => ['config' => ['type' => 'select', 'foreign_table' => 'tx_does_not_exist']],
            ],
            'types' => [
                '0' => ['showitem' => 'name, ghost_field, --palette--;;nope'],
            ],
        ];
        $GLOBALS['TCA']['tx_demo_clean'] = [
            'ctrl' => [
                'title' => 'Clean Demo',
                'label' => 'name',
            ],
            'columns' => [
                'name'     => ['config' => ['type' => 'input']],
                'category' => ['config' => ['type' => 'select', 'foreign_table' => 'sys_category']],
            ],
            'types' => [
                '0' => ['showitem' => 'name, --palette--;;meta'],
            ],
            'palettes' => [
                'meta' => ['showitem' => 'category'],
            ],
        ];

        // showitem references ctrl-declared fields (enablecolumns, language
        // fields) that have NO columns definition — auto-created by the core
        // since v13, so they must NOT be flagged.
        $GLOBALS['TCA']['tx_demo_ctrlfields'] = [
            'ctrl' => [
                'title'                    => 'Ctrl Fields Demo',
                'label'                    => 'name',
                'languageField'            => 'sys_language_uid',
                'transOrigPointerField'    => 'l18n_parent',
                'transOrigDiffSourceField' => 'l18n_diffsource',
                'enablecolumns'            => [
                    'disabled'  => 'disable',
                    'starttime' => 'starttime',
                ],
            ],
            'columns' => [
                'name' => ['config' => ['type' => 'input']],
            ],
            'types' => [
                '0' => ['showitem' => 'name, disable, starttime, sys_language_uid, l18n_parent'],
            ],
        ];

        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $tool = $registry->get('validate_tca');
        self::assertInstanceOf(ToolInterface::class, $tool);
        $this->tool = $tool;
    }

    /**
     * Set up the admin backend user and build the acting-user execution context
     * the (user-scoped) tool now authorises from, mirroring the ambient user.
     */
    private function adminContext(): ToolExecutionContext
    {
        $user = $this->setUpBackendUser(1);

        return ToolExecutionContext::fromBackendUser($user);
    }

    #[Test]
    public function reportsLabelForeignTableAndShowitemFindings(): void
    {
        $context = $this->adminContext();

        $output = $this->tool->execute(['table' => 'tx_demo_broken'], $context)->content;

        self::assertStringContainsString('ctrl.label "missing_label_column" is not a defined column', $output);
        self::assertStringContainsString('foreign_table "tx_does_not_exist" is not a TCA table', $output);
        self::assertStringContainsString('showitem references undefined column "ghost_field"', $output);
        self::assertStringContainsString('references unknown palette "nope"', $output);
        self::assertStringContainsString('Checked 1 table.', $output);
    }

    #[Test]
    public function cleanTableReportsNoIssues(): void
    {
        $context = $this->adminContext();

        self::assertSame(
            'No TCA issues found in table "tx_demo_clean".',
            $this->tool->execute(['table' => 'tx_demo_clean'], $context)->content,
        );
    }

    #[Test]
    public function ctrlDeclaredFieldsInShowitemAreNotFlagged(): void
    {
        $context = $this->adminContext();

        self::assertSame(
            'No TCA issues found in table "tx_demo_ctrlfields".',
            $this->tool->execute(['table' => 'tx_demo_ctrlfields'], $context)->content,
        );
    }

    #[Test]
    public function allTablesScanIncludesTheBrokenTable(): void
    {
        $context = $this->adminContext();

        $output = $this->tool->execute([], $context)->content;

        self::assertStringContainsString('tx_demo_broken: ctrl.label "missing_label_column"', $output);
        self::assertStringContainsString('Checked', $output);
    }

    #[Test]
    public function deniesSensitiveTableEvenForAdmin(): void
    {
        $context = $this->adminContext();

        self::assertSame(
            'Table not found or not permitted.',
            $this->tool->execute(['table' => 'be_users'], $context)->content,
        );
    }
}
