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
 * An editor copies a page holding a configuration and the skill attached
 * to it (ADR-214 item 3). The copied configuration is attached to the
 * copied skill, which is stored disabled and marked 'sync', so it keeps
 * restricting the copy's runs until an administrator enables it.
 */
#[CoversClass(SkillDisabledByHook::class)]
final class SkillCopyKeepsRestrictionTest extends AbstractFunctionalTestCase
{
    private const EDITOR_UID = 3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('BeUsers.csv');
        $pool = $this->getConnectionPool();
        foreach ([[10, 0, 'Mount'], [11, 10, 'Assistant']] as [$uid, $pid, $title]) {
            $pool->getConnectionForTable('pages')->insert('pages', [
                'uid' => $uid, 'pid' => $pid, 'title' => $title, 'doktype' => 254,
                'perms_userid' => 1, 'perms_groupid' => 1, 'perms_user' => 31, 'perms_group' => 31, 'perms_everybody' => 31,
            ]);
        }

        $pool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 1, 'pid' => 0, 'title' => 'Editors',
            'tables_select' => 'pages,tx_nrllm_skill,tx_nrllm_configuration',
            'tables_modify' => 'pages,tx_nrllm_skill,tx_nrllm_configuration',
            'pagetypes_select' => '254',
            'db_mountpoints' => '10',
        ]);
        $pool->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => self::EDITOR_UID, 'pid' => 0, 'username' => 'editor2', 'password' => 'x', 'admin' => 0,
            'usergroup' => '1', 'db_mountpoints' => '10', 'options' => 3,
        ]);
        $pool->getConnectionForTable('tx_nrllm_skill')->insert('tx_nrllm_skill', [
            'uid' => 7, 'pid' => 11, 'identifier' => 'guide', 'enabled' => 1, 'disabled_by' => '',
        ]);
        $pool->getConnectionForTable('tx_nrllm_configuration')->insert('tx_nrllm_configuration', [
            'uid' => 3, 'pid' => 11, 'identifier' => 'chat', 'name' => 'Chat', 'skills' => 1,
        ]);
        $pool->getConnectionForTable('tx_nrllm_configuration_skill_mm')->insert('tx_nrllm_configuration_skill_mm', [
            'uid_local' => 3, 'uid_foreign' => 7, 'sorting' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function anEditorsPageCopyAttachesTheCopiedConfigurationToADisabledMarkedCopyOfTheSkill(): void
    {
        $backendUser     = $this->setUpBackendUser(self::EDITOR_UID);
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], ['pages' => [11 => ['copy' => 10]]]);
        $dataHandler->process_cmdmap();

        $skills         = $dataHandler->copyMappingArray_merged['tx_nrllm_skill'] ?? [];
        $configurations = $dataHandler->copyMappingArray_merged['tx_nrllm_configuration'] ?? [];
        $skillCopy      = is_array($skills) && is_numeric($skills[7] ?? null) ? (int)$skills[7] : 0;
        $configCopy     = is_array($configurations) && is_numeric($configurations[3] ?? null) ? (int)$configurations[3] : 0;
        self::assertGreaterThan(0, $skillCopy, 'the skill was copied');
        self::assertGreaterThan(0, $configCopy, 'the configuration was copied');

        $attached = $this->getConnectionPool()->getConnectionForTable('tx_nrllm_configuration_skill_mm')
            ->select(['uid_foreign'], 'tx_nrllm_configuration_skill_mm', ['uid_local' => $configCopy])->fetchFirstColumn();
        self::assertSame([$skillCopy], array_map(intval(...), $attached), 'the copied configuration holds the copied skill');

        $row = $this->getConnectionPool()->getConnectionForTable('tx_nrllm_skill')
            ->select(['enabled', 'disabled_by'], 'tx_nrllm_skill', ['uid' => $skillCopy])->fetchAssociative();
        self::assertIsArray($row);
        self::assertSame(0, (int)$row['enabled'], 'an editor cannot store a skill enabled');
        self::assertSame('sync', $row['disabled_by'], 'so it keeps restricting the copied configuration');
    }

    /**
     * An administrator's copy of a skill an administrator disabled keeps
     * that decision; it does not start restricting.
     */
    #[Test]
    public function anAdministratorsCopyOfAnAdministratorsDisableKeepsTheMark(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_nrllm_skill')
            ->update('tx_nrllm_skill', ['enabled' => 0, 'disabled_by' => 'admin'], ['uid' => 7]);
        $backendUser     = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], ['tx_nrllm_skill' => [7 => ['copy' => 11]]]);
        $dataHandler->process_cmdmap();

        $skills    = $dataHandler->copyMappingArray_merged['tx_nrllm_skill'] ?? [];
        $skillCopy = is_array($skills) && is_numeric($skills[7] ?? null) ? (int)$skills[7] : 0;
        self::assertGreaterThan(0, $skillCopy);
        self::assertSame('admin', $this->getConnectionPool()->getConnectionForTable('tx_nrllm_skill')
            ->select(['disabled_by'], 'tx_nrllm_skill', ['uid' => $skillCopy])->fetchOne());
    }
}
