<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\UpdatePageMetadataTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * `pages.twitter_card` through `update_page_metadata`, with EXT:seo loaded
 * (ADR-194).
 *
 * The column, its `select` items and its `exclude` flag are EXT:seo's and are
 * read from the live TCA here rather than from a fixture, against a real
 * database and the real {@see \TYPO3\CMS\Core\DataHandling\DataHandler}.
 * {@see UpdatePageMetadataToolTest} runs WITHOUT the extension and holds the
 * other direction: there the field is neither offered nor accepted.
 */
#[CoversClass(UpdatePageMetadataTool::class)]
final class UpdatePageMetadataToolTwitterCardTest extends AbstractFunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'extbase',
        'fluid',
        'seo',
    ];

    /** A page every backend user may edit — the page-permission axis is open here. */
    private const PAGE = 2;

    private const STORED_CARD = 'summary';

    private UpdatePageMetadataTool $tool;

    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->connectionPool = $connectionPool;

        $this->importFixture('BeUsers.csv');

        $this->connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid' => self::PAGE, 'pid' => 0, 'title' => 'Open', 'doktype' => 1, 'slug' => '/open',
            'sorting' => 1, 'twitter_card' => self::STORED_CARD,
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
        ]);

        // `calcPerms()` returns nothing for a user in no group and for a page
        // outside the web mounts, so the non-admin needs a group whose DB mount
        // covers the page.
        $groups = $this->connectionPool->getConnectionForTable('be_groups');
        $groups->insert('be_groups', [
            'uid' => 7, 'pid' => 0, 'title' => 'Editors', 'db_mountpoints' => (string)self::PAGE,
        ]);
        // options = 3: inherit DB and file mounts from the groups.
        $groups->update('be_users', ['usergroup' => '7', 'options' => 3], ['uid' => 2]);

        // The DataHandler declares $GLOBALS['LANG'] as a prerequisite, and the
        // tool refuses to write without it (ADR-135).
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');

        $this->tool = new UpdatePageMetadataTool($this->connectionPool);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function theSpecOffersTheFieldWithTheValuesExtSeoDeclares(): void
    {
        $properties = $this->tool->getSpec()->parameters['properties'] ?? null;
        self::assertIsArray($properties);

        $property = $properties['twitter_card'] ?? null;
        self::assertIsArray($property);

        $description = $property['description'] ?? null;
        self::assertIsString($description);
        self::assertStringContainsString('"", "summary", "summary_large_image"', $description);
    }

    #[Test]
    public function aDeclaredCardTypeIsWritten(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            // Untrimmed on purpose: the value is compared and stored trimmed,
            // like every other field of this tool.
            ['uid' => self::PAGE, 'twitter_card' => ' summary_large_image '],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('twitter_card', $result->content);
        self::assertSame('summary_large_image', $this->pageRow()['twitter_card'] ?? null);
    }

    /**
     * The DataHandler does not compare a static select's value with its items,
     * so without the tool's own check this value would be stored and the
     * backend form would then show it as invalid.
     */
    #[Test]
    public function aValueExtSeoDoesNotDeclareIsRefusedAndNothingIsWritten(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['uid' => self::PAGE, 'title' => 'New title', 'twitter_card' => 'player'],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertTrue($result->isError);
        self::assertSame(
            'Refused: the value for "twitter_card" must be one of: "", "summary", "summary_large_image".',
            $result->content,
        );

        // The whole call is refused: the valid `title` beside it did not land.
        $row = $this->pageRow();
        self::assertSame(self::STORED_CARD, $row['twitter_card'] ?? null);
        self::assertSame('Open', $row['title'] ?? null);
        self::assertSame([], $this->sysLogUserIds());
    }

    /**
     * EXT:seo declares the empty string as an item ("no card type"), so it is a
     * value the tool writes — and the read-back confirms it.
     */
    #[Test]
    public function theEmptyStringClearsTheCardType(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['uid' => self::PAGE, 'twitter_card' => ''],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame('', $this->pageRow()['twitter_card'] ?? null);
    }

    /**
     * The list is the live TCA's and not the tool's: an item an installation
     * adds becomes writable, and an item it removes stops being writable.
     */
    #[Test]
    public function theLiveTcaDecidesWhichValuesAreAllowed(): void
    {
        $admin   = $this->setUpBackendUser(1);
        $context = ToolExecutionContext::fromBackendUser($admin);

        $tca = $GLOBALS['TCA'];
        self::assertIsArray($tca);
        $pages = $tca['pages'] ?? null;
        self::assertIsArray($pages);
        $columns = $pages['columns'] ?? null;
        self::assertIsArray($columns);
        $column = $columns['twitter_card'] ?? null;
        self::assertIsArray($column);
        $config = $column['config'] ?? null;
        self::assertIsArray($config);
        $config['items'] = [
            ['label' => 'Summary', 'value' => 'summary'],
            ['label' => 'Player', 'value' => 'player'],
        ];
        $column['config']          = $config;
        $columns['twitter_card']   = $column;
        $pages['columns']          = $columns;
        $tca['pages']              = $pages;
        $GLOBALS['TCA']            = $tca;

        $added = $this->tool->execute(['uid' => self::PAGE, 'twitter_card' => 'player'], $context);
        self::assertFalse($added->isError, $added->content);
        self::assertSame('player', $this->pageRow()['twitter_card'] ?? null);

        $removed = $this->tool->execute(['uid' => self::PAGE, 'twitter_card' => ''], $context);
        self::assertTrue($removed->isError);
        self::assertSame(
            'Refused: the value for "twitter_card" must be one of: "summary", "player".',
            $removed->content,
        );
        self::assertSame('player', $this->pageRow()['twitter_card'] ?? null);
    }

    #[Test]
    public function thePreviewNamesTheFieldWithItsStoredAndItsProposedValue(): void
    {
        $admin = $this->setUpBackendUser(1);

        $lines = $this->tool->previewCall(
            ['uid' => self::PAGE, 'twitter_card' => 'summary_large_image'],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertSame('Page [2] "Open" — 1 field(s):', $lines[0] ?? null);
        self::assertSame('twitter_card: "summary" → "summary_large_image"', $lines[1] ?? null);
        // A preview reads; it must not write.
        self::assertSame(self::STORED_CARD, $this->pageRow()['twitter_card'] ?? null);
    }

    #[Test]
    public function thePreviewOfAnUndeclaredValueIsTheRefusal(): void
    {
        $admin = $this->setUpBackendUser(1);

        $lines = $this->tool->previewCall(
            ['uid' => self::PAGE, 'twitter_card' => 'player'],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertSame(
            ['Refused: the value for "twitter_card" must be one of: "", "summary", "summary_large_image".'],
            $lines,
        );
    }

    /**
     * EXT:seo ships the column with the `exclude` flag. The DataHandler drops
     * such a field in silence for a user without the grant, and the read-back
     * that reports it for every other field reports it for this one.
     */
    #[Test]
    public function aNonAdminWithoutTheFieldGrantIsToldTheCardTypeDidNotTake(): void
    {
        $editor                                  = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify']      = 'pages';
        $editor->groupData['non_exclude_fields'] = '';

        $result = $this->tool->execute(
            ['uid' => self::PAGE, 'twitter_card' => 'summary_large_image'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('did not take', $result->content);
        self::assertStringContainsString('twitter_card', $result->content);
        self::assertSame(self::STORED_CARD, $this->pageRow()['twitter_card'] ?? null);
    }

    #[Test]
    public function aNonAdminWithTheFieldGrantWritesTheCardType(): void
    {
        $editor                                  = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify']      = 'pages';
        $editor->groupData['non_exclude_fields'] = 'pages:twitter_card';

        $result = $this->tool->execute(
            ['uid' => self::PAGE, 'twitter_card' => 'summary_large_image'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame('summary_large_image', $this->pageRow()['twitter_card'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function pageRow(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('*')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter(self::PAGE, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($row);

        return $row;
    }

    /**
     * The `sys_log` user ids the DataHandler recorded for the page.
     *
     * @return list<int>
     */
    private function sysLogUserIds(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_log');
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('userid')
            ->from('sys_log')
            ->where(
                $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter('pages')),
                $queryBuilder->expr()->eq('recuid', $queryBuilder->createNamedParameter(self::PAGE, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): int => (int)($row['userid'] ?? 0), $rows);
    }
}
