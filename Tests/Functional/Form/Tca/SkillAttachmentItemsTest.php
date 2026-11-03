<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Form\Tca;

use Netresearch\NrLlm\Form\Tca\SkillAttachmentItems;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Schema\Struct\SelectItem;

/**
 * The skill choice of a configuration (ADR-214 item 3): an orphaned or
 * disabled skill is offered only while the record already has it attached,
 * labelled, so saving the form keeps the attachment; a new attachment picks
 * among enabled, non-orphaned skills.
 *
 * FormEngine hands the items over as SelectItem objects on TYPO3 13.4 and
 * 14.3, so every case runs with both item shapes.
 */
#[CoversClass(SkillAttachmentItems::class)]
final class SkillAttachmentItemsTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $skills = $this->getConnectionPool()->getConnectionForTable('tx_nrllm_skill');
        $skills->insert('tx_nrllm_skill', ['uid' => 1, 'pid' => 0, 'name' => 'Live', 'enabled' => 1, 'orphaned' => 0]);
        $skills->insert('tx_nrllm_skill', ['uid' => 2, 'pid' => 0, 'name' => 'Orphan', 'enabled' => 0, 'orphaned' => 1]);
        $skills->insert('tx_nrllm_skill', ['uid' => 3, 'pid' => 0, 'name' => 'Off', 'enabled' => 0, 'orphaned' => 0]);
        $skills->insert('tx_nrllm_skill', ['uid' => 4, 'pid' => 0, 'name' => 'Other orphan', 'enabled' => 0, 'orphaned' => 1]);
        $skills->insert('tx_nrllm_skill', ['uid' => 5, 'pid' => 0, 'name' => 'Other off', 'enabled' => 0, 'orphaned' => 0]);

        $mm = $this->getConnectionPool()->getConnectionForTable('tx_nrllm_configuration_skill_mm');
        $mm->insert('tx_nrllm_configuration_skill_mm', ['uid_local' => 9, 'uid_foreign' => 2]);
        $mm->insert('tx_nrllm_configuration_skill_mm', ['uid_local' => 9, 'uid_foreign' => 3]);
    }

    #[Test]
    #[DataProvider('itemShapes')]
    public function anAttachedOrphanedOrDisabledSkillStaysLabelledAndAnUnattachedOneIsNotOffered(bool $selectItems): void
    {
        $params = $this->params(9, $selectItems);

        (new SkillAttachmentItems())->filterAndLabel($params);

        self::assertSame(['Live', 'Orphan (orphaned)', 'Off (disabled)'], $this->labels($params));
    }

    #[Test]
    #[DataProvider('itemShapes')]
    public function aNewRecordIsOfferedEnabledNonOrphanedSkillsOnly(bool $selectItems): void
    {
        $params = $this->params('NEW123', $selectItems);

        (new SkillAttachmentItems())->filterAndLabel($params);

        self::assertSame(['Live'], $this->labels($params));
    }

    /**
     * The items FormEngine gets back keep the shape they came in, so it can
     * read their values and groups as before.
     */
    #[Test]
    #[DataProvider('itemShapes')]
    public function theItemsKeepTheirShape(bool $selectItems): void
    {
        $params = $this->params(9, $selectItems);

        (new SkillAttachmentItems())->filterAndLabel($params);

        foreach ($params['items'] as $item) {
            self::assertSame($selectItems, $item instanceof SelectItem);
        }

        self::assertSame([1, 2, 3], array_map(static fn (array|SelectItem $item): mixed => $item['value'], $params['items']));
    }

    /**
     * Attached 'always', a process skill is not offered for a new attachment
     * (ADR-214 item 6) and kept, labelled, where it is attached; attached
     * on demand it is offered as any skill.
     */
    #[Test]
    #[DataProvider('itemShapes')]
    public function aProcessSkillIsNotOfferedForANewAlwaysAttachment(bool $selectItems): void
    {
        $skills = $this->getConnectionPool()->getConnectionForTable('tx_nrllm_skill');
        $skills->insert('tx_nrllm_skill', ['uid' => 6, 'pid' => 0, 'name' => 'Tour', 'enabled' => 1, 'orphaned' => 0, 'process' => 1]);
        $skills->insert('tx_nrllm_skill', ['uid' => 7, 'pid' => 0, 'name' => 'Old tour', 'enabled' => 1, 'orphaned' => 0, 'process' => 1]);
        $this->getConnectionPool()->getConnectionForTable('tx_nrllm_configuration_skill_mm')
            ->insert('tx_nrllm_configuration_skill_mm', ['uid_local' => 9, 'uid_foreign' => 7, 'load_mode' => 'always']);

        $always                              = $this->params(9, $selectItems, [['label' => 'Tour', 'value' => 6], ['label' => 'Old tour', 'value' => 7]]);
        $always['config']['MM_match_fields'] = ['load_mode' => 'always'];
        (new SkillAttachmentItems())->filterAndLabel($always);

        self::assertNotContains('Tour', $this->labels($always));
        self::assertContains('Old tour (process skill, composed only when invoked)', $this->labels($always));

        $onDemand                              = $this->params('NEW1', $selectItems, [['label' => 'Tour', 'value' => 6]]);
        $onDemand['config']['MM_match_fields'] = ['load_mode' => 'on_demand'];
        (new SkillAttachmentItems())->filterAndLabel($onDemand);

        self::assertContains('Tour', $this->labels($onDemand));
    }

    /**
     * FormEngine passes the items as SelectItem objects on TYPO3 13.4
     * (AbstractItemProvider) and 14.3 (ItemProcessingService); a direct
     * caller may pass plain arrays.
     *
     * @return iterable<string, array{bool}>
     */
    public static function itemShapes(): iterable
    {
        yield 'SelectItem objects, as FormEngine passes them' => [true];
        yield 'plain item arrays' => [false];
    }

    /**
     * @param list<array{label: string, value: int}> $extra
     *
     * @return array{items: list<array{label: string, value: int}|SelectItem>, row: array<string, mixed>, config: array<string, mixed>}
     */
    private function params(int|string $uid, bool $selectItems, array $extra = []): array
    {
        $items = [
            ['label' => 'Live', 'value' => 1],
            ['label' => 'Orphan', 'value' => 2],
            ['label' => 'Off', 'value' => 3],
            ['label' => 'Other orphan', 'value' => 4],
            ['label' => 'Other off', 'value' => 5],
            ...$extra,
        ];

        return [
            'items'  => $selectItems ? array_map(static fn (array $item): SelectItem => SelectItem::fromTcaItemArray($item), $items) : $items,
            'row'    => ['uid' => $uid],
            'config' => ['MM' => 'tx_nrllm_configuration_skill_mm'],
        ];
    }

    /**
     * @param array{items: array<int, mixed>} $params
     *
     * @return list<mixed>
     */
    private function labels(array $params): array
    {
        return array_values(array_map(
            static fn (mixed $item): mixed => is_array($item) || $item instanceof SelectItem ? ($item['label'] ?? null) : null,
            $params['items'],
        ));
    }
}
