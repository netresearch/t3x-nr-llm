<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Configuration;

use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The snippet mark and a configuration's opt-in for caller system messages
 * are written by an administrator only (ADR-214 item 8): an editor whose
 * group is granted both fields cannot write them through the DataHandler,
 * while the same write by an administrator lands. The control field shows
 * that the editor may write the records at all.
 */
#[CoversNothing]
final class CallerSystemFieldRightsTest extends AbstractFunctionalTestCase
{
    private const EDITOR_UID = 3;

    private const MARK = '1:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('BeUsers.csv');
        $pool = $this->getConnectionPool();
        $pool->getConnectionForTable('pages')->insert('pages', [
            'uid' => 10, 'pid' => 0, 'title' => 'LLM', 'doktype' => 254,
            'perms_userid' => 1, 'perms_groupid' => 1, 'perms_user' => 31, 'perms_group' => 31, 'perms_everybody' => 31,
        ]);
        $pool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 1, 'pid' => 0, 'title' => 'LLM editors',
            'tables_select' => 'tx_nrllm_promptsnippet,tx_nrllm_configuration',
            'tables_modify' => 'tx_nrllm_promptsnippet,tx_nrllm_configuration',
            'non_exclude_fields' => 'tx_nrllm_promptsnippet:caller_system_digest,tx_nrllm_configuration:snippets_in_caller_system_message',
            'db_mountpoints' => '10',
        ]);
        $pool->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => self::EDITOR_UID, 'pid' => 0, 'username' => 'llmeditor', 'password' => 'x', 'admin' => 0,
            'usergroup' => '1', 'db_mountpoints' => '10', 'options' => 3,
        ]);
        $pool->getConnectionForTable('tx_nrllm_promptsnippet')->insert('tx_nrllm_promptsnippet', [
            'uid' => 5, 'pid' => 10, 'name' => 'House style', 'snippet' => 'Short sentences.', 'description' => 'Original',
        ]);
        $pool->getConnectionForTable('tx_nrllm_configuration')->insert('tx_nrllm_configuration', [
            'uid' => 6, 'pid' => 10, 'identifier' => 'chat', 'name' => 'Chat', 'description' => 'Original',
        ]);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function anEditorCannotMarkASnippetOrOptAConfigurationIn(): void
    {
        $this->actAs(self::EDITOR_UID);

        $this->write([
            'tx_nrllm_promptsnippet' => [5 => ['caller_system_digest' => self::MARK, 'description' => 'Edited']],
            'tx_nrllm_configuration' => [6 => ['snippets_in_caller_system_message' => 1, 'description' => 'Edited']],
        ]);

        self::assertSame('Edited', $this->column('tx_nrllm_promptsnippet', 5, 'description'), 'the control: the editor may write the snippet');
        self::assertSame('', $this->column('tx_nrllm_promptsnippet', 5, 'caller_system_digest'));
        self::assertSame('Edited', $this->column('tx_nrllm_configuration', 6, 'description'));
        self::assertSame('0', $this->column('tx_nrllm_configuration', 6, 'snippets_in_caller_system_message'));
    }

    #[Test]
    public function anAdministratorCanWriteBoth(): void
    {
        $this->actAs(1);

        $this->write([
            'tx_nrllm_promptsnippet' => [5 => ['caller_system_digest' => self::MARK]],
            'tx_nrllm_configuration' => [6 => ['snippets_in_caller_system_message' => 1]],
        ]);

        self::assertSame(self::MARK, $this->column('tx_nrllm_promptsnippet', 5, 'caller_system_digest'));
        self::assertSame('1', $this->column('tx_nrllm_configuration', 6, 'snippets_in_caller_system_message'));
    }

    private function actAs(int $userUid): void
    {
        $backendUser     = $this->setUpBackendUser($userUid);
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    /**
     * @param array<string, array<int, array<string, int|string>>> $data
     */
    private function write(array $data): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, []);
        $dataHandler->process_datamap();
    }

    private function column(string $table, int $uid, string $column): string
    {
        $value = $this->getConnectionPool()->getConnectionForTable($table)
            ->select([$column], $table, ['uid' => $uid])->fetchOne();

        return is_scalar($value) ? (string)$value : '';
    }
}
