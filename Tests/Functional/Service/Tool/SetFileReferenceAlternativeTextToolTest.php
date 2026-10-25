<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\WriteCompleteness;
use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewTranslator;
use Netresearch\NrLlm\Service\Tool\Builtin\ReadRecordsTool;
use Netresearch\NrLlm\Service\Tool\Builtin\SetFileReferenceAlternativeTextTool;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\InterferesWithAnUpdateHook;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\RegistersTheInterferingHookTrait;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * `set_file_reference_alternative_text` against the real DataHandler
 * (ADR-230): it sets `alternative` on one existing reference, on any table and
 * in the reference's own language, and on nothing else.
 */
#[CoversClass(SetFileReferenceAlternativeTextTool::class)]
final class SetFileReferenceAlternativeTextToolTest extends AbstractFunctionalTestCase
{
    use AssertsGermanPreviewTrait;
    use RegistersTheInterferingHookTrait;

    private const NOT_PERMITTED = 'File reference or its record not found, or not permitted.';

    private const GALLERY = 'tx_writerfixture_gallery';

    private const OPEN_PAGE = 1;

    private const CLOSED_PAGE = 2;

    private const ELEMENT = 10;

    private const ELEMENT_ON_CLOSED = 11;

    private const TRANSLATED_ELEMENT = 12;

    /** On tt_content, default language, no text of its own (NULL). */
    private const ON_ELEMENT = 101;

    private const ON_CLOSED = 102;

    /** The language-1 overlay of ON_ELEMENT. */
    private const TRANSLATED = 103;

    /** On the page's own `media` field, an explicit empty text. */
    private const ON_PAGE = 104;

    /** On a record of the writer fixture. */
    private const ON_RECORD = 105;

    private const DELETED = 106;

    /** Points at a column that is not a file field. */
    private const NOT_IN_A_FILE_FIELD = 107;

    private const FILE = 1;

    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend'];

