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
use PHPUnit\Framework\Attributes\Test;

/**
 * The skill choice of a configuration (ADR-214 item 3): an orphaned or
 * disabled skill is offered only while the record already has it attached,
 * labelled, so saving the form keeps the attachment; a new attachment picks
 * among enabled, non-orphaned skills.
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
    public function anAttachedOrphanedOrDisabledSkillStaysLabelledAndAnUnattachedOneIsNotOffered(): void
    {
        $params = $this->params(9);

        (new SkillAttachmentItems())->filterAndLabel($params);

        self::assertSame(
            ['Live', 'Orphan (orphaned)', 'Off (disabled)'],
            array_column($params['items'], 'label'),
        );
    }

    #[Test]
    public function aNewRecordIsOfferedEnabledNonOrphanedSkillsOnly(): void
    {
        $params = $this->params('NEW123');

        (new SkillAttachmentItems())->filterAndLabel($params);

        self::assertSame(['Live'], array_column($params['items'], 'label'));
    }

    /**
     * @return array{items: list<array{label: string, value: int}>, row: array<string, mixed>, config: array<string, mixed>}
     */
    private function params(int|string $uid): array
    {
        return [
            'items' => [
                ['label' => 'Live', 'value' => 1],
                ['label' => 'Orphan', 'value' => 2],
                ['label' => 'Off', 'value' => 3],
                ['label' => 'Other orphan', 'value' => 4],
                ['label' => 'Other off', 'value' => 5],
            ],
            'row'    => ['uid' => $uid],
            'config' => ['MM' => 'tx_nrllm_configuration_skill_mm'],
        ];
    }
}
