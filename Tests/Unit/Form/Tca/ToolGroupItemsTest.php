<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Form\Tca;

use Netresearch\NrLlm\Form\Tca\ToolGroupItems;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The itemsProcFunc must list the registered tools' groups (sorted,
 * de-duplicated) and keep groups already stored on the record selectable
 * even when no installed tool claims them any more.
 */
#[CoversClass(ToolGroupItems::class)]
final class ToolGroupItemsTest extends AbstractUnitTestCase
{
    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    #[Test]
    public function addsRegistryGroupsSortedAndDeduplicated(): void
    {
        $this->registerRegistryWithGroups(['rag', 'diagnostics', 'rag', 'fal']);

        $params = ['items' => []];
        (new ToolGroupItems())->addItems($params);

        self::assertSame(
            [
                ['label' => 'diagnostics', 'value' => 'diagnostics'],
                ['label' => 'fal', 'value' => 'fal'],
                ['label' => 'rag', 'value' => 'rag'],
            ],
            $params['items'],
        );
    }

    #[Test]
    public function keepsStoredButUnknownGroupsSelectable(): void
    {
        $this->registerRegistryWithGroups(['diagnostics']);

        $params = [
            'items' => [],
            'row'   => ['allowed_tool_groups' => 'diagnostics,orphaned_group'],
        ];
        (new ToolGroupItems())->addItems($params);

        self::assertSame(
            [
                ['label' => 'diagnostics', 'value' => 'diagnostics'],
                ['label' => 'orphaned_group', 'value' => 'orphaned_group'],
            ],
            $params['items'],
        );
    }

    #[Test]
    public function acceptsStoredValueAsArrayFromFormEngine(): void
    {
        $this->registerRegistryWithGroups([]);

        $params = [
            'items' => [],
            'row'   => ['allowed_tool_groups' => ['legacy_a', 'legacy_b']],
        ];
        (new ToolGroupItems())->addItems($params);

        self::assertSame(
            [
                ['label' => 'legacy_a', 'value' => 'legacy_a'],
                ['label' => 'legacy_b', 'value' => 'legacy_b'],
            ],
            $params['items'],
        );
    }

    #[Test]
    public function ignoresNonScalarStoredValue(): void
    {
        $this->registerRegistryWithGroups([]);

        $params = [
            'items' => [],
            'row'   => ['allowed_tool_groups' => null],
        ];
        (new ToolGroupItems())->addItems($params);

        self::assertSame([], $params['items']);
    }

    /**
     * @param list<string> $groups
     */
    private function registerRegistryWithGroups(array $groups): void
    {
        $tools = [];
        foreach ($groups as $i => $group) {
            $tools[] = new FakeTool('tool_' . $i . '_' . $group, group: $group);
        }
        GeneralUtility::addInstance(ToolRegistry::class, new ToolRegistry($tools));
    }
}
