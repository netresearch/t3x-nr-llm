<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\CreateRecordDraftTool;
use Netresearch\NrLlm\Service\Tool\RecordCreatorInterface;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\RewritesTheWriterFixtureItemHook;
use Netresearch\NrLlm\Tests\Fixtures\Tool\WriterFixtureItemCreatorTool;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeRecordCreatorTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * The write path of the generic record creator (ADR-197), against a real
 * database, the real DataHandler and a TCA table no narrow writer covers.
 *
 * The table comes from the fixture extension under
 * Tests/Functional/Fixtures/Extensions/nrllm_writer_fixture: one column of
 * every scalar type the tool may set, one relation it may not, record types
 * with their own showitem lists and one with `columnsOverrides`, a palette, a
 * required column, an exclude column, an authMode select, a text column with
 * a `min` and a decimal. A DataHandler hook fixture rewrites a row after every
 * check, for the read-back. The assertion this file exists for is that the
 * record is HIDDEN and carries exactly the fields the approver read.
 */
#[CoversClass(CreateRecordDraftTool::class)]
final class CreateRecordDraftToolTest extends AbstractFunctionalTestCase
{
    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend'];

    /** @var non-empty-string[] */
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'typo3conf/ext/nr_llm/Tests/Functional/Fixtures/Extensions/nrllm_writer_fixture',
    ];

    private const TABLE = 'tx_writerfixture_item';

    private const PLAIN_TABLE = 'tx_writerfixture_plain';

    /** A folder only the admin may edit content in. */
    private const FOLDER_CLOSED = 1;

    /** A folder every backend user may edit content in. */
    private const FOLDER_OPEN = 2;

    /**
     * Folders every backend user may edit content in, each with page TSconfig
     * the backend form enforces and the DataHandler does not (TCEFORM).
     */
    private const FOLDER_TCEFORM = 3;

    private const FOLDER_NO_NOTES = 4;

    private const FOLDER_BY_TYPE = 5;

    /** Rules written the way TypoScript allows and FormEngine reads: any truthy value. */
    private const FOLDER_TRUTHY = 6;

    /** Columns page TSconfig makes read-only in the backend form (`config.readOnly`). */
    private const FOLDER_READ_ONLY = 7;

    /** A folder whose page TSconfig gives new records a record type (`TCAdefaults`). */
    private const FOLDER_TCA_DEFAULTS = 8;

    /** A folder whose `TCAdefaults` names a value that is no record type of the table. */
    private const FOLDER_UNDECLARED_DEFAULT = 9;

    /** A folder whose `TCAdefaults` give tx_writerfixture_variant records type "2". */
    private const FOLDER_VARIANT_DEFAULTS = 10;

    private const VARIANT_TABLE = 'tx_writerfixture_variant';

    private const PUBLISHED_AT = 1789034400;

    private CreateRecordDraftTool $tool;

    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->connectionPool = $connectionPool;

        $this->importFixture('BeUsers.csv');

        $pages = $this->connectionPool->getConnectionForTable('pages');
        $pages->insert('pages', [
            'uid' => self::FOLDER_CLOSED, 'pid' => 0, 'title' => 'Closed', 'doktype' => 254, 'slug' => '/',
            'sorting' => 1,
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => 0,
        ]);
        $pages->insert('pages', [
            'uid' => self::FOLDER_OPEN, 'pid' => 0, 'title' => 'Open', 'doktype' => 254, 'slug' => '/open',
            'sorting' => 2,
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
        ]);

        $tceform = [
            self::FOLDER_TCEFORM  => "TCEFORM.tx_writerfixture_item {\n  featured.disabled = 1\n  tone.removeItems = loud\n  kind.keepItems = note, event\n}",
            self::FOLDER_NO_NOTES => 'TCEFORM.tx_writerfixture_item.kind.removeItems = note',
            self::FOLDER_BY_TYPE  => "TCEFORM.tx_writerfixture_item {\n  kind.types.event.removeItems = event\n  priority.types.story.disabled = 1\n  locked.types.note.config.readOnly = 0\n}",
            self::FOLDER_TRUTHY   => "TCEFORM.tx_writerfixture_item {\n  contact.disabled = true\n  featured.disabled = 0\n}",
            self::FOLDER_READ_ONLY => "TCEFORM.tx_writerfixture_item {\n  tone.config.readOnly = 1\n  priority.types.story.config.readOnly = 1\n  featured.config.readOnly = 0\n  mood.config.readOnly = 1\n  locked.config.readOnly = 0\n}",
            self::FOLDER_TCA_DEFAULTS       => 'TCAdefaults.tx_writerfixture_item.kind = story',
            self::FOLDER_UNDECLARED_DEFAULT => 'TCAdefaults.tx_writerfixture_item.kind = novel',
            self::FOLDER_VARIANT_DEFAULTS   => 'TCAdefaults.tx_writerfixture_variant.variant = 2',
        ];
        foreach ($tceform as $uid => $tsConfig) {
            $pages->insert('pages', [
                'uid' => $uid, 'pid' => 0, 'title' => 'TCEFORM ' . $uid, 'doktype' => 254, 'slug' => '/tceform-' . $uid,
                'sorting' => 2 + $uid, 'TSconfig' => $tsConfig,
                'perms_userid' => 1, 'perms_user' => Permission::ALL,
                'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
            ]);
        }

        $groups = $this->connectionPool->getConnectionForTable('be_groups');
        $groups->insert('be_groups', [
            'uid' => 7, 'pid' => 0, 'title' => 'Editors', 'db_mountpoints' => '1,2,' . self::FOLDER_VARIANT_DEFAULTS,
        ]);
        $groups->update('be_users', ['usergroup' => '7', 'options' => 3], ['uid' => 2]);

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');

        $this->tool = $this->toolWith(deniedTables: '');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function theCreatedRecordIsHiddenAndCarriesTheFields(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            $this->call([
                'title'        => 'Drafted item',
                'teaser'       => "Two lines\nof teaser",
                'kind'         => 'note',
                'published_at' => self::PUBLISHED_AT,
                'priority'     => 3,
                'featured'     => 1,
                'contact'      => 'editor@example.com',
                'tone'         => 'calm',
            ]),
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('Created hidden ' . self::TABLE . ' record', $result->content);
        self::assertStringContainsString('not visible until a human unhides it', $result->content);
        self::assertSame(self::TABLE, $result->writeTarget?->table);

        $row = $this->createdRecord();
        self::assertSame(1, (int)($row['hidden'] ?? 0), 'a drafted record must never be visible');
        self::assertSame(self::FOLDER_OPEN, (int)($row['pid'] ?? 0));
        self::assertSame(0, (int)($row['sys_language_uid'] ?? -1), 'the default language, always');
        self::assertSame('Drafted item', $row['title'] ?? null);
        self::assertSame("Two lines\nof teaser", $row['teaser'] ?? null);
        self::assertSame('note', $row['kind'] ?? null);
        self::assertSame(self::PUBLISHED_AT, (int)($row['published_at'] ?? 0));
        self::assertSame(3, (int)($row['priority'] ?? 0));
        self::assertSame(1, (int)($row['featured'] ?? 0));
        self::assertSame('editor@example.com', $row['contact'] ?? null);
        self::assertSame('calm', $row['tone'] ?? null);
        self::assertSame((int)($row['uid'] ?? 0), $result->writeTarget?->uid);
    }

    #[Test]
    public function anEditorCreatesWithTheGrantsTheBackendRequires(): void
    {
        $editor = $this->editorWithEveryGrant();

        $result = $this->tool->execute(
            $this->call(['title' => 'By the editor', 'featured' => 1, 'tone' => 'calm']),
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertFalse($result->isError, $result->content);

        $row = $this->createdRecord();
        self::assertSame(1, (int)($row['hidden'] ?? 0));
        self::assertSame(1, (int)($row['featured'] ?? 0));
        self::assertSame('calm', $row['tone'] ?? null);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedTables(): iterable
    {
        yield 'not in the TCA'       => ['tx_nowhere_domain_model_thing', 'not a table this installation declares'];
        yield 'on the read denylist' => ['be_users', 'system or sensitive table'];
        yield 'a sys_ table'         => ['sys_category', 'system or sensitive table'];
        yield 'pages'                => ['pages', 'create_page_draft'];
        yield 'tt_content'           => ['tt_content', 'create_content_element_draft'];
        yield 'no disabled column'   => [self::PLAIN_TABLE, 'no "disabled" enable column'];
    }

    #[Test]
    #[DataProvider('refusedTables')]
    public function aTableTheToolDoesNotServeIsRefused(string $table, string $expectedFragment): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['table' => $table, 'pid' => self::FOLDER_OPEN, 'fields' => ['title' => 'x']],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString($expectedFragment, $result->content);
        self::assertSame(0, $this->recordCount());
    }

    #[Test]
    public function aTableAnotherRegisteredCreatorDeclaresIsRefused(): void
    {
        $admin = $this->setUpBackendUser(1);
        $tool  = $this->toolWith(deniedTables: '', writers: [new FakeRecordCreatorTool('fixture_creator', [self::TABLE])]);

        $result = $tool->execute($this->call(['title' => 'x']), ToolExecutionContext::fromBackendUser($admin));

        self::assertTrue($result->isError);
        self::assertStringContainsString('fixture_creator', $result->content);
        self::assertSame(0, $this->recordCount());

        // The other direction: a creator declaring some other table does not
        // stand in the way.
        $other  = $this->toolWith(deniedTables: '', writers: [new FakeRecordCreatorTool('other_creator', [self::PLAIN_TABLE])]);
        $result = $other->execute($this->call(['title' => 'x']), ToolExecutionContext::fromBackendUser($admin));

        self::assertFalse($result->isError, $result->content);
        self::assertSame(1, $this->recordCount());
    }

    /**
     * The withdrawal is only real if the tool the CONTAINER builds sees a
     * creator another extension registers through the `nr_llm.tool` tag. The
     * fixture extension registers one for this very table in its
     * Configuration/Services.yaml; the hand-built tools of the other tests
     * cannot show that the tagged iterator is wired.
     */
    #[Test]
    public function theContainerBuiltToolStepsBackFromATableAnExtensionCreatorDeclares(): void
    {
        $admin = $this->setUpBackendUser(1);

        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $tool = $registry->get('create_record_draft');
        self::assertInstanceOf(CreateRecordDraftTool::class, $tool);

        $result = $tool->execute($this->call(['title' => 'x']), ToolExecutionContext::fromBackendUser($admin));

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString(WriterFixtureItemCreatorTool::NAME, $result->content);
        self::assertSame(0, $this->recordCount());
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function builtinCreators(): iterable
    {
        yield 'content element' => ['create_content_element_draft', ['tt_content']];
        yield 'page'            => ['create_page_draft', ['pages']];
        yield 'translation'     => ['create_translation_draft', ['pages', 'tt_content']];
    }

    /**
     * @param list<string> $tables
     */
    #[Test]
    #[DataProvider('builtinCreators')]
    public function theBuiltinCreatorsDeclareTheTablesTheyCreateIn(string $name, array $tables): void
    {
        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);

        $creator = $registry->get($name);
        self::assertInstanceOf(RecordCreatorInterface::class, $creator);
        self::assertSame($tables, $creator->getCreatedTables());
    }

    #[Test]
    public function aTableDeniedByTheExtensionConfigurationIsRefused(): void
    {
        $admin = $this->setUpBackendUser(1);
        $tool  = $this->toolWith(deniedTables: 'tx_other_table, ' . self::TABLE);

        $result = $tool->execute($this->call(['title' => 'x']), ToolExecutionContext::fromBackendUser($admin));

        self::assertTrue($result->isError);
        self::assertStringContainsString('tools.createRecordDraft.deniedTables', $result->content);
        self::assertSame(0, $this->recordCount());
    }

    /**
     * The deny-list is only a control if the tool the CONTAINER builds reads
     * it. The constructor takes the configuration service as an optional
     * argument and treats its absence as "no exclusions", so a tool the
     * container wired without it would ignore the list in silence — the
     * hand-built tools of the other tests cannot show that.
     */
    #[Test]
    public function theContainerBuiltToolHonoursTheDenyList(): void
    {
        $admin = $this->setUpBackendUser(1);
        $this->setDeniedTables(self::TABLE);

        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $tool = $registry->get('create_record_draft');
        self::assertInstanceOf(CreateRecordDraftTool::class, $tool);

        $result = $tool->execute($this->call(['title' => 'x']), ToolExecutionContext::fromBackendUser($admin));

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('tools.createRecordDraft.deniedTables', $result->content);
        self::assertSame(0, $this->recordCount());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusedFields(): iterable
    {
        $valid = ['title' => 'x'];

        yield 'a relation column'          => [$valid + ['related' => '1'], 'only scalar columns'];
        yield 'hidden'                     => [$valid + ['hidden' => 0], 'never arguments'];
        yield 'pid as a field'             => [$valid + ['pid' => 1], 'never arguments'];
        yield 'uid'                        => [$valid + ['uid' => 9], 'never arguments'];
        yield 'the language column'        => [$valid + ['sys_language_uid' => 1], 'never arguments'];
        yield 'the translation parent'     => [$valid + ['l10n_parent' => 1], 'never arguments'];
        yield 'starttime'                  => [$valid + ['starttime' => 1], 'never arguments'];
        yield 'fe_group'                   => [$valid + ['fe_group' => '-2'], 'never arguments'];
        yield 'an unknown column'          => [$valid + ['subtitle' => 'x'], 'not a column of ' . self::TABLE];
        yield 'not in the type showitem'   => [$valid + ['kind' => 'story', 'teaser' => 'x'], 'not shown for record type "story"'];
        yield 'select outside the items'   => [$valid + ['kind' => 'novel'], 'must be one of'];
        yield 'number outside the range'   => [$valid + ['priority' => 9], 'outside the range 1..5'];
        yield 'a check that is not 0 or 1' => [$valid + ['featured' => 2], 'must be 0 or 1'];
        yield 'an invalid email'           => [$valid + ['contact' => 'nobody'], 'not a valid e-mail address'];
        yield 'an unparseable datetime'    => [$valid + ['published_at' => 'yesterday-ish'], 'UNIX timestamp or an ISO 8601'];
        yield 'a title over max'           => [['title' => str_repeat('a', 101)], 'exceeds 100 characters'];
        yield 'a teaser below its min'     => [$valid + ['teaser' => 'short'], 'at least 8 characters'];
        yield 'required column missing'    => [['teaser' => 'A teaser long enough'], '"title" is required'];
        yield 'required column empty'      => [['title' => '   '], '"title" is required'];
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[Test]
    #[DataProvider('refusedFields')]
    public function aFieldTheToolDoesNotSetIsRefusedAndNothingIsCreated(array $fields, string $expectedFragment): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute($this->call($fields), ToolExecutionContext::fromBackendUser($admin));

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString($expectedFragment, $result->content);
        self::assertSame(0, $this->recordCount());
    }

    #[Test]
    public function aColumnInsideAPaletteOfTheRecordTypeIsAccepted(): void
    {
        $admin = $this->setUpBackendUser(1);

        // `priority` is reachable only through the `timing` palette of both
        // record types; a showitem walk that did not expand palettes would
        // refuse it.
        $result = $this->tool->execute(
            $this->call(['title' => 'x', 'kind' => 'story', 'priority' => 2]),
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(2, (int)($this->createdRecord()['priority'] ?? 0));
    }

    #[Test]
    public function anIsoDateTimeIsStoredAsItsTimestamp(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            $this->call(['title' => 'x', 'published_at' => '2026-09-21T10:00:00+02:00']),
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(1789977600, (int)($this->createdRecord()['published_at'] ?? 0));
    }

    /**
     * The DataHandler stores every decimal through number_format($value, 2)
     * (checkValueForNumber()): 1.234 would become 1.23, a record the approver
     * never read, and 0.125 or 2.675 would round up to a value the read-back
     * then calls wrong. A third decimal place is refused before the write.
     *
     * @return iterable<string, array{float}>
     */
    public static function decimalsTypo3WouldRound(): iterable
    {
        yield 'rounded down' => [1.234];
        yield 'rounded up at the half' => [0.125];
        yield 'a half that is below it in binary' => [2.675];
    }

    #[Test]
    #[DataProvider('decimalsTypo3WouldRound')]
    public function aDecimalWithMoreThanTwoPlacesIsRefusedAndNothingIsCreated(float $rating): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            $this->call(['title' => 'x', 'rating' => $rating]),
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('more than two decimal places', $result->content);
        self::assertSame(0, $this->recordCount());
    }

    /**
     * What TYPO3 stores unchanged is created and kept — a whole number and a
     * trailing zero included, which a database may hand back as 4 or 3.1.
     *
     * @return iterable<string, array{float|int|string, float}>
     */
    public static function decimalsTypo3StoresUnchanged(): iterable
    {
        yield 'two places'       => [3.25, 3.25];
        yield 'a whole number'   => [4, 4.0];
        yield 'a trailing zero'  => ['3.10', 3.1];
    }

    #[Test]
    #[DataProvider('decimalsTypo3StoresUnchanged')]
    public function aDecimalWithAtMostTwoPlacesIsStoredAndKept(float|int|string $rating, float $stored): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            $this->call(['title' => 'x', 'rating' => $rating]),
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(1, $this->undeletedRecordCount());
        $value = $this->createdRecord()['rating'] ?? null;
        self::assertIsNumeric($value);
        self::assertEqualsWithDelta($stored, (float)$value, 0.0001);
    }

    /**
     * `teaser` is required only through the `event` type's columnsOverrides.
     * The DataHandler validates against that per-type configuration and drops
     * the empty value in silence, so the tool must refuse first.
     */
    #[Test]
    public function aColumnRequiredOnlyByTheRecordTypeIsRefusedWhenMissing(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            $this->call(['title' => 'x', 'kind' => 'event']),
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('"teaser" is required for record type "event"', $result->content);
        self::assertSame(0, $this->recordCount());
    }

    /**
     * `body` is rich text only through the `event` type's columnsOverrides.
     * The RTE transformation rewrites its markup on the way in, so a
     * column-by-column read-back against the base configuration would call the
     * correct record wrong and delete it.
     */
    #[Test]
    public function aColumnThatIsRichTextOnlyByTheRecordTypeIsCreatedAndKept(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            $this->call(['title' => 'x', 'kind' => 'event', 'teaser' => 'An event teaser', 'body' => '<p>One</p><p>Two</p>']),
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(1, $this->undeletedRecordCount());
        $body = $this->createdRecord()['body'] ?? null;
        self::assertIsString($body);
        self::assertStringContainsString('One', $body);
        self::assertStringContainsString('Two', $body);
    }

    #[Test]
    public function anEditorWithoutTablesModifyIsRefused(): void
    {
        $editor                             = $this->editorWithEveryGrant();
        $editor->groupData['tables_modify'] = 'pages';

        $result = $this->tool->execute($this->call(['title' => 'x']), ToolExecutionContext::fromBackendUser($editor));

        self::assertTrue($result->isError);
        self::assertStringContainsString('tables_modify', $result->content);
        self::assertSame(0, $this->recordCount());
    }

    #[Test]
    public function anEditorWithoutContentEditOnTheFolderIsRefusedWithTheNeutralWords(): void
    {
        $editor = $this->editorWithEveryGrant();

        $result = $this->tool->execute(
            ['table' => self::TABLE, 'pid' => self::FOLDER_CLOSED, 'fields' => ['title' => 'x']],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        self::assertSame('Page not found or not permitted.', $result->content);
        self::assertSame(0, $this->recordCount());
    }

    /**
     * The folder grants content edit to everybody, but it lies outside the
     * editor's web mounts — the backend never shows it to them, and page
     * permissions alone must not open it.
     */
    #[Test]
    public function anEditorOutsideTheirWebMountsIsRefusedWithTheNeutralWords(): void
    {
        $editor                         = $this->editorWithEveryGrant();
        $editor->groupData['webmounts'] = (string)self::FOLDER_CLOSED;

        $result = $this->tool->execute($this->call(['title' => 'x']), ToolExecutionContext::fromBackendUser($editor));

        self::assertTrue($result->isError);
        self::assertSame('Page not found or not permitted.', $result->content);
        self::assertSame(0, $this->recordCount());
    }

    /**
     * Page TSconfig TCEFORM is enforced by the backend form only; the
     * DataHandler writes a disabled column or a removed item without a word.
     * The tool refuses what the form would not offer on that page, and names
     * the rule.
     *
     * @return iterable<string, array{int, array<string, mixed>, string}>
     */
    public static function valuesPageTsConfigRemoves(): iterable
    {
        yield 'a disabled column' => [self::FOLDER_TCEFORM, ['title' => 'x', 'featured' => 1], 'TCEFORM.tx_writerfixture_item.featured.disabled'];
        yield 'a removed item'    => [self::FOLDER_TCEFORM, ['title' => 'x', 'tone' => 'loud'], 'TCEFORM.tx_writerfixture_item.tone.removeItems'];
        yield 'a record type outside keepItems' => [self::FOLDER_TCEFORM, ['title' => 'x', 'kind' => 'story'], 'TCEFORM.tx_writerfixture_item.kind.keepItems'];
        yield 'the default record type inside removeItems' => [self::FOLDER_NO_NOTES, ['title' => 'x'], 'TCEFORM.tx_writerfixture_item.kind.removeItems'];
        yield 'a record type removed for that type' => [self::FOLDER_BY_TYPE, ['title' => 'x', 'kind' => 'event', 'teaser' => 'An event teaser'], 'TCEFORM.tx_writerfixture_item.kind.types.event.removeItems'];
        yield 'a column disabled for that type' => [self::FOLDER_BY_TYPE, ['title' => 'x', 'kind' => 'story', 'priority' => 2], 'TCEFORM.tx_writerfixture_item.priority.types.story.disabled'];
        // FormEngine drops the field on any truthy value (SingleFieldContainer), not only on 1.
        yield 'a column disabled with the word true' => [self::FOLDER_TRUTHY, ['title' => 'x', 'contact' => 'editor@example.com'], 'TCEFORM.tx_writerfixture_item.contact.disabled'];
        // FormEngine renders it read-only (FormEngineUtility::overrideFieldConf()); the DataHandler stores it.
        yield 'a column read-only by page TSconfig' => [self::FOLDER_READ_ONLY, ['title' => 'x', 'tone' => 'calm'], 'TCEFORM.tx_writerfixture_item.tone.config.readOnly'];
        yield 'a column read-only for that type' => [self::FOLDER_READ_ONLY, ['title' => 'x', 'kind' => 'story', 'priority' => 2], 'TCEFORM.tx_writerfixture_item.priority.types.story.config.readOnly'];
        // No page rule: the TCA's own `readOnly` decides.
        yield 'a column read-only in the TCA' => [self::FOLDER_OPEN, ['title' => 'x', 'locked' => 'Set'], '"locked" is read-only in the TCA'];
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[Test]
    #[DataProvider('valuesPageTsConfigRemoves')]
    public function aValueThePageTsConfigRemovesIsRefusedAndTheRuleNamed(int $pid, array $fields, string $rule): void
    {
        $admin = $this->setUpBackendUser(1);

        $arguments = ['table' => self::TABLE, 'pid' => $pid, 'fields' => $fields];
        $result    = $this->tool->execute($arguments, ToolExecutionContext::fromBackendUser($admin));

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString($rule, $result->content);
        self::assertSame(0, $this->recordCount());
        self::assertSame([$result->content], $this->tool->previewCall($arguments, ToolExecutionContext::fromBackendUser($admin)));
    }

    /**
     * The other direction: what the rules leave on the page is written.
     *
     * @return iterable<string, array{int, array<string, mixed>}>
     */
    public static function valuesPageTsConfigLeaves(): iterable
    {
        yield 'a kept item and a kept record type' => [self::FOLDER_TCEFORM, ['title' => 'x', 'kind' => 'note', 'tone' => 'calm']];
        yield 'another record type than the removed one' => [self::FOLDER_NO_NOTES, ['title' => 'x', 'kind' => 'story']];
        yield 'a column disabled only for another type' => [self::FOLDER_BY_TYPE, ['title' => 'x', 'kind' => 'note', 'priority' => 2]];
        yield 'a column whose disabled rule is 0' => [self::FOLDER_TRUTHY, ['title' => 'x', 'featured' => 1]];
        yield 'a column read-only only for another type' => [self::FOLDER_READ_ONLY, ['title' => 'x', 'kind' => 'note', 'priority' => 2]];
        yield 'a column whose readOnly rule is 0' => [self::FOLDER_READ_ONLY, ['title' => 'x', 'featured' => 1]];
        // FormEngine takes no `config.` override for a radio, so the rule does nothing there.
        yield 'a radio a readOnly rule cannot reach' => [self::FOLDER_READ_ONLY, ['title' => 'x', 'mood' => 'dark']];
        // FormEngine merges the page rule over the TCA (FormEngineUtility::overrideFieldConf()), so 0 lifts it.
        yield 'a TCA read-only column page TSconfig lifts' => [self::FOLDER_READ_ONLY, ['title' => 'x', 'locked' => 'Set']];
        yield 'a TCA read-only column lifted for that type' => [self::FOLDER_BY_TYPE, ['title' => 'x', 'kind' => 'note', 'locked' => 'Set']];
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[Test]
    #[DataProvider('valuesPageTsConfigLeaves')]
    public function aValueThePageTsConfigLeavesIsCreated(int $pid, array $fields): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(['table' => self::TABLE, 'pid' => $pid, 'fields' => $fields], ToolExecutionContext::fromBackendUser($admin));

        self::assertFalse($result->isError, $result->content);
        self::assertSame(1, $this->undeletedRecordCount());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function missingExcludeFieldGrants(): iterable
    {
        // `hidden` is set by the tool itself, so its grant is asked for even
        // though no argument names it.
        yield 'hidden'   => [self::TABLE . ':featured,' . self::TABLE . ':starttime', self::TABLE . ':hidden'];
        yield 'featured' => [self::TABLE . ':hidden', self::TABLE . ':featured'];
    }

    #[Test]
    #[DataProvider('missingExcludeFieldGrants')]
    public function anEditorWithoutTheExcludeFieldGrantIsRefusedBeforeTheWrite(string $granted, string $expected): void
    {
        $editor                                  = $this->editorWithEveryGrant();
        $editor->groupData['non_exclude_fields'] = $granted;

        $result = $this->tool->execute(
            $this->call(['title' => 'x', 'featured' => 1]),
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('exclude field', $result->content);
        self::assertStringContainsString($expected, $result->content);
        self::assertSame(0, $this->recordCount(), 'the grant is asked before anything is written');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function rewrittenRecords(): iterable
    {
        yield 'the hidden flag' => [['title' => 'rewrite:hidden'], 'it is not hidden'];
        yield 'the page'        => [['title' => 'rewrite:pid'], 'the page differs'];
        yield 'a field'         => [['title' => 'rewrite:teaser', 'teaser' => 'A teaser long enough'], 'these fields did not take: teaser'];
        // Neither is an argument: the tool writes the default language and the
        // record type it resolved, and verifies both.
        yield 'the language'    => [['title' => 'rewrite:language'], 'the language differs (sys_language_uid)'];
        yield 'the record type' => [['title' => 'rewrite:type'], 'the record type differs (kind)'];
    }

    /**
     * A silent rewrite no pre-check can model: an installation's own
     * DataHandler hook changes the row after every check. The read-back must
     * name what differs, and the record nobody approved in that shape must not
     * stay — least of all a visible one.
     *
     * @param array<string, mixed> $fields
     */
    #[Test]
    #[DataProvider('rewrittenRecords')]
    public function theReadBackNamesWhatWasRewrittenAndDeletesTheRecord(array $fields, string $expectedFragment): void
    {
        $admin = $this->setUpBackendUser(1);
        $this->registerRewritingHook();

        try {
            $result = $this->tool->execute($this->call($fields), ToolExecutionContext::fromBackendUser($admin));
        } finally {
            $this->unregisterRewritingHook();
        }

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString($expectedFragment, $result->content);
        self::assertStringContainsString('rewritten by TYPO3', $result->content);
        self::assertStringContainsString('was deleted again', $result->content);
        self::assertSame(1, $this->recordCount(), 'the row exists, flagged deleted');
        self::assertSame(0, $this->undeletedRecordCount());
    }

    /**
     * The other direction: where the type column declares no default, the
     * record type is core's fallback ("1" here, as no "0" is declared). The
     * tool writes that type rather than leaving the column to its database
     * default (''), so the record carries the type its fields were checked
     * against, the read-back finds it, and the record stays.
     */
    #[Test]
    public function aRecordTypeFromCoresFallbackIsWrittenAndKept(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['table' => 'tx_writerfixture_variant', 'pid' => self::FOLDER_OPEN, 'fields' => ['title' => 'x']],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        // Restrictions removed: the record is hidden, which the default ones filter out.
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_writerfixture_variant');
        $queryBuilder->getRestrictions()->removeAll();
        $count = (int)$queryBuilder
            ->count('uid')
            ->from('tx_writerfixture_variant')
            ->where($queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
        self::assertSame(1, $count, 'the record is kept');

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_writerfixture_variant');
        $queryBuilder->getRestrictions()->removeAll();
        $variant = $queryBuilder
            ->select('variant')
            ->from('tx_writerfixture_variant')
            ->executeQuery()
            ->fetchOne();
        self::assertSame('1', $variant, 'the record carries the record type it was checked against');
    }

    /**
     * The record type the tool writes without an argument naming it is written
     * only where the acting user may write the type column. Without the
     * field-level grant the DataHandler would drop the column in silence, so
     * the tool leaves it out and reads back what the DataHandler stores itself:
     * the `TCAdefaults` type, which it applies to a new record either way, or
     * the column's database default where the type is core's own fallback —
     * which the read-back then does not compare.
     *
     * @return iterable<string, array{bool, int, string}>
     */
    public static function recordTypesByTypeColumnGrant(): iterable
    {
        yield "core's fallback, without the grant" => [false, self::FOLDER_OPEN, ''];
        yield "core's fallback, with the grant"    => [true, self::FOLDER_OPEN, '1'];
        yield 'TCAdefaults, without the grant'     => [false, self::FOLDER_VARIANT_DEFAULTS, '2'];
    }

    #[Test]
    #[DataProvider('recordTypesByTypeColumnGrant')]
    public function theRecordTypeIsWrittenOnlyWhereTheUserMayWriteItAndTheRecordIsKept(bool $granted, int $pid, string $stored): void
    {
        $editor                                  = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify']      = self::VARIANT_TABLE;
        $editor->groupData['non_exclude_fields'] = $granted ? self::VARIANT_TABLE . ':variant' : '';

        $result = $this->tool->execute(
            ['table' => self::VARIANT_TABLE, 'pid' => $pid, 'fields' => ['title' => 'x']],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertFalse($result->isError, $result->content);
        $rows = $this->variantRows();
        self::assertCount(1, $rows);
        self::assertSame(0, (int)($rows[0]['deleted'] ?? 1), 'the record is kept');
        self::assertSame(1, (int)($rows[0]['hidden'] ?? 0));
        self::assertSame($stored, $rows[0]['variant'] ?? null);
    }

    /**
     * Page TSconfig `TCAdefaults` gives a new record its type where the call
     * does not (DataHandler::applyDefaultsForFieldArray()). The tool resolves
     * the same type and writes it, so the read-back finds it and the record
     * stays.
     */
    #[Test]
    public function aRecordTypeFromThePagesTcaDefaultsIsWrittenAndKept(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['table' => self::TABLE, 'pid' => self::FOLDER_TCA_DEFAULTS, 'fields' => ['title' => 'x', 'featured' => 1]],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(1, $this->undeletedRecordCount());
        self::assertSame('story', $this->createdRecord()['kind'] ?? null);
    }

    /**
     * And the fields are checked against that type: `teaser` is shown for the
     * column's default type "note" but not for "story".
     */
    #[Test]
    public function theFieldsAreCheckedAgainstTheRecordTypeThePagesTcaDefaultsGive(): void
    {
        $admin = $this->setUpBackendUser(1);

        $arguments = ['table' => self::TABLE, 'pid' => self::FOLDER_TCA_DEFAULTS, 'fields' => ['title' => 'x', 'teaser' => 'A teaser long enough']];
        $result    = $this->tool->execute($arguments, ToolExecutionContext::fromBackendUser($admin));

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('not shown for record type "story"', $result->content);
        self::assertSame(0, $this->recordCount());
        self::assertSame([$result->content], $this->tool->previewCall($arguments, ToolExecutionContext::fromBackendUser($admin)));
    }

    /**
     * The page's TSconfig is read only once the page is authorised: a user
     * outside the folder's web mount gets the neutral words, not a refusal
     * that names the record type the folder's `TCAdefaults` give.
     */
    #[Test]
    public function aUserWithoutAccessToThePageLearnsNothingOfItsTcaDefaults(): void
    {
        $editor = $this->editorWithEveryGrant();

        $result = $this->tool->execute(
            ['table' => self::TABLE, 'pid' => self::FOLDER_TCA_DEFAULTS, 'fields' => ['title' => 'x', 'teaser' => 'A teaser long enough']],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        self::assertSame('Page not found or not permitted.', $result->content);
        self::assertSame(0, $this->recordCount());
    }

    /**
     * The acting user's TSconfig `TCAdefaults` gives the type where the page
     * sets none; where both do, the page's wins — the DataHandler merges the
     * page's defaults over the user's.
     *
     * @return iterable<string, array{int, array<string, mixed>, string}>
     */
    public static function recordTypesFromTheUsersTcaDefaults(): iterable
    {
        yield "the user's where the page sets none" => [self::FOLDER_OPEN, ['title' => 'x', 'teaser' => 'An event teaser'], 'event'];
        yield "the page's over the user's"          => [self::FOLDER_TCA_DEFAULTS, ['title' => 'x'], 'story'];
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[Test]
    #[DataProvider('recordTypesFromTheUsersTcaDefaults')]
    public function theUsersTcaDefaultsGiveTheRecordTypeUnlessThePageSetsOne(int $pid, array $fields, string $kind): void
    {
        $this->connectionPool->getConnectionForTable('be_users')
            ->update('be_users', ['TSconfig' => 'TCAdefaults.tx_writerfixture_item.kind = event'], ['uid' => 1]);
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['table' => self::TABLE, 'pid' => $pid, 'fields' => $fields],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(1, $this->undeletedRecordCount());
        self::assertSame($kind, $this->createdRecord()['kind'] ?? null);
    }

    /**
     * A `TCAdefaults` value that names no record type of the table would be
     * stored as it is and shown as core's fallback type; the tool refuses it
     * and names the rule rather than guessing which type was meant.
     */
    #[Test]
    public function aTcaDefaultThatNamesNoRecordTypeIsRefusedAndNamed(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['table' => self::TABLE, 'pid' => self::FOLDER_UNDECLARED_DEFAULT, 'fields' => ['title' => 'x']],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('TCAdefaults.tx_writerfixture_item.kind', $result->content);
        self::assertSame(0, $this->recordCount());
    }

    #[Test]
    public function thePreviewNamesTheColumnsWithTheirEnglishLabelsAndWritesNothing(): void
    {
        $admin = $this->setUpBackendUser(1);

        $lines = $this->tool->previewCall(
            $this->call(['title' => 'Proposed', 'kind' => 'note', 'priority' => 4, 'published_at' => self::PUBLISHED_AT]),
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertSame([
            'New "Fixture item" record (' . self::TABLE . ') on page [2] "Open":',
            // `title` carries a `LLL:` label; it is resolved in English, not printed raw.
            'title (Title): "Proposed"',
            // A select shows its value and the item's label.
            'kind (Kind): "note" (Note)',
            'priority (Priority): "4"',
            // A timestamp as an ISO 8601 date-time in UTC.
            'published_at (Published at): "2026-09-10T10:00:00+00:00"',
            'language: default',
            'visibility: hidden — a human must unhide it before anyone sees it',
        ], $lines);

        self::assertSame(0, $this->recordCount(), 'a preview must not create anything');
    }

    /**
     * The label the backend form shows for the record type: `columnsOverrides`
     * replaces the column's own, a showitem `field;Label` replaces both.
     */
    #[Test]
    public function thePreviewNamesTheColumnsWithTheLabelsOfTheRecordType(): void
    {
        $admin = $this->setUpBackendUser(1);

        $lines = $this->tool->previewCall(
            $this->call(['title' => 'Proposed', 'kind' => 'event', 'teaser' => 'An event teaser']),
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertSame([
            'New "Fixture item" record (' . self::TABLE . ') on page [2] "Open":',
            'title (Event title): "Proposed"',
            'kind (Kind): "event" (Event)',
            'teaser (Event teaser): "An event teaser"',
            'language: default',
            'visibility: hidden — a human must unhide it before anyone sees it',
        ], $lines);
    }

    /**
     * ADR-184 compares the preview lines byte for byte on resume, and a resume
     * can run in another request, another worker, for another viewer. The
     * labels must therefore not come from the ambient language service.
     */
    #[Test]
    public function thePreviewDoesNotDependOnTheAmbientLanguage(): void
    {
        $admin     = $this->setUpBackendUser(1);
        $arguments = $this->call(['title' => 'Proposed', 'kind' => 'note', 'published_at' => self::PUBLISHED_AT]);

        $before = $this->tool->previewCall($arguments, ToolExecutionContext::fromBackendUser($admin));

        $ambient = self::createStub(LanguageService::class);
        $ambient->method('sL')->willReturn('Label in the viewer language');
        $GLOBALS['LANG'] = $ambient;

        self::assertSame($before, $this->tool->previewCall($arguments, ToolExecutionContext::fromBackendUser($admin)));
    }

    /**
     * The English labels come from the language service factory the
     * constructor takes as an optional argument; a tool the container wired
     * without it would print column names only, in silence. The container's
     * own tool cannot preview the fixture table (the fixture extension's
     * creator claims it), so the wiring is read directly.
     */
    #[Test]
    public function theContainerBuiltToolResolvesLabelsInEnglish(): void
    {
        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $tool = $registry->get('create_record_draft');
        self::assertInstanceOf(CreateRecordDraftTool::class, $tool);

        $factory = (new ReflectionProperty(CreateRecordDraftTool::class, 'languageServiceFactory'))->getValue($tool);
        self::assertInstanceOf(LanguageServiceFactory::class, $factory);
    }

    #[Test]
    public function thePreviewMirrorsTheRefusal(): void
    {
        $admin = $this->setUpBackendUser(1);

        $lines = $this->tool->previewCall($this->call(['title' => 'x', 'kind' => 'novel']), ToolExecutionContext::fromBackendUser($admin));

        self::assertCount(1, $lines);
        self::assertStringContainsString('must be one of', $lines[0]);
    }

    #[Test]
    public function theViewerGateAnswersForTheViewerNotTheRun(): void
    {
        $admin  = $this->setUpBackendUser(1);
        $editor = $this->setUpBackendUser(2);

        $arguments = ['table' => self::TABLE, 'pid' => self::FOLDER_CLOSED, 'fields' => ['title' => 'x']];

        self::assertTrue($this->tool->mayViewerReadPreview($arguments, $admin));
        self::assertFalse($this->tool->mayViewerReadPreview($arguments, $editor));
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function call(array $fields): array
    {
        return ['table' => self::TABLE, 'pid' => self::FOLDER_OPEN, 'fields' => $fields];
    }

    /**
     * @param list<ToolInterface> $writers
     */
    private function toolWith(string $deniedTables, array $writers = []): CreateRecordDraftTool
    {
        $this->setDeniedTables($deniedTables);

        $languageServiceFactory = $this->get(LanguageServiceFactory::class);
        self::assertInstanceOf(LanguageServiceFactory::class, $languageServiceFactory);

        return new CreateRecordDraftTool(
            $this->connectionPool,
            new TableReadAccessService(),
            $writers,
            new ExtensionConfiguration(),
            $languageServiceFactory,
        );
    }

    /**
     * Write `tools.createRecordDraft.deniedTables` where
     * {@see ExtensionConfiguration::get()} reads it, narrowing the untyped
     * global step by step.
     */
    private function setDeniedTables(string $deniedTables): void
    {
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        if (!is_array($confVars)) {
            $confVars = [];
        }

        $extensions = $confVars['EXTENSIONS'] ?? [];
        if (!is_array($extensions)) {
            $extensions = [];
        }

        $nrLlm = $extensions['nr_llm'] ?? [];
        if (!is_array($nrLlm)) {
            $nrLlm = [];
        }

        $tools = $nrLlm['tools'] ?? [];
        if (!is_array($tools)) {
            $tools = [];
        }

        $tools['createRecordDraft']  = ['deniedTables' => $deniedTables];
        $nrLlm['tools']              = $tools;
        $extensions['nr_llm']        = $nrLlm;
        $confVars['EXTENSIONS']      = $extensions;
        $GLOBALS['TYPO3_CONF_VARS']  = $confVars;
    }

    private function registerRewritingHook(): void
    {
        $hooks   = $this->dataHandlerHooks();
        $hooks[] = RewritesTheWriterFixtureItemHook::class;
        $this->storeDataHandlerHooks($hooks);
    }

    private function unregisterRewritingHook(): void
    {
        $this->storeDataHandlerHooks(array_values(array_filter(
            $this->dataHandlerHooks(),
            static fn(mixed $className): bool => $className !== RewritesTheWriterFixtureItemHook::class,
        )));
    }

    /**
     * The `processDatamapClass` hook list, narrowed step by step — `$GLOBALS`
     * is untyped.
     *
     * @return array<array-key, mixed>
     */
    private function dataHandlerHooks(): array
    {
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        $options  = is_array($confVars) ? ($confVars['SC_OPTIONS'] ?? []) : [];
        $tcemain  = is_array($options) ? ($options['t3lib/class.t3lib_tcemain.php'] ?? []) : [];
        $hooks    = is_array($tcemain) ? ($tcemain['processDatamapClass'] ?? []) : [];

        return is_array($hooks) ? $hooks : [];
    }

    /**
     * @param array<array-key, mixed> $hooks
     */
    private function storeDataHandlerHooks(array $hooks): void
    {
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        if (!is_array($confVars)) {
            $confVars = [];
        }

        $options = $confVars['SC_OPTIONS'] ?? [];
        if (!is_array($options)) {
            $options = [];
        }

        $tcemain = $options['t3lib/class.t3lib_tcemain.php'] ?? [];
        if (!is_array($tcemain)) {
            $tcemain = [];
        }

        $tcemain['processDatamapClass']           = $hooks;
        $options['t3lib/class.t3lib_tcemain.php'] = $tcemain;
        $confVars['SC_OPTIONS']                   = $options;
        $GLOBALS['TYPO3_CONF_VARS']               = $confVars;
    }

    /**
     * The editor with every grant the DataHandler asks for on this table.
     */
    private function editorWithEveryGrant(): BackendUserAuthentication
    {
        $editor                                  = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify']      = self::TABLE;
        $editor->groupData['non_exclude_fields'] = self::TABLE . ':hidden,' . self::TABLE . ':featured';
        $editor->groupData['explicit_allowdeny'] = self::TABLE . ':tone:calm';

        return $editor;
    }

    /**
     * The one record this tool created, asserting there is exactly one.
     *
     * @return array<string, mixed>
     */
    private function createdRecord(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->executeQuery()
            ->fetchAllAssociative();

        self::assertCount(1, $rows, 'exactly one record must have been created');

        return $rows[0];
    }

    /**
     * Every row of the variant fixture table, deleted and hidden ones included.
     *
     * @return list<array<string, mixed>>
     */
    private function variantRows(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::VARIANT_TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('uid', 'deleted', 'hidden', 'variant')
            ->from(self::VARIANT_TABLE)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    private function recordCount(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->executeQuery()
            ->fetchOne();
    }

    private function undeletedRecordCount(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
    }
}
