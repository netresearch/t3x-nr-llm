<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Form\Tca;

use Netresearch\NrLlm\Form\Tca\ToolItems;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The `excluded_tools` select lists the registered tools under one heading per
 * tool group, and keeps a stored name no tool carries any more selectable under
 * a heading of its own (ADR-261).
 */
#[CoversClass(ToolItems::class)]
final class ToolItemsTest extends AbstractUnitTestCase
{
    private const UNREGISTERED = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.excluded_tools.unregistered';

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    #[Test]
    public function listsTheRegisteredToolsSortedUnderTheirGroup(): void
    {
        $this->register(['move_page' => 'editing', 'get_tca' => 'structure', 'delete_record' => 'editing']);

        $params = ['items' => []];
        (new ToolItems())->addItems($params);

        self::assertSame(
            [
                ['label' => 'editing', 'value' => '--div--', 'group' => 'editing'],
                ['label' => 'delete_record', 'value' => 'delete_record', 'group' => 'editing'],
                ['label' => 'move_page', 'value' => 'move_page', 'group' => 'editing'],
                ['label' => 'structure', 'value' => '--div--', 'group' => 'structure'],
                ['label' => 'get_tca', 'value' => 'get_tca', 'group' => 'structure'],
            ],
            $params['items'],
        );
    }

    #[Test]
    public function keepsAStoredNameNoToolCarriesSelectable(): void
    {
        $this->register(['delete_record' => 'editing']);

        $params = ['items' => [], 'row' => ['excluded_tools' => 'delete_record,mcp_gone_tool,mcp_gone_tool']];
        (new ToolItems())->addItems($params);

        self::assertSame(
            [
                ['label' => 'editing', 'value' => '--div--', 'group' => 'editing'],
                ['label' => 'delete_record', 'value' => 'delete_record', 'group' => 'editing'],
                ['label' => self::UNREGISTERED, 'value' => '--div--', 'group' => ToolItems::UNKNOWN_GROUP],
                ['label' => 'mcp_gone_tool', 'value' => 'mcp_gone_tool', 'group' => ToolItems::UNKNOWN_GROUP],
            ],
            $params['items'],
        );
    }

    #[Test]
    public function acceptsTheStoredValueAsAnArrayAndIgnoresANonScalarOne(): void
    {
        $this->register([]);

        $params = ['items' => [], 'row' => ['excluded_tools' => ['gone_a', 'gone_b']]];
        (new ToolItems())->addItems($params);

        self::assertSame(['--div--', 'gone_a', 'gone_b'], array_column($params['items'], 'value'));

        $this->register([]);
        $params = ['items' => [], 'row' => ['excluded_tools' => null]];
        (new ToolItems())->addItems($params);

        self::assertSame([], $params['items']);
    }

    /**
     * @param array<string, string> $groupByName
     */
    private function register(array $groupByName): void
    {
        $tools = [];
        foreach ($groupByName as $name => $group) {
            $tools[] = new FakeTool($name, group: $group);
        }

        GeneralUtility::addInstance(ToolRegistry::class, new ToolRegistry($tools));
    }
}
