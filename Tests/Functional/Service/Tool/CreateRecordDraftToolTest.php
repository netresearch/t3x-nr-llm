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
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * The write path of the generic record creator (ADR-197), against a real
 * database, the real DataHandler and a TCA table no narrow writer covers.
 *
 * The table comes from the fixture extension under
 * Tests/Functional/Fixtures/Extensions/nrllm_writer_fixture: one column of
 * every scalar type the tool may set, one relation it may not, a record type
 * with two showitem lists, a palette, a required column, an exclude column, an
 * authMode select and a text column with a `min`. The assertion this file
 * exists for is that the record is HIDDEN and carries exactly the fields the
 * approver read.
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

        $groups = $this->connectionPool->getConnectionForTable('be_groups');
        $groups->insert('be_groups', [
            'uid' => 7, 'pid' => 0, 'title' => 'Editors', 'db_mountpoints' => '1,2',
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
        yield 'required column missing'    => [['teaser' => 'x'], '"title" is required'];
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
     * The DataHandler stores a decimal with two places; the read-back compares
     * it as a number, so 1.234 stored as 1.23 is the record that was asked for.
     */
    #[Test]
    public function aDecimalIsStoredWithTwoPlacesAndKept(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            $this->call(['title' => 'x', 'rating' => 1.234]),
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertEqualsWithDelta(1.23, (float)($this->createdRecord()['rating'] ?? 0), 0.0001);
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
        $row = $this->createdRecord();
        self::assertStringContainsString('One', (string)($row['body'] ?? ''));
        self::assertStringContainsString('Two', (string)($row['body'] ?? ''));
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
        self::assertStringContainsString('was deleted again', $result->content);
        self::assertSame(1, $this->recordCount(), 'the row exists, flagged deleted');
        self::assertSame(0, $this->undeletedRecordCount());
    }

    #[Test]
    public function thePreviewUsesTheTcaLabelsAndWritesNothing(): void
    {
        $admin = $this->setUpBackendUser(1);

        $lines = $this->tool->previewCall(
            $this->call(['title' => 'Proposed', 'priority' => 4, 'published_at' => self::PUBLISHED_AT]),
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertCount(6, $lines, implode("\n", $lines));
        self::assertStringContainsString('Fixture item', $lines[0]);
        self::assertStringContainsString('on page [2] "Open"', $lines[0]);
        // `title` carries a `LLL:` label; it is resolved, not printed raw.
        self::assertSame('Title: "Proposed"', $lines[1]);
        self::assertSame('Priority: "4"', $lines[2]);
        self::assertSame('Published at: "' . self::PUBLISHED_AT . '"', $lines[3]);
        self::assertSame('language: default', $lines[4]);
        self::assertStringContainsString('hidden', $lines[5]);

        self::assertSame(0, $this->recordCount(), 'a preview must not create anything');
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

        return new CreateRecordDraftTool(
            $this->connectionPool,
            new TableReadAccessService(),
            $writers,
            new ExtensionConfiguration(),
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
