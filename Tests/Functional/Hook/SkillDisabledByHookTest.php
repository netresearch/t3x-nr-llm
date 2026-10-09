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
 * Only an interactive administrator enables or disables a skill through the
 * DataHandler (ADR-214 item 3). An administrator's disable is marked
 * 'admin' and an enable clears the mark, the sync's included. An editor —
 * even one granted the field — and the CLI user cannot change the flag, so
 * toggling a skill the sync disabled cannot turn the sync's mark into an
 * administrator's. The mark is never written through the DataHandler.
 */
#[CoversClass(SkillDisabledByHook::class)]
final class SkillDisabledByHookTest extends AbstractFunctionalTestCase
{
    private const SKILL_UID = 7;

    private const EDITOR_UID = 3;

    private const CLI_UID = 4;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('BeUsers.csv');
        $pool = $this->getConnectionPool();
        $pool->getConnectionForTable('pages')->insert('pages', [
            'uid' => 10, 'pid' => 0, 'title' => 'Skills', 'doktype' => 254,
            'perms_userid' => 1, 'perms_groupid' => 1, 'perms_user' => 31, 'perms_group' => 31, 'perms_everybody' => 31,
        ]);
        // An editor granted everything a skill author could be granted,
        // the enable flag and the mark included.
        $pool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 1, 'pid' => 0, 'title' => 'Skill authors',
            'tables_select' => 'tx_nrllm_skill', 'tables_modify' => 'tx_nrllm_skill',
            'non_exclude_fields' => 'tx_nrllm_skill:enabled,tx_nrllm_skill:disabled_by,tx_nrllm_skill:hidden,tx_nrllm_skill:orphaned',
            'db_mountpoints' => '10',
        ]);
        $pool->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => self::EDITOR_UID, 'pid' => 0, 'username' => 'skillauthor', 'password' => 'x', 'admin' => 0,
            'usergroup' => '1', 'db_mountpoints' => '10', 'options' => 3,
        ]);
        $pool->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => self::CLI_UID, 'pid' => 0, 'username' => '_cli_', 'password' => 'x', 'admin' => 1,
        ]);
        $pool->getConnectionForTable('tx_nrllm_skill')->insert('tx_nrllm_skill', [
            'uid' => self::SKILL_UID, 'pid' => 10, 'identifier' => 'guide', 'description' => 'Original',
            'enabled' => 0, 'disabled_by' => 'sync',
        ]);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    /**
     * The attack: an editor enables, then disables, a skill the sync
     * disabled. Both writes are refused and the skill stays marked 'sync',
     * so it keeps restricting; the editor can still write other fields.
     */
    #[Test]
    public function anEditorCannotToggleASkillTheSyncDisabled(): void
    {
        $this->actAs(self::EDITOR_UID);

        self::assertNotSame([], $this->write(['enabled' => 1]), 'the refusal is reported');
        // Unchanged, the flag never reaches the write; either way nothing
        // turns the sync's mark into an administrator's.
        $this->write(['enabled' => 0]);

        self::assertSame('0', $this->column('enabled'));
        self::assertSame('sync', $this->column('disabled_by'));

        self::assertSame([], $this->write(['description' => 'Edited']), 'the control: the editor may write the record');
        self::assertSame('Edited', $this->column('description'));
        self::assertSame('sync', $this->column('disabled_by'));
    }

    /**
     * Hiding takes a skill out of its runs as a disable does: an editor
     * granted the hidden flag cannot hide a skill the sync disabled.
     */
    #[Test]
    public function anEditorCannotHideASkill(): void
    {
        $this->actAs(self::EDITOR_UID);

        self::assertNotSame([], $this->write(['hidden' => 1]));
        self::assertSame('0', $this->column('hidden'));

        $this->actAs(1);

        self::assertSame([], $this->write(['hidden' => 1]), 'an administrator may');
        self::assertSame('1', $this->column('hidden'));
    }

    /**
     * Unhiding is an administrator's too: an editor cannot bring back a
     * skill an administrator hid.
     */
    #[Test]
    public function anEditorCannotUnhideASkill(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_nrllm_skill')->update('tx_nrllm_skill', ['hidden' => 1], ['uid' => self::SKILL_UID]);

        $this->actAs(self::EDITOR_UID);

        self::assertNotSame([], $this->write(['hidden' => 0]));
        self::assertSame('1', $this->column('hidden'));
    }

    /**
     * A write the hook refuses entirely leaves the record untouched, its
     * timestamp included.
     */
    #[Test]
    public function aFullyRefusedWriteLeavesTheRecordUntouched(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_nrllm_skill')->update('tx_nrllm_skill', ['tstamp' => 1000], ['uid' => self::SKILL_UID]);

        $this->actAs(self::EDITOR_UID);
        $this->write(['enabled' => 1]);

        self::assertSame('1000', $this->column('tstamp'));
    }

    /**
     * The orphan flag is the sync's: nobody clears it through the
     * DataHandler, an administrator neither.
     */
    #[Test]
    public function nobodyClearsTheOrphanFlag(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_nrllm_skill')->update('tx_nrllm_skill', ['orphaned' => 1], ['uid' => self::SKILL_UID]);

        $this->actAs(self::EDITOR_UID);
        $this->write(['orphaned' => 0]);
        self::assertSame('1', $this->column('orphaned'));

        $this->actAs(1);
        $this->write(['orphaned' => 0]);
        self::assertSame('1', $this->column('orphaned'));
    }

    /**
     * A new skill is attached nowhere yet. An editor creates it disabled
     * without an error, marked as not enabled by an administrator; asking to
     * create it enabled stores it disabled and says why. An administrator
     * may create it enabled.
     */
    #[Test]
    public function aNewSkillIsStoredDisabledAndMarkedUnlessAnAdministratorEnablesIt(): void
    {
        $this->actAs(self::EDITOR_UID);

        self::assertSame([], $this->create('NEW1', ['identifier' => 'draft']));
        self::assertSame(['0', 'sync'], $this->stateOf('draft'));

        self::assertNotSame([], $this->create('NEW2', ['identifier' => 'eager', 'enabled' => 1]));
        self::assertSame(['0', 'sync'], $this->stateOf('eager'));

        $this->actAs(1);

        self::assertSame([], $this->create('NEW3', ['identifier' => 'admins', 'enabled' => 1]));
        self::assertSame(['1', ''], $this->stateOf('admins'));
    }

    #[Test]
    public function theCliUserCannotToggleASkill(): void
    {
        $this->actAs(self::CLI_UID);

        self::assertNotSame([], $this->write(['enabled' => 1]));
        self::assertSame('0', $this->column('enabled'));
        self::assertSame('sync', $this->column('disabled_by'));
    }

    #[Test]
    public function anAdministratorsEnableClearsTheSyncMarkAndADisableIsMarkedAdmin(): void
    {
        $this->actAs(1);

        self::assertSame([], $this->write(['enabled' => 1]));
        self::assertSame('1', $this->column('enabled'));
        self::assertSame('', $this->column('disabled_by'));

        self::assertSame([], $this->write(['enabled' => 0]));
        self::assertSame('admin', $this->column('disabled_by'));
    }

    /**
     * Every form save sends the enable flag along, unchanged. Saving another
     * field of a skill the sync disabled keeps the sync's mark, also for an
     * administrator.
     */
    #[Test]
    public function aFormSaveThatLeavesTheFlagUnchangedKeepsTheSyncMark(): void
    {
        $this->actAs(1);

        self::assertSame([], $this->write(['description' => 'Edited', 'enabled' => 0]));
        self::assertSame('Edited', $this->column('description'));
        self::assertSame('sync', $this->column('disabled_by'));
    }

    #[Test]
    public function theMarkIsNeverWrittenThroughTheDataHandler(): void
    {
        $this->actAs(1);

        $this->write(['disabled_by' => 'admin']);
        self::assertSame('sync', $this->column('disabled_by'), 'not by an administrator');

        $this->actAs(self::EDITOR_UID);
        $this->write(['disabled_by' => 'admin']);
        self::assertSame('sync', $this->column('disabled_by'), 'nor by an editor granted the field');
    }

    private function actAs(int $userUid): void
    {
        $backendUser     = $this->setUpBackendUser($userUid);
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    /**
     * @param array<string, int|string> $fields
     *
     * @return list<string> the DataHandler's error log
     */
    private function write(array $fields): array
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['tx_nrllm_skill' => [self::SKILL_UID => $fields]], []);
        $dataHandler->process_datamap();

        // TYPO3 13.4 types the log as array, 14.3 as a list of strings.
        $errors = [];
        foreach ($dataHandler->errorLog as $error) {
            $errors[] = is_string($error) ? $error : var_export($error, true);
        }

        return $errors;
    }

    /**
     * @param array<string, int|string> $fields
     *
     * @return list<string>
     */
    private function create(string $newId, array $fields): array
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['tx_nrllm_skill' => [$newId => ['pid' => 10, ...$fields]]], []);
        $dataHandler->process_datamap();

        $errors = [];
        foreach ($dataHandler->errorLog as $error) {
            $errors[] = is_string($error) ? $error : var_export($error, true);
        }

        return $errors;
    }

    /**
     * @return array{0: string, 1: string} enabled and disabled_by of the skill with the identifier
     */
    private function stateOf(string $identifier): array
    {
        $row = $this->getConnectionPool()->getConnectionForTable('tx_nrllm_skill')
            ->select(['enabled', 'disabled_by'], 'tx_nrllm_skill', ['identifier' => $identifier])->fetchAssociative();
        self::assertIsArray($row, 'the skill was created');

        return [is_scalar($row['enabled']) ? (string)$row['enabled'] : '', is_scalar($row['disabled_by']) ? (string)$row['disabled_by'] : ''];
    }

    private function column(string $column): string
    {
        // Without restrictions: a hidden row is read too.
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_nrllm_skill');
        $queryBuilder->getRestrictions()->removeAll();
        $value = $queryBuilder->select($column)->from('tx_nrllm_skill')
            ->where($queryBuilder->expr()->eq('uid', self::SKILL_UID))
            ->executeQuery()->fetchOne();

        return is_scalar($value) ? (string)$value : '';
    }
}
