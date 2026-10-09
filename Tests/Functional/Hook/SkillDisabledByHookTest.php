<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Hook;

use Netresearch\NrLlm\Hook\SkillDisabledByHook;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Clearing a skill's enable flag through the DataHandler is an
 * administrator's decision, and setting it clears the sync's mark (ADR-214
 * item 3); a write that leaves the flag alone leaves the mark alone.
 */
#[CoversClass(SkillDisabledByHook::class)]
final class SkillDisabledByHookTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('BeUsers.csv');
        $backendUser     = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $this->getConnectionPool()->getConnectionForTable('tx_nrllm_skill')
            ->insert('tx_nrllm_skill', ['uid' => 7, 'pid' => 0, 'identifier' => 'guide', 'enabled' => 0, 'disabled_by' => 'sync']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function aWriteThatLeavesTheEnableFlagAloneKeepsTheMark(): void
    {
        $this->write(['description' => 'Edited']);

        self::assertSame('sync', $this->disabledBy());
    }

    #[Test]
    public function anEnableClearsTheMarkAndADisableIsTheAdministrators(): void
    {
        $this->write(['enabled' => 1]);

        self::assertSame('', $this->disabledBy());

        $this->write(['enabled' => 0]);

        self::assertSame('admin', $this->disabledBy());
    }

    /**
     * @param array<string, int|string> $fields
     */
    private function write(array $fields): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['tx_nrllm_skill' => [7 => $fields]], []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);
    }

    private function disabledBy(): string
    {
        $value = $this->getConnectionPool()->getConnectionForTable('tx_nrllm_skill')
            ->select(['disabled_by'], 'tx_nrllm_skill', ['uid' => 7])->fetchOne();

        return is_string($value) ? $value : '';
    }
}