    /** @var non-empty-string[] */
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'typo3conf/ext/nr_llm/Tests/Functional/Fixtures/Extensions/nrllm_writer_fixture',
    ];

    private ConnectionPool $connectionPool;

    private SetFileReferenceAlternativeTextTool $tool;

    private int $recordUid = 0;

    /** @var mixed The TCA as the test framework built it, put back by tearDown(). */
    private mixed $originalTca = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTca = $GLOBALS['TCA'];
        $this->importFixture('BeUsers.csv');

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->connectionPool = $connectionPool;

        $groups = $this->connectionPool->getConnectionForTable('be_groups');
        $groups->insert('be_groups', [
            'uid'                => 9, 'pid' => 0, 'title' => 'Editors',
            'tables_modify'      => 'pages,tt_content,sys_file_reference,' . self::GALLERY,
            'non_exclude_fields' => 'sys_file_reference:alternative,pages:media,' . self::GALLERY . ':media',
            'db_mountpoints'     => '1,2',
        ]);
        $groups->update('be_users', ['usergroup' => '9', 'options' => 3], ['uid' => 2]);

        $this->connectionPool->getConnectionForTable('sys_file')->insert('sys_file', [
            'uid'       => self::FILE, 'storage' => 1, 'identifier' => '/docs/bicycle.jpg', 'name' => 'bicycle.jpg',
            'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'size' => 8, 'sha1' => str_repeat('1', 40),
        ]);

        $pages = $this->connectionPool->getConnectionForTable('pages');
        foreach ([
            [self::OPEN_PAGE, 'Host page', Permission::ALL],
            [self::CLOSED_PAGE, 'Closed', Permission::PAGE_SHOW],
        ] as [$uid, $title, $everybody]) {
            $pages->insert('pages', [
                'uid'           => $uid, 'pid' => 0, 'title' => $title, 'doktype' => 1, 'media' => $uid === self::OPEN_PAGE ? 1 : 0,
                'perms_userid'  => 1, 'perms_user' => Permission::ALL,
                'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => $everybody,
            ]);
        }

        $content = $this->connectionPool->getConnectionForTable('tt_content');
        foreach ([
            [self::ELEMENT, self::OPEN_PAGE, 'Gallery', 0, 0],
            [self::ELEMENT_ON_CLOSED, self::CLOSED_PAGE, 'Guarded', 0, 0],
            [self::TRANSLATED_ELEMENT, self::OPEN_PAGE, 'Galerie', 1, self::ELEMENT],
        ] as [$uid, $pid, $header, $language, $parent]) {
            $content->insert('tt_content', [
                'uid'              => $uid, 'pid' => $pid, 'header' => $header, 'CType' => 'textmedia', 'assets' => 1,
                'sys_language_uid' => $language, 'l18n_parent' => $parent,
            ]);
        }

        $gallery = $this->connectionPool->getConnectionForTable(self::GALLERY);
        $gallery->insert(self::GALLERY, ['pid' => self::OPEN_PAGE, 'title' => 'Summer trip', 'media' => 1, 'sys_language_uid' => 0]);

        $this->recordUid = (int) $gallery->lastInsertId();

        $references = $this->connectionPool->getConnectionForTable('sys_file_reference');
        foreach ([
            [self::ON_ELEMENT, self::OPEN_PAGE, 'tt_content', self::ELEMENT, 'assets', 0, 0, null, 0],
            [self::ON_CLOSED, self::CLOSED_PAGE, 'tt_content', self::ELEMENT_ON_CLOSED, 'assets', 0, 0, null, 0],
            [self::TRANSLATED, self::OPEN_PAGE, 'tt_content', self::TRANSLATED_ELEMENT, 'assets', 1, self::ON_ELEMENT, 'Fahrrad', 0],
            [self::ON_PAGE, self::OPEN_PAGE, 'pages', self::OPEN_PAGE, 'media', 0, 0, '', 0],
            [self::ON_RECORD, self::OPEN_PAGE, self::GALLERY, $this->recordUid, 'media', 0, 0, 'Old text', 0],
            [self::DELETED, self::OPEN_PAGE, 'tt_content', self::ELEMENT, 'assets', 0, 0, null, 1],
            [self::NOT_IN_A_FILE_FIELD, self::OPEN_PAGE, 'tt_content', self::ELEMENT, 'header', 0, 0, null, 0],
        ] as [$uid, $pid, $table, $foreign, $field, $language, $parent, $alternative, $deleted]) {
            $references->insert('sys_file_reference', [
                'uid'              => $uid, 'pid' => $pid, 'uid_local' => self::FILE, 'tablenames' => $table,
                'uid_foreign'      => $foreign, 'fieldname' => $field, 'sorting_foreign' => 1,
                'sys_language_uid' => $language, 'l10n_parent' => $parent, 'alternative' => $alternative,
                'deleted'          => $deleted,
            ]);
        }

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');

        $this->tool = new SetFileReferenceAlternativeTextTool(
            $this->connectionPool,
            new TableReadAccessService(),
            new ApprovalPreviewTranslator($this->getService(LanguageServiceFactory::class)),
        );
    }

    protected function tearDown(): void
    {
        $this->unregisterInterferingHook();
        $GLOBALS['TCA'] = $this->originalTca;
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function anAdminSetsTheTextOfAReferenceOnAContentElementAndNothingElse(): void
    {
        $before = $this->alternatives();

        $result = $this->change(['reference' => self::ON_ELEMENT, 'alternative' => '  A red bicycle  ']);

        self::assertFalse($result->isError, $result->content);
        self::assertSame(WriteKind::UPDATED, $result->writeKind);
        self::assertSame(WriteCompleteness::COMPLETE, $result->writeCompleteness);
        self::assertSame('sys_file_reference', $result->writeTarget?->table);
        self::assertSame(self::ON_ELEMENT, $result->writeTarget?->uid);
        self::assertSame(
            'Set the alternative text of file reference [101] (file [1] "bicycle.jpg" in assets of tt_content [10], language 0) to "A red bicycle".',
            $result->content,
        );

        $expected                   = $before;
        $expected[self::ON_ELEMENT] = 'A red bicycle';
        self::assertSame($expected, $this->alternatives(), 'only the one reference changes, its translation included');
    }

    #[Test]
    public function anAdminSetsTheTextOfAReferenceOnAPagesOwnFileField(): void
    {
        $result = $this->change(['reference' => self::ON_PAGE, 'alternative' => 'Logo of the host']);

        self::assertFalse($result->isError, $result->content);
        self::assertSame('Logo of the host', $this->alternatives()[self::ON_PAGE]);
    }

    #[Test]
    public function anAdminSetsTheTextOfAReferenceOnARecordOfAnotherTable(): void
    {
        $result = $this->change(['reference' => self::ON_RECORD, 'alternative' => 'Beach at sunset']);

        self::assertFalse($result->isError, $result->content);
        self::assertSame('Beach at sunset', $this->alternatives()[self::ON_RECORD]);
    }

    #[Test]
    public function aTranslatedReferenceIsWrittenInItsOwnLanguage(): void
    {
        $result = $this->change(['reference' => self::TRANSLATED, 'alternative' => 'Rotes Fahrrad'], 2);

        self::assertFalse($result->isError, $result->content);
        $alternatives = $this->alternatives();
        self::assertSame('Rotes Fahrrad', $alternatives[self::TRANSLATED]);
        self::assertNull($alternatives[self::ON_ELEMENT], 'the default-language reference stays as it was');
        self::assertSame(1, $this->languageOf(self::TRANSLATED));
    }

    #[Test]
    public function anEditorSetsTheTextWhereTheBackendLetsThem(): void
    {
        $result = $this->change(['reference' => self::ON_RECORD, 'alternative' => 'Beach'], 2);

        self::assertFalse($result->isError, $result->content);
        self::assertSame('Beach', $this->alternatives()[self::ON_RECORD]);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function referencesThatAreNotThere(): iterable
    {
        yield 'no such uid' => [999];
        yield 'deleted' => [self::DELETED];
        yield 'not in a file field' => [self::NOT_IN_A_FILE_FIELD];
    }

    #[Test]
    #[DataProvider('referencesThatAreNotThere')]
    public function aReferenceThatIsNotThereIsRefusedNeutrally(int $reference): void
    {
        $before = $this->alternatives();

        $result = $this->change(['reference' => $reference, 'alternative' => 'x']);

        self::assertTrue($result->isError);
        self::assertSame(self::NOT_PERMITTED, $result->content);
        self::assertSame($before, $this->alternatives());
    }

    /**
     * A reference whose record is gone — never there, or deleted — is not
     * written: nothing renders it.
     */
    #[Test]
    public function aReferenceWhoseRecordIsGoneIsRefusedNeutrally(): void
    {
        $this->addReference(110, 'tt_content', 999, 'assets', self::OPEN_PAGE);
        $this->connectionPool->getConnectionForTable('tt_content')
            ->update('tt_content', ['deleted' => 1], ['uid' => self::ELEMENT_ON_CLOSED]);

        foreach ([110, self::ON_CLOSED] as $reference) {
            $result = $this->change(['reference' => $reference, 'alternative' => 'x']);

            self::assertTrue($result->isError);
            self::assertSame(self::NOT_PERMITTED, $result->content);
            self::assertNull($this->alternatives()[$reference]);
        }
    }

    #[Test]
    public function aReferenceOnASensitiveTableIsRefusedEvenToAnAdmin(): void
    {
        $this->addReference(108, 'be_users', 1, 'avatar', 0);

        $result = $this->change(['reference' => 108, 'alternative' => 'x']);

        self::assertTrue($result->isError);
        self::assertSame(self::NOT_PERMITTED, $result->content);
        self::assertNull($this->alternatives()[108]);
    }

    #[Test]
    public function aReadOnlyTableIsRefusedEvenToAnAdmin(): void
    {
        $this->patchTca([self::GALLERY => ['ctrl' => ['readOnly' => true]]]);

        $result = $this->change(['reference' => self::ON_RECORD, 'alternative' => 'x']);

        self::assertTrue($result->isError);
        self::assertSame(self::NOT_PERMITTED, $result->content);
        self::assertSame('Old text', $this->alternatives()[self::ON_RECORD]);
    }

    #[Test]
    public function anAdminOnlyTableIsRefusedToAnEditorOnly(): void
    {
        $this->patchTca([self::GALLERY => ['ctrl' => ['adminOnly' => true]]]);

        $refused = $this->change(['reference' => self::ON_RECORD, 'alternative' => 'x'], 2);
        self::assertTrue($refused->isError);
        self::assertSame(self::NOT_PERMITTED, $refused->content);
        self::assertSame('Old text', $this->alternatives()[self::ON_RECORD]);

        $taken = $this->change(['reference' => self::ON_RECORD, 'alternative' => 'Beach']);
        self::assertFalse($taken->isError, $taken->content);
    }

    /**
     * A record on the root level has no page: only an admin edits there, and
     * the card has no location line.
     */
    #[Test]
    public function aReferenceOnTheRootLevelIsAnAdminsOnly(): void
    {
        $gallery = $this->connectionPool->getConnectionForTable(self::GALLERY);
        $gallery->insert(self::GALLERY, ['pid' => 0, 'title' => 'On the root level', 'media' => 1, 'sys_language_uid' => 0]);
        $this->addReference(109, self::GALLERY, (int) $gallery->lastInsertId(), 'media', 0);

        $refused = $this->change(['reference' => 109, 'alternative' => 'x'], 2);
        self::assertTrue($refused->isError);
        self::assertSame(self::NOT_PERMITTED, $refused->content);

        $preview = $this->previewIn('en', ['reference' => 109, 'alternative' => 'Root image']);
        self::assertSame('Change alternative text of this image use', $preview[0]);
        self::assertSame([], array_values(array_filter($preview, static fn (string $line): bool => str_starts_with($line, 'Location: '))));
        self::assertStringNotContainsString('page UID', $preview[array_key_last($preview)]);

        $taken = $this->change(['reference' => 109, 'alternative' => 'Root image']);
        self::assertFalse($taken->isError, $taken->content);
        self::assertSame('Root image', $this->alternatives()[109]);
    }

    #[Test]
    public function anEditorMayNotChangeAReferenceOnAPageTheyMayNotEdit(): void
    {
        $result = $this->change(['reference' => self::ON_CLOSED, 'alternative' => 'x'], 2);

        self::assertTrue($result->isError);
        self::assertSame(self::NOT_PERMITTED, $result->content);
        self::assertNull($this->alternatives()[self::ON_CLOSED]);
    }

    /**
     * A page's own file field is a page property: content-edit rights on the
     * page are not enough.
     */
    #[Test]
    public function anEditorMayNotChangeAPagesReferenceWithoutThePageEditRight(): void
    {
        $this->connectionPool->getConnectionForTable('pages')
            ->update('pages', ['perms_everybody' => Permission::ALL & ~Permission::PAGE_EDIT], ['uid' => self::OPEN_PAGE]);

        $result = $this->change(['reference' => self::ON_PAGE, 'alternative' => 'x'], 2);

        self::assertTrue($result->isError);
        self::assertSame(self::NOT_PERMITTED, $result->content);
        self::assertSame('', $this->alternatives()[self::ON_PAGE]);
    }

    #[Test]
    public function anEditorWithoutTheRightToModifyTheParentTableIsRefusedNeutrally(): void
    {
        $editor                             = $this->actor(2);
        $editor->groupData['tables_modify'] = 'pages,tt_content,sys_file_reference';

        $result = $this->tool->execute(['reference' => self::ON_RECORD, 'alternative' => 'x'], ToolExecutionContext::fromBackendUser($editor));

        self::assertTrue($result->isError);
        self::assertSame(self::NOT_PERMITTED, $result->content);
        self::assertSame('Old text', $this->alternatives()[self::ON_RECORD]);
    }

    /**
     * `tables_modify` for `sys_file_reference` is the DataHandler's question,
     * not the tool's: its refusal reaches the caller as such, and nothing is
     * written.
     */
    #[Test]
    public function theDataHandlersRefusalReachesTheCaller(): void
    {
        $editor                             = $this->actor(2);
        $editor->groupData['tables_modify'] = 'pages,tt_content,' . self::GALLERY;

        $result = $this->tool->execute(['reference' => self::ON_RECORD, 'alternative' => 'x'], ToolExecutionContext::fromBackendUser($editor));

        self::assertTrue($result->isError);
        self::assertStringStartsWith('The update was refused by TYPO3: ', $result->content);
        self::assertSame('Old text', $this->alternatives()[self::ON_RECORD]);
    }

    #[Test]
    public function anEditorWithoutTheAlternativeGrantIsRefusedBeforeTheWrite(): void
    {
        $editor                                  = $this->actor(2);
        $editor->groupData['non_exclude_fields'] = 'pages:media,' . self::GALLERY . ':media';

        $result = $this->tool->execute(['reference' => self::ON_RECORD, 'alternative' => 'x'], ToolExecutionContext::fromBackendUser($editor));

        self::assertTrue($result->isError);
        self::assertSame(
            'Refused: the acting backend user holds no field-level ("exclude field") grant for sys_file_reference:alternative. Nothing was written.',
            $result->content,
        );
        self::assertSame('Old text', $this->alternatives()[self::ON_RECORD]);
    }

    #[Test]
    public function anEditorWithoutTheGrantForTheParentsFileFieldIsRefusedBeforeTheWrite(): void
    {
        $editor                                  = $this->actor(2);
        $editor->groupData['non_exclude_fields'] = 'sys_file_reference:alternative';

        $result = $this->tool->execute(['reference' => self::ON_RECORD, 'alternative' => 'x'], ToolExecutionContext::fromBackendUser($editor));

        self::assertTrue($result->isError);
        self::assertSame(
            'Refused: the acting backend user holds no field-level ("exclude field") grant for tx_writerfixture_gallery:media. Nothing was written.',
            $result->content,
        );
    }

    #[Test]
    public function anEditorWithoutTheLanguageRightIsRefusedBeforeTheWrite(): void
    {
        $editor                                 = $this->actor(2);
        $editor->groupData['allowed_languages'] = '0';

        $result = $this->tool->execute(['reference' => self::TRANSLATED, 'alternative' => 'x'], ToolExecutionContext::fromBackendUser($editor));

        self::assertTrue($result->isError);
        self::assertSame(
            'Refused: file reference [103] is in language 1, which the acting backend user may not edit. Nothing was written.',
            $result->content,
        );
        self::assertSame('Fahrrad', $this->alternatives()[self::TRANSLATED]);
    }

    #[Test]
    public function anEmptyTextIsRefused(): void
    {
        $result = $this->change(['reference' => self::ON_RECORD, 'alternative' => '   ']);

        self::assertTrue($result->isError);
        self::assertStringStartsWith('Refused: "alternative" must not be empty.', $result->content);
        self::assertSame('Old text', $this->alternatives()[self::ON_RECORD]);
    }

    /**
     * A hook that drops the value leaves no error in the log; the read-back
     * is what reports it.
     */
    #[Test]
    public function aTextAHookDropsIsReportedAsAFailure(): void
    {
        $this->registerInterferingHook();
        InterferesWithAnUpdateHook::$dropFileReferenceColumn = 'alternative';

        $result = $this->change(['reference' => self::ON_RECORD, 'alternative' => 'Beach']);

        self::assertTrue($result->isError);
        self::assertSame(
            'The alternative text did not take on file reference [105]: the DataHandler dropped or changed the value.',
            $result->content,
        );
        self::assertSame('Old text', $this->alternatives()[self::ON_RECORD]);
    }

    #[Test]
    public function thePreviewShowsTheRecordTheFileAndTheTextBeforeAndAfterAndWritesNothing(): void
    {
        $arguments = ['reference' => self::ON_ELEMENT, 'alternative' => 'A red bicycle'];

        self::assertSame([
            'Change alternative text of this image use',
            'Content element: “Gallery”',
            'Location: on page “Host page”',
            'Language: default language',
            'Field: ' . $this->tcaLabelIn('en', 'tt_content', 'columns', 'assets', 'label'),
            'File: “bicycle.jpg”',
            "This image use has no alternative text of its own yet; the file's text applies.",
            'Alternative text: currently (empty), proposed “A red bicycle”',
            'Technical details: table tt_content, UID 10, page UID 1, language UID 0, fields assets, file reference UID 101, file UID 1',
        ], $this->previewIn('en', $arguments));

        $german = $this->previewIn('de', $arguments);
        self::assertSame([
            'Alternativtext dieser Bildverwendung ändern',
            'Inhaltselement: „Gallery“',
            'Ort: auf der Seite „Host page“',
            'Sprache: Standardsprache',
            'Feld: ' . $this->tcaLabelIn('de', 'tt_content', 'columns', 'assets', 'label'),
            'Datei: „bicycle.jpg“',
            'Diese Bildverwendung hat noch keinen eigenen Alternativtext; es gilt der Text der Datei.',
            'Alternativtext: aktuell (leer), Vorschlag „A red bicycle“',
            'Technische Details: Tabelle tt_content, UID 10, Seite UID 1, Sprach-UID 0, Felder assets, Dateiverweis UID 101, Datei UID 1',
        ], $german);
        self::assertGermanEditorLines($german);
        self::assertNull($this->alternatives()[self::ON_ELEMENT]);
    }

    #[Test]
    public function thePreviewOfARecordShowsItsOwnTextAsTheCurrentOne(): void
    {
        $lines = $this->previewIn('en', ['reference' => self::ON_RECORD, 'alternative' => 'Beach']);

        self::assertSame('Change alternative text of this image use', $lines[0]);
        self::assertStringStartsWith('Record: ', $lines[1]);
        self::assertStringEndsWith('“Summer trip”', $lines[1]);
        self::assertContains('Alternative text: currently “Old text”, proposed “Beach”', $lines);
        self::assertNotContains("This image use has no alternative text of its own yet; the file's text applies.", $lines);
    }

    #[Test]
    public function thePreviewOfATranslatedReferenceNamesTheTranslation(): void
    {
        $lines = $this->previewIn('en', ['reference' => self::TRANSLATED, 'alternative' => 'Rotes Fahrrad']);

        self::assertContains('Language: translation, not the default language', $lines);
        self::assertContains('Alternative text: currently “Fahrrad”, proposed “Rotes Fahrrad”', $lines);
    }

    /**
     * The read side the tool relies on: `read_records` returns a reference's
     * own text, and keeps "none of its own" (NULL) apart from an explicit
     * empty text.
     */
    #[Test]
    public function readRecordsReturnsAReferencesOwnTextAndTellsNullFromEmpty(): void
    {
        $readRecords = new ReadRecordsTool($this->connectionPool, new TableReadAccessService());
        $context     = ToolExecutionContext::fromBackendUser($this->actor(1));

        $ownText = $readRecords->execute(['table' => 'sys_file_reference', 'uid' => self::ON_RECORD, 'fields' => ['uid_local', 'alternative']], $context);
        self::assertFalse($ownText->isError, $ownText->content);
        self::assertStringContainsString("- sys_file_reference:105\n", $ownText->content);
        self::assertStringContainsString("\n  uid_local: 1\n  alternative: Old text\n", $ownText->content);

        $noneOfItsOwn = $readRecords->execute(['table' => 'sys_file_reference', 'uid' => self::ON_ELEMENT, 'fields' => ['alternative']], $context);
        self::assertStringContainsString('alternative: null', $noneOfItsOwn->content);

        $empty = $readRecords->execute(['table' => 'sys_file_reference', 'uid' => self::ON_PAGE, 'fields' => ['alternative']], $context);
        self::assertStringContainsString("alternative: \n", $empty->content . "\n");
        self::assertStringNotContainsString('alternative: null', $empty->content);
    }

    /**
     * A page's own file field: the page is the record, so the card names the
     * page and has no separate location line.
     */
    #[Test]
    public function thePreviewOfAPagesReferenceNamesThePage(): void
    {
        $lines = $this->previewIn('en', ['reference' => self::ON_PAGE, 'alternative' => 'Logo']);

        self::assertSame('Page: “Host page”', $lines[1]);
        self::assertSame([], array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'Location: '))));
        self::assertContains('Alternative text: currently (empty), proposed “Logo”', $lines);
    }

    /**
     * A reference for all languages (-1) is neither the default language nor
     * a translation, and the card says so.
     */
    #[Test]
    public function thePreviewOfAReferenceForAllLanguagesSaysSo(): void
    {
        $this->connectionPool->getConnectionForTable('sys_file_reference')
            ->update('sys_file_reference', ['sys_language_uid' => -1], ['uid' => self::ON_RECORD]);

        $lines = $this->previewIn('en', ['reference' => self::ON_RECORD, 'alternative' => 'Beach']);

        self::assertContains('Language: all languages', $lines);
        self::assertStringContainsString('language UID -1', $lines[array_key_last($lines)]);
    }

    #[Test]
    public function theViewerGateAnswersForTheViewerNotTheRun(): void
    {
        $arguments = ['reference' => self::ON_CLOSED, 'alternative' => 'x'];

        self::assertTrue($this->tool->mayViewerReadPreview($arguments, $this->actor(1)));
        self::assertFalse($this->tool->mayViewerReadPreview($arguments, $this->actor(2)));
    }

    private function addReference(int $uid, string $table, int $foreign, string $field, int $pid): void
    {
        $this->connectionPool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'uid'         => $uid, 'pid' => $pid, 'uid_local' => self::FILE, 'tablenames' => $table,
            'uid_foreign' => $foreign, 'fieldname' => $field, 'sorting_foreign' => 1, 'sys_language_uid' => 0,
        ]);
    }

    /**
     * Lay a change over the TCA for the rest of this test.
     *
     * @param array<string, mixed> $patch
     */
    private function patchTca(array $patch): void
    {
        $tca = $GLOBALS['TCA'];
        self::assertIsArray($tca);
        $GLOBALS['TCA'] = array_replace_recursive($tca, $patch);
    }

    /**
     * The preview as the run's acting user reads it, in that user's language
     * (ADR-213).
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<string>
     */
    private function previewIn(string $language, array $arguments): array
    {
        $admin               = $this->actor(1);
        $admin->user['lang'] = $language;

        return $this->tool->previewCall($arguments, ToolExecutionContext::fromBackendUser($admin));
    }

    private function actor(int $uid): BackendUserAuthentication
    {
        $user                                  = $this->setUpBackendUser($uid);
        $user->groupData['explicit_allowdeny'] = 'tt_content:CType:textmedia';

        return $user;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function change(array $arguments, int $userUid = 1): ToolResult
    {
        return $this->tool->execute($arguments, ToolExecutionContext::fromBackendUser($this->actor($userUid)));
    }

    /**
     * Every reference's alternative text, by uid.
     *
     * @return array<int, string|null>
     */
    private function alternatives(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();

        $alternatives = [];
        foreach ($queryBuilder->select('uid', 'alternative')->from('sys_file_reference')->orderBy('uid')->executeQuery()->fetchAllAssociative() as $row) {
            $alternative = $row['alternative'];
            self::assertTrue($alternative === null || is_string($alternative));
            $alternatives[(int) $row['uid']] = $alternative;
        }

        return $alternatives;
    }

    private function languageOf(int $reference): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();

        return (int) $queryBuilder
            ->select('sys_language_uid')
            ->from('sys_file_reference')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($reference, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
    }
}
