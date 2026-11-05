<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Service\Tool\ToolExclusionReadout;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The readout behind the Tools module and the governance tab (ADR-261), read
 * from the database column through the Extbase mapping the gate uses as well.
 */
#[CoversClass(ToolExclusionReadout::class)]
#[CoversClass(LlmConfiguration::class)]
final class ToolExclusionReadoutTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function listsTheActiveConfigurationsThatExcludeATool(): void
    {
        $this->insertConfiguration(701, 'editorial', 'Editorial', 'delete_record, move_page', 1);
        $this->insertConfiguration(702, 'support', 'Support', 'delete_record', 1);
        $this->insertConfiguration(703, 'plain', 'Plain', '', 1);
        $this->insertConfiguration(704, 'retired', 'Retired', 'publish_record', 0);

        $readout = new ToolExclusionReadout($this->getService(LlmConfigurationRepository::class));

        self::assertSame(
            [
                ['identifier' => 'editorial', 'name' => 'Editorial', 'tools' => ['delete_record', 'move_page']],
                ['identifier' => 'support', 'name' => 'Support', 'tools' => ['delete_record']],
            ],
            $readout->byConfiguration(),
        );
        self::assertSame(
            [
                'delete_record' => [['identifier' => 'editorial', 'name' => 'Editorial'], ['identifier' => 'support', 'name' => 'Support']],
                'move_page'     => [['identifier' => 'editorial', 'name' => 'Editorial']],
            ],
            $readout->byTool(),
        );
    }

    #[Test]
    public function aRowWithoutTheColumnValueExcludesNothing(): void
    {
        // The column is a nullable text: a row written before it existed, or
        // by a path that does not set it, holds NULL.
        $this->insertConfiguration(705, 'legacy', 'Legacy', null, 1);

        $configuration = $this->getService(LlmConfigurationRepository::class)->findOneByIdentifier('legacy');

        self::assertInstanceOf(LlmConfiguration::class, $configuration);
        self::assertSame([], $configuration->getExcludedToolsList());
        self::assertSame([], (new ToolExclusionReadout($this->getService(LlmConfigurationRepository::class)))->byConfiguration());
    }

    private function insertConfiguration(int $uid, string $identifier, string $name, ?string $excluded, int $active): void
    {
        $this->getService(ConnectionPool::class)->getConnectionForTable('tx_nrllm_configuration')->insert('tx_nrllm_configuration', [
            'uid'            => $uid,
            'pid'            => 0,
            'identifier'     => $identifier,
            'name'           => $name,
            'is_active'      => $active,
            'excluded_tools' => $excluded,
            'sorting'        => $uid,
        ]);
    }
}
