<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Hook;

use Netresearch\NrLlm\Hook\SkillDeletionGuardHook;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A skill that a configuration or a task still has attached is not deleted
 * through the DataHandler (ADR-214 item 3): deleting it would lift the tool
 * restriction it imposes. Detached, or attached only to a deleted holder, it
 * is deleted as before.
 */
#[CoversClass(SkillDeletionGuardHook::class)]
final class SkillDeletionGuardHookTest extends AbstractFunctionalTestCase
{
    private const SKILL_UID = 7;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('BeUsers.csv');
        $backendUser     = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $this->getConnectionPool()->getConnectionForTable('tx_nrllm_skill')
            ->insert('tx_nrllm_skill', ['uid' => self::SKILL_UID, 'pid' => 0, 'identifier' => 'guide']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function holders(): iterable
    {
        yield 'configuration' => ['tx_nrllm_configuration', 'tx_nrllm_configuration_skill_mm'];
        yield 'task' => ['tx_nrllm_task', 'tx_nrllm_task_skill_mm'];
    }

    #[Test]
    #[DataProvider('holders')]
    public function anAttachedSkillIsNotDeletedAndADetachedOneIs(string $holderTable, string $mmTable): void
    {
        $this->getConnectionPool()->getConnectionForTable($holderTable)->insert($holderTable, ['uid' => 3, 'pid' => 0]);
        $this->getConnectionPool()->getConnectionForTable($mmTable)->insert($mmTable, ['uid_local' => 3, 'uid_foreign' => self::SKILL_UID]);

        $errors = $this->delete();

        self::assertNotSame([], $errors, 'the refusal is reported');
        self::assertStringContainsString($holderTable . ':3', implode("\n", $errors));
        self::assertSame(0, $this->deletedFlag(), 'the attached skill is kept');

        $this->getConnectionPool()->getConnectionForTable($mmTable)->delete($mmTable, ['uid_foreign' => self::SKILL_UID]);

        self::assertSame([], $this->delete());
        self::assertSame(1, $this->deletedFlag(), 'detached, it is deleted');
    }

    #[Test]
    public function anAttachmentOnADeletedConfigurationDoesNotBlockTheDelete(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_nrllm_configuration')
            ->insert('tx_nrllm_configuration', ['uid' => 3, 'pid' => 0, 'deleted' => 1]);
        $this->getConnectionPool()->getConnectionForTable('tx_nrllm_configuration_skill_mm')
            ->insert('tx_nrllm_configuration_skill_mm', ['uid_local' => 3, 'uid_foreign' => self::SKILL_UID]);

        self::assertSame([], $this->delete());
        self::assertSame(1, $this->deletedFlag());
    }

    /**
     * @return list<string> the DataHandler's error log
     */
    private function delete(): array
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], ['tx_nrllm_skill' => [self::SKILL_UID => ['delete' => 1]]]);
        $dataHandler->process_cmdmap();

        return $dataHandler->errorLog;
    }

    private function deletedFlag(): int
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_nrllm_skill');
        $queryBuilder->getRestrictions()->removeAll();
        $value = $queryBuilder->select('deleted')->from('tx_nrllm_skill')
            ->where($queryBuilder->expr()->eq('uid', self::SKILL_UID))
            ->executeQuery()->fetchOne();

        return is_numeric($value) ? (int)$value : -1;
    }
}
