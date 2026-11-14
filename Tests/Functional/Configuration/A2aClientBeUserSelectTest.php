<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Configuration;

use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Form\FormDataCompiler;
use TYPO3\CMS\Backend\Form\FormDataGroup\TcaDatabaseRecord;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The be_user select of a new tx_nrllm_a2a_client record, compiled by
 * FormEngine's own data providers. AbstractItemProvider splits
 * foreign_table_where into WHERE and ORDER BY with a pattern that does not
 * accept the identifier quote of MySQL and MariaDB, so an ORDER BY written
 * with {#table}.{#field} stays inside the WHERE clause there; the resulting SQL
 * error becomes a flash message and an empty select. The failure shows with
 * `-d mariadb` or `-d mysql`: SQLite quotes with a character the pattern
 * accepts.
 */
final class A2aClientBeUserSelectTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $this->importFixture('BeUsersForA2aSelect.csv');

        $this->setUpBackendUser(1);
        $GLOBALS['LANG']          = GeneralUtility::makeInstance(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG'], $GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    #[Test]
    public function theNewRecordFormListsOnlyNonAdministratorsSortedByUsername(): void
    {
        $result = GeneralUtility::makeInstance(FormDataCompiler::class)->compile(
            [
                'request'    => $GLOBALS['TYPO3_REQUEST'],
                'tableName'  => 'tx_nrllm_a2a_client',
                'vanillaUid' => 0,
                'command'    => 'new',
            ],
            GeneralUtility::makeInstance(TcaDatabaseRecord::class),
        );

        $flashMessages = GeneralUtility::makeInstance(FlashMessageService::class)->getMessageQueueByIdentifier()->getAllMessages();
        self::assertSame([], array_map(static fn (FlashMessage $message): string => $message->getMessage(), $flashMessages), 'FormEngine reports no database error');

        $processedTca = $result['processedTca'] ?? null;
        self::assertIsArray($processedTca);
        $columns = $processedTca['columns'] ?? null;
        self::assertIsArray($columns);
        assert(isset($columns['be_user']));
        $beUser = $columns['be_user'];
        self::assertIsArray($beUser);
        $config = $beUser['config'] ?? null;
        self::assertIsArray($config);
        $items = $config['items'] ?? null;
        self::assertIsArray($items);

        $names = [];
        foreach ($items as $item) {
            self::assertIsArray($item);
            $names[] = is_string($item['label'] ?? null) ? $item['label'] : '?';
        }

        self::assertSame(['', 'alpha', 'editor', 'zeta'], $names);
    }
}
