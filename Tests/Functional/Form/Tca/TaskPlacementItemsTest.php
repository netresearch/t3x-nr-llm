<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Form\Tca;

use Netresearch\NrLlm\Form\Tca\TaskPlacementItems;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Schema\Struct\SelectItem;

/**
 * A task select that names a placement (ADR-245): the tasks marked for it come
 * first in their own group, the static items stay where the TCA put them, and
 * the rest is shown after or hidden as the field asks — never hiding the value
 * the record already holds, and never hiding everything while nothing is marked.
 */
#[CoversClass(TaskPlacementItems::class)]
final class TaskPlacementItemsTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $tasks = $this->getConnectionPool()->getConnectionForTable('tx_nrllm_task');
        $tasks->insert('tx_nrllm_task', ['uid' => 1, 'pid' => 0, 'identifier' => 'translate', 'name' => 'Translate', 'placements' => '']);
        $tasks->insert('tx_nrllm_task', ['uid' => 2, 'pid' => 0, 'identifier' => 'photo', 'name' => 'Photo', 'placements' => 'landingpage_image']);
        $tasks->insert('tx_nrllm_task', ['uid' => 3, 'pid' => 0, 'identifier' => 'summary', 'name' => 'Summary', 'placements' => 'ai_tasks']);
        $tasks->insert('tx_nrllm_task', ['uid' => 4, 'pid' => 0, 'identifier' => 'flat', 'name' => 'Flat', 'placements' => 'ai_tasks,landingpage_image']);
    }

    #[Test]
    #[DataProvider('itemShapes')]
    public function markedTasksComeFirstInTheirGroupAndTheRestFollowsInTheOtherGroup(bool $selectItems): void
    {
        $params = $this->params('landingpage_image', 'after', 0, $selectItems);

        (new TaskPlacementItems())->groupByPlacement($params);

        self::assertSame(
            [['None', null], ['Flat', 'nrllm_placement'], ['Photo', 'nrllm_placement'], ['Summary', 'nrllm_unmarked'], ['Translate', 'nrllm_unmarked']],
            $this->labelsAndGroups($params),
        );
    }

    #[Test]
    #[DataProvider('itemShapes')]
    public function hideLeavesOnlyTheMarkedTasksAndTheStaticItems(bool $selectItems): void
    {
        $params = $this->params('landingpage_image', 'hide', 0, $selectItems);

        (new TaskPlacementItems())->groupByPlacement($params);

        self::assertSame(
            [['None', null], ['Flat', 'nrllm_placement'], ['Photo', 'nrllm_placement']],
            $this->labelsAndGroups($params),
        );
    }

    #[Test]
    #[DataProvider('itemShapes')]
    public function hideKeepsTheUnmarkedTaskTheRecordAlreadyHolds(bool $selectItems): void
    {
        $params = $this->params('landingpage_image', 'hide', 1, $selectItems);

        (new TaskPlacementItems())->groupByPlacement($params);

        self::assertSame(
            [['None', null], ['Flat', 'nrllm_placement'], ['Photo', 'nrllm_placement'], ['Translate', 'nrllm_unmarked']],
            $this->labelsAndGroups($params),
        );
    }

    /**
     * The edit form hands the raw column over; a list is accepted too.
     */
    #[Test]
    #[DataProvider('itemShapes')]
    public function hideReadsTheCurrentValueFromTheListFormEngineHandsOver(bool $selectItems): void
    {
        $params        = $this->params('landingpage_image', 'hide', 0, $selectItems);
        $params['row'] = ['image_task' => ['3']];

        (new TaskPlacementItems())->groupByPlacement($params);

        self::assertContains(['Summary', 'nrllm_unmarked'], $this->labelsAndGroups($params));
    }

    #[Test]
    #[DataProvider('itemShapes')]
    public function hideShowsEveryTaskWhileNoTaskIsMarkedForThePlacement(bool $selectItems): void
    {
        $params = $this->params('cowriter', 'hide', 0, $selectItems);

        (new TaskPlacementItems())->groupByPlacement($params);

        self::assertSame(
            [['None', null], ['Flat', 'nrllm_unmarked'], ['Photo', 'nrllm_unmarked'], ['Summary', 'nrllm_unmarked'], ['Translate', 'nrllm_unmarked']],
            $this->labelsAndGroups($params),
        );
    }

    #[Test]
    #[DataProvider('itemShapes')]
    public function aFieldThatNamesNoPlacementIsLeftAsItIs(bool $selectItems): void
    {
        $params = $this->params('', 'hide', 0, $selectItems);
        $before = $params['items'];

        (new TaskPlacementItems())->groupByPlacement($params);

        self::assertSame($this->labelsAndGroups(['items' => $before]), $this->labelsAndGroups($params));
    }

    /**
     * FormEngine passes the items as SelectItem objects on TYPO3 13.4 and
     * 14.3 (AbstractItemProvider / ItemProcessingService); a direct caller
     * may pass plain arrays.
     *
     * @return iterable<string, array{bool}>
     */
    public static function itemShapes(): iterable
    {
        yield 'SelectItem objects, as FormEngine passes them' => [true];
        yield 'plain item arrays' => [false];
    }

    /**
     * @return array{items: list<array<string, mixed>|SelectItem>, row: array<string, mixed>, field: string, config: array<string, mixed>}
     */
    private function params(string $placement, string $unmarked, int $current, bool $selectItems): array
    {
        $items = [
            ['label' => 'None', 'value' => 0],
            ['label' => 'Flat', 'value' => 4],
            ['label' => 'Photo', 'value' => 2],
            ['label' => 'Summary', 'value' => 3],
            ['label' => 'Translate', 'value' => 1],
        ];

        return [
            'items'  => $selectItems ? array_map(static fn (array $item): SelectItem => SelectItem::fromTcaItemArray($item), $items) : $items,
            'row'    => ['image_task' => (string) $current],
            'field'  => 'image_task',
            'config' => ['itemsProcConfig' => ['placement' => $placement, 'unmarked' => $unmarked]],
        ];
    }

    /**
     * @param array{items: list<array<string, mixed>|SelectItem>} $params
     *
     * @return list<array{0: mixed, 1: mixed}>
     */
    private function labelsAndGroups(array $params): array
    {
        return array_map(
            static fn (array|SelectItem $item): array => [$item['label'] ?? null, $item['group'] ?? null],
            $params['items'],
        );
    }
}
