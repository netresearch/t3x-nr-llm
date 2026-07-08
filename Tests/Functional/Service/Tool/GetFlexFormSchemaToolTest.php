<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\GetFlexFormSchemaTool;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Functional tests for GetFlexFormSchemaTool (ADR-045): non-flex fields are
 * reported, ambiguous multi-structure fields list their keys, and a single
 * inline data structure is parsed into sheets/fields.
 */
#[CoversClass(GetFlexFormSchemaTool::class)]
final class GetFlexFormSchemaToolTest extends AbstractFunctionalTestCase
{
    private const DS = '<T3DataStructure>
        <sheets><sDEF><ROOT>
            <sheetTitle>Main</sheetTitle>
            <type>array</type>
            <el>
                <settings.width>
                    <label>Width</label>
                    <config><type>input</type></config>
                </settings.width>
            </el>
        </ROOT></sDEF></sheets>
    </T3DataStructure>';

    private GetFlexFormSchemaTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $this->tool = new GetFlexFormSchemaTool(new TableReadAccessService());

        self::assertIsArray($GLOBALS['TCA']);
        $GLOBALS['TCA']['tx_demo_flex'] = [
            'ctrl'    => ['title' => 'Demo Flex'],
            'columns' => [
                'title'     => ['config' => ['type' => 'input']],
                'single_ff' => ['config' => ['type' => 'flex', 'ds' => self::DS]],
                'multi_ff'  => ['config' => [
                    'type'            => 'flex',
                    'ds_pointerField' => 'kind',
                    'ds'              => ['alpha' => self::DS, 'beta' => self::DS],
                ]],
            ],
        ];
    }

    #[Test]
    public function reportsWhenFieldIsNotFlex(): void
    {
        $this->setUpBackendUser(1);

        self::assertStringContainsString(
            'is not a FlexForm field',
            $this->tool->execute(['table' => 'tx_demo_flex', 'field' => 'title']),
        );
    }

    #[Test]
    public function listsDataStructureKeysWhenAmbiguous(): void
    {
        $this->setUpBackendUser(1);

        $output = $this->tool->execute(['table' => 'tx_demo_flex', 'field' => 'multi_ff']);

        self::assertStringContainsString('multiple data structures', $output);
        self::assertStringContainsString('- alpha', $output);
        self::assertStringContainsString('- beta', $output);
    }

    #[Test]
    public function parsesSingleDataStructureIntoSheetsAndFields(): void
    {
        $this->setUpBackendUser(1);

        $output = $this->tool->execute(['table' => 'tx_demo_flex', 'field' => 'single_ff']);

        self::assertStringContainsString('FlexForm schema for tx_demo_flex.single_ff', $output);
        self::assertStringContainsString('Sheet:', $output);
        self::assertStringContainsString('settings.width: input', $output);
    }

    #[Test]
    public function deniesSensitiveTable(): void
    {
        $this->setUpBackendUser(1);

        self::assertSame(
            'Table not found or not permitted.',
            $this->tool->execute(['table' => 'be_users', 'field' => 'anything']),
        );
    }
}
