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
use Netresearch\NrLlm\Service\Tool\Builtin\ReplaceFileReferenceTool;
use Netresearch\NrLlm\Service\Tool\FalStorageGate;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\InterferesWithAnUpdateHook;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\RegistersTheInterferingHookTrait;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * `replace_file_reference` against the real DataHandler and a real storage
 * (ADR-198): the new reference takes the old one's place, the old one goes,
 * nothing of it is carried over, and the element's counter stays true.
 */
#[CoversClass(ReplaceFileReferenceTool::class)]
final class ReplaceFileReferenceToolTest extends AbstractFunctionalTestCase
{
    use AssertsGermanPreviewTrait;
    use RegistersTheInterferingHookTrait;

    private const STORAGE_CONFIGURATION = '<?xml version="1.0" encoding="utf-8" standalone="yes" ?>
<T3FlexForms><data><sheet index="sDEF"><language index="lDEF">
<field index="basePath"><value index="vDEF">fileadmin/</value></field>
<field index="pathType"><value index="vDEF">relative</value></field>
<field index="caseSensitive"><value index="vDEF">1</value></field>
</language></sheet></data></T3FlexForms>';

    private const OPEN_PAGE = 1;

    private const CLOSED_PAGE = 2;

    private const ELEMENT = 10;

    private const ELEMENT_ON_CLOSED = 11;

    /** The language-1 translation of ELEMENT, carrying overlays of its references. */
    private const TRANSLATED_ELEMENT = 12;

    private const OVERLAY_OF_SECOND = 111;

    private const OVERLAY_OF_FIRST = 112;

    private const FIRST = 101;

    private const SECOND = 102;

    private const ON_CLOSED = 103;

    private const ON_A_PAGE = 104;

    private const FILE_ONE = 1;

    private const FILE_TWO = 2;

    private const FILE_TEXT = 3;

    private const FILE_OUTSIDE = 4;

    private const FILE_THREE = 5;

    private ConnectionPool $connectionPool;

    private ReplaceFileReferenceTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->connectionPool = $connectionPool;

        GeneralUtility::mkdir_deep($this->instancePath . '/fileadmin/docs');
        foreach (['outside.jpg', 'docs/one.jpg', 'docs/two.jpg', 'docs/notes.txt', 'docs/three.jpg'] as $name) {
            file_put_contents($this->instancePath . '/fileadmin/' . $name, $name);
        }

        $storage = $this->connectionPool->getConnectionForTable('sys_file_storage');
        $storage->insert('sys_file_storage', [
            'uid' => 1, 'pid' => 0, 'name' => 'Main storage', 'driver' => 'Local',
            'configuration' => self::STORAGE_CONFIGURATION,
            'is_online' => 1, 'is_browsable' => 1, 'is_public' => 1, 'is_writable' => 1,
        ]);
        $storage->insert('sys_filemounts', [
            'uid' => 1, 'pid' => 0, 'title' => 'Docs mount', 'identifier' => '1:/docs/', 'read_only' => 0,
        ]);
        $storage->insert('be_groups', [
            'uid' => 9, 'pid' => 0, 'title' => 'Editors', 'file_mountpoints' => '1',
            'file_permissions' => 'readFolder,readFile',
            'tables_modify' => 'tt_content,sys_file_reference',
            'db_mountpoints' => '1,2',
        ]);
        $storage->update('be_users', ['usergroup' => '9', 'options' => 3], ['uid' => 2]);

        $files = $this->connectionPool->getConnectionForTable('sys_file');
        foreach ([
            [self::FILE_ONE, '/docs/one.jpg', 'one.jpg', 'jpg', 'image/jpeg'],
            [self::FILE_TWO, '/docs/two.jpg', 'two.jpg', 'jpg', 'image/jpeg'],
            [self::FILE_TEXT, '/docs/notes.txt', 'notes.txt', 'txt', 'text/plain'],
            [self::FILE_OUTSIDE, '/outside.jpg', 'outside.jpg', 'jpg', 'image/jpeg'],
            [self::FILE_THREE, '/docs/three.jpg', 'three.jpg', 'jpg', 'image/jpeg'],
        ] as [$uid, $identifier, $name, $extension, $mime]) {
            $files->insert('sys_file', [
                'uid' => $uid, 'storage' => 1, 'identifier' => $identifier, 'name' => $name,
                'extension' => $extension, 'mime_type' => $mime, 'size' => 8,
                'sha1' => str_repeat((string)$uid, 40),
            ]);
        }

        $pages = $this->connectionPool->getConnectionForTable('pages');
        foreach ([[self::OPEN_PAGE, 'Host page', Permission::ALL], [self::CLOSED_PAGE, 'Closed', Permission::ALL & ~Permission::CONTENT_EDIT]] as [$uid, $title, $everybody]) {
            $pages->insert('pages', [
                'uid' => $uid, 'pid' => 0, 'title' => $title, 'doktype' => 1,
                'perms_userid' => 1, 'perms_user' => Permission::ALL,
                'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => $everybody,
            ]);
        }

        $content = $this->connectionPool->getConnectionForTable('tt_content');
        $content->insert('tt_content', [
            'uid' => self::ELEMENT, 'pid' => self::OPEN_PAGE, 'header' => 'Gallery', 'CType' => 'textmedia',
            'assets' => 2, 'sys_language_uid' => 0,
        ]);
        $content->insert('tt_content', [
            'uid' => self::ELEMENT_ON_CLOSED, 'pid' => self::CLOSED_PAGE, 'header' => 'Guarded', 'CType' => 'textmedia',
            'assets' => 1, 'sys_language_uid' => 0,
        ]);

        $content->insert('tt_content', [
            'uid' => self::TRANSLATED_ELEMENT, 'pid' => self::OPEN_PAGE, 'header' => 'Galerie', 'CType' => 'textmedia',
            'assets' => 2, 'sys_language_uid' => 1, 'l18n_parent' => self::ELEMENT,
        ]);

        $references = $this->connectionPool->getConnectionForTable('sys_file_reference');
        foreach ([[self::OVERLAY_OF_FIRST, self::FIRST, 1], [self::OVERLAY_OF_SECOND, self::SECOND, 2]] as [$uid, $parent, $sorting]) {
            $references->insert('sys_file_reference', [
                'uid' => $uid, 'pid' => self::OPEN_PAGE, 'uid_local' => self::FILE_ONE, 'tablenames' => 'tt_content',
                'uid_foreign' => self::TRANSLATED_ELEMENT, 'fieldname' => 'assets', 'sorting_foreign' => $sorting,
                'sys_language_uid' => 1, 'l10n_parent' => $parent,
            ]);
        }

        foreach ([
            [self::FIRST, self::OPEN_PAGE, self::FILE_ONE, 'tt_content', self::ELEMENT, 'assets', 1, 'The old image'],
            [self::SECOND, self::OPEN_PAGE, self::FILE_TWO, 'tt_content', self::ELEMENT, 'assets', 2, null],
            [self::ON_CLOSED, self::CLOSED_PAGE, self::FILE_ONE, 'tt_content', self::ELEMENT_ON_CLOSED, 'assets', 1, null],
            // A page's reference whose uid_foreign happens to equal a content
            // element's uid: only the table name tells them apart.
            [self::ON_A_PAGE, self::OPEN_PAGE, self::FILE_ONE, 'pages', self::ELEMENT, 'assets', 3, null],
        ] as [$uid, $pid, $file, $table, $foreign, $field, $sorting, $alternative]) {
            $references->insert('sys_file_reference', [
                'uid' => $uid, 'pid' => $pid, 'uid_local' => $file, 'tablenames' => $table, 'uid_foreign' => $foreign,
                'fieldname' => $field, 'sorting_foreign' => $sorting, 'alternative' => $alternative,
                'sys_language_uid' => 0,
            ]);
        }

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');

        $gate = $this->get(FalStorageGate::class);
        self::assertInstanceOf(FalStorageGate::class, $gate);
        $this->tool = new ReplaceFileReferenceTool($this->connectionPool, $gate, new ApprovalPreviewTranslator($this->getService(LanguageServiceFactory::class)));
    }

    protected function tearDown(): void
    {
        $this->unregisterInterferingHook();
        unset($GLOBALS['TYPO3_REQUEST'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function anAdminReplacesAFileAtTheSamePositionAndNothingOfTheOldReferenceIsCarriedOver(): void
    {
        $result = $this->change(['reference' => self::FIRST, 'action' => 'replace', 'file' => self::FILE_THREE]);

        self::assertFalse($result->isError, $result->content);
        self::assertSame(WriteKind::CREATED, $result->writeKind);
        self::assertSame(WriteCompleteness::COMPLETE, $result->writeCompleteness);
        $newUid = (int)$result->writeTarget?->uid;

        self::assertSame([$newUid, self::SECOND], $this->liveReferences(self::ELEMENT));
        self::assertSame(self::FILE_THREE, (int)($this->referenceRow($newUid)['uid_local'] ?? 0));
        self::assertNull($this->referenceRow($newUid)['alternative'] ?? null, 'the old alternative text must not travel');
        self::assertSame(1, (int)($this->referenceRow(self::FIRST)['deleted'] ?? 0));
        self::assertSame(2, $this->counter(self::ELEMENT));
    }

    #[Test]
    public function aReplacementCanSetTextsOfItsOwn(): void
    {
        $result = $this->change(['reference' => self::FIRST, 'action' => 'replace', 'file' => self::FILE_THREE, 'alternative' => 'Three']);

        self::assertFalse($result->isError, $result->content);
        self::assertSame('Three', $this->referenceRow((int)$result->writeTarget?->uid)['alternative'] ?? null);
        self::assertSame(WriteCompleteness::COMPLETE, $result->writeCompleteness);
    }

    /**
     * A text the card showed is part of the plan: a hook that drops it from
     * the new reference leaves the file replaced and the write partial
     * (ADR-214).
     */
    #[Test]
    public function aTextAHookDropsMakesTheReplacementPartial(): void
    {
        $this->registerInterferingHook();
        InterferesWithAnUpdateHook::$dropColumnOnCreate = 'alternative';

        $result = $this->change(['reference' => self::FIRST, 'action' => 'replace', 'file' => self::FILE_THREE, 'alternative' => 'Three', 'title' => 'Kept']);

        self::assertFalse($result->isError, $result->content);
        self::assertSame(WriteKind::CREATED, $result->writeKind);
        self::assertSame(WriteCompleteness::PARTIAL, $result->writeCompleteness);
        self::assertStringContainsString('Not completely: alternative did not take on the new reference', $result->content);
        self::assertSame('Kept', $this->referenceRow((int)$result->writeTarget?->uid)['title'] ?? null);
    }

    /**
     * An asked empty text is an explicit override, and NULL is not one: the
     * frontend falls back to the file's metadata for NULL. A hook that drops
     * the empty text leaves NULL, which is not what was asked.
     */
    #[Test]
    public function anEmptyTextAHookDropsMakesTheReplacementPartial(): void
    {
        $this->registerInterferingHook();
        InterferesWithAnUpdateHook::$dropColumnOnCreate = 'alternative';

        $result = $this->change(['reference' => self::FIRST, 'action' => 'replace', 'file' => self::FILE_THREE, 'alternative' => '']);

        self::assertFalse($result->isError, $result->content);
        self::assertNull($this->referenceRow((int)$result->writeTarget?->uid)['alternative'] ?? null);
        self::assertSame(WriteCompleteness::PARTIAL, $result->writeCompleteness);
    }

    /**
     * The other direction: an asked empty text that is stored as '' is what
     * was asked.
     */
    #[Test]
    public function anEmptyTextThatTakesLeavesTheReplacementComplete(): void
    {
        $result = $this->change(['reference' => self::FIRST, 'action' => 'replace', 'file' => self::FILE_THREE, 'alternative' => '']);

        self::assertFalse($result->isError, $result->content);
        self::assertSame('', $this->referenceRow((int)$result->writeTarget?->uid)['alternative'] ?? null);
        self::assertSame(WriteCompleteness::COMPLETE, $result->writeCompleteness);
    }

    /**
     * A text longer than its column takes is refused before anything is
     * written: core would cut a title to 255 characters without a word.
     */
    #[Test]
    public function aTextLongerThanItsColumnTakesIsRefusedBeforeTheWrite(): void
    {
        $result = $this->change(['reference' => self::FIRST, 'action' => 'replace', 'file' => self::FILE_THREE, 'title' => str_repeat('t', 256)]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('"title" is longer than 255 characters', $result->content);
        self::assertSame([self::FIRST, self::SECOND], $this->liveReferences(self::ELEMENT));
    }

    #[Test]
    public function anAdminRemovesAReferenceAndTheCounterFollows(): void
    {
        $result = $this->change(['reference' => self::SECOND, 'action' => 'remove']);

        self::assertFalse($result->isError, $result->content);
        self::assertSame(WriteKind::DELETED, $result->writeKind);
        self::assertSame(self::SECOND, $result->writeTarget?->uid);
        self::assertSame(WriteCompleteness::COMPLETE, $result->writeCompleteness);
        self::assertSame([self::FIRST], $this->liveReferences(self::ELEMENT));
        self::assertSame(1, $this->counter(self::ELEMENT));
    }

    #[Test]
    public function removingAReferenceTakesItsTranslatedOverlayAlongAndSettlesTheTranslation(): void
    {
        $result = $this->change(['reference' => self::SECOND, 'action' => 'remove']);

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('Its 1 translated reference(s) were deleted with it.', $result->content);
        self::assertSame(WriteCompleteness::COMPLETE, $result->writeCompleteness);
        self::assertSame(1, (int)($this->referenceRow(self::OVERLAY_OF_SECOND)['deleted'] ?? 0));
        self::assertSame([self::OVERLAY_OF_FIRST], $this->liveReferences(self::TRANSLATED_ELEMENT));
        self::assertSame(1, $this->counter(self::TRANSLATED_ELEMENT));
    }

    /**
     * The replace branch decides through the same step: the reference is
     * replaced, its translated overlay is kept by a hook, and the write is
     * partial (ADR-214).
     */
    #[Test]
    public function aTranslatedOverlayAHookKeepsMakesTheReplacementPartial(): void
    {
        $this->registerInterferingHook();
        InterferesWithAnUpdateHook::$keepRecord = 'sys_file_reference:' . self::OVERLAY_OF_FIRST;

        $result = $this->change(['reference' => self::FIRST, 'action' => 'replace', 'file' => self::FILE_THREE]);

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('The translations are not settled: translated reference [112] is still there', $result->content);
        self::assertSame(WriteKind::CREATED, $result->writeKind);
        self::assertSame(WriteCompleteness::PARTIAL, $result->writeCompleteness);
    }

    /**
     * The reference is removed, and its translated overlay, which should have
     * gone with it, is kept by a hook: the translations are not settled, and
     * the write is partial (ADR-214) — decided in settleTranslations().
     */
    #[Test]
    public function aTranslatedOverlayAHookKeepsMakesTheRemovalPartial(): void
    {
        $this->registerInterferingHook();
        InterferesWithAnUpdateHook::$keepRecord = 'sys_file_reference:' . self::OVERLAY_OF_SECOND;

        $result = $this->change(['reference' => self::SECOND, 'action' => 'remove']);

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('The translations are not settled: translated reference [111] is still there', $result->content);
        self::assertSame([self::FIRST], $this->liveReferences(self::ELEMENT));
        self::assertSame(self::SECOND, $result->writeTarget?->uid);
        self::assertSame(WriteCompleteness::PARTIAL, $result->writeCompleteness);
    }

    #[Test]
    public function thePreviewNamesTheTranslatedReferenceThatGoesAlong(): void
    {
        $arguments = ['reference' => self::SECOND, 'action' => 'remove'];

        self::assertSame([
            'Remove file from content element',
            'Content element: “Gallery”',
            'Location: on page “Host page”',
            'Language: default language',
            'Field: ' . $this->tcaLabelIn('en', 'tt_content', 'columns', 'assets', 'label'),
            'Position: file 2 of 2',
            'File: “two.jpg”',
            'Files in this field afterwards: 1',
            'The file itself stays in the file list.',
            'Important: references in translations, removed with it: 1',
            'Technical details: table tt_content, UID 10, page UID 1, language UID 0, fields assets, file reference UID 102, current file UID 2, translated file references UID 111, translated content elements UID 12',
        ], $this->previewIn('en', $arguments));
        $german = $this->previewIn('de', $arguments);
        self::assertSame([
            'Datei aus dem Inhaltselement entfernen',
            'Inhaltselement: „Gallery“',
            'Ort: auf der Seite „Host page“',
            'Sprache: Standardsprache',
            'Feld: ' . $this->tcaLabelIn('de', 'tt_content', 'columns', 'assets', 'label'),
            'Position: Datei 2 von 2',
            'Datei: „two.jpg“',
            'Dateien in diesem Feld danach: 1',
            'Die Datei selbst bleibt in der Dateiliste erhalten.',
            'Wichtig: Verweise in Übersetzungen, die mit entfernt werden: 1',
            'Technische Details: Tabelle tt_content, UID 10, Seite UID 1, Sprach-UID 0, Felder assets, Dateiverweis UID 102, bisherige Datei UID 2, übersetzte Dateiverweise UID 111, übersetzte Inhaltselemente UID 12',
        ], $german);
        self::assertGermanEditorLines($german);
    }

    #[Test]
    public function anOrphanedTranslatedReferenceIsNamedAndGoesAlong(): void
    {
        $this->orphanTheOverlayOfSecond();

        $german = $this->previewIn('de', ['reference' => self::SECOND, 'action' => 'remove']);
        self::assertContains('Wichtig: Verweise in Übersetzungen, die mit entfernt werden: 1', $german);
        self::assertContains('Davon auf einem übersetzten Inhaltselement, das nicht mehr existiert: 1', $german);
        self::assertStringEndsWith('übersetzte Dateiverweise UID 111', $german[array_key_last($german)]);

        $result = $this->change(['reference' => self::SECOND, 'action' => 'remove']);

        self::assertFalse($result->isError, $result->content);
        self::assertSame(1, (int)($this->referenceRow(self::OVERLAY_OF_SECOND)['deleted'] ?? 0));
        self::assertSame([self::FIRST], $this->liveReferences(self::ELEMENT));
    }

    #[Test]
    public function anOrphanedTranslatedReferenceInALanguageTheEditorMayNotEditIsRefused(): void
    {
        $this->orphanTheOverlayOfSecond();
        $editor                                 = $this->actor(2);
        $editor->groupData['allowed_languages'] = '0';

        $result = $this->tool->execute(
            ['reference' => self::SECOND, 'action' => 'remove'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('has a translated reference in language 1', $result->content);
        self::assertSame(0, (int)($this->referenceRow(self::OVERLAY_OF_SECOND)['deleted'] ?? 1));
    }

    #[Test]
    public function anEditorWhoMayNotEditTheTranslationIsRefusedBeforeTheWrite(): void
    {
        $editor                                 = $this->actor(2);
        $editor->groupData['allowed_languages'] = '0';

        $result = $this->tool->execute(
            ['reference' => self::SECOND, 'action' => 'remove'],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('translated reference in language 1', $result->content);
        self::assertSame([self::FIRST, self::SECOND], $this->liveReferences(self::ELEMENT));
        self::assertSame(0, (int)($this->referenceRow(self::OVERLAY_OF_SECOND)['deleted'] ?? 1));
    }

    #[Test]
    public function anEditorReplacesWithAFileInsideTheirMount(): void
    {
        $result = $this->change(['reference' => self::FIRST, 'action' => 'replace', 'file' => self::FILE_THREE], 2);

        self::assertFalse($result->isError, $result->content);
        self::assertSame(self::FILE_THREE, (int)($this->referenceRow((int)$result->writeTarget?->uid)['uid_local'] ?? 0));
    }

    #[Test]
    public function anEditorMayNotReferenceAFileOutsideTheirMount(): void
    {
        $result = $this->change(['reference' => self::FIRST, 'action' => 'replace', 'file' => self::FILE_OUTSIDE], 2);

        self::assertTrue($result->isError);
        self::assertSame('File reference, content element or file not found, or not permitted.', $result->content);
        self::assertSame([self::FIRST, self::SECOND], $this->liveReferences(self::ELEMENT));
    }

    #[Test]
    public function anEditorWithoutTheTextGrantIsRefusedBeforeTheWrite(): void
    {
        $result = $this->change(
            ['reference' => self::FIRST, 'action' => 'replace', 'file' => self::FILE_THREE, 'alternative' => 'Three'],
            2,
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('grant for sys_file_reference:alternative', $result->content);
        self::assertSame([self::FIRST, self::SECOND], $this->liveReferences(self::ELEMENT));
    }

    #[Test]
    public function anEditorMayNotChangeAReferenceOnAPageTheyMayNotEdit(): void
    {
        $result = $this->change(['reference' => self::ON_CLOSED, 'action' => 'remove'], 2);

        self::assertTrue($result->isError);
        self::assertSame('File reference, content element or file not found, or not permitted.', $result->content);
        self::assertSame(0, (int)($this->referenceRow(self::ON_CLOSED)['deleted'] ?? 1));
    }

    #[Test]
    public function aFileTheFieldDoesNotAcceptIsRefused(): void
    {
        $result = $this->change(['reference' => self::FIRST, 'action' => 'replace', 'file' => self::FILE_TEXT]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('does not accept a .txt file', $result->content);
    }

    #[Test]
    public function aReferenceThatIsNotOnAContentElementIsRefusedNeutrally(): void
    {
        $result = $this->change(['reference' => self::ON_A_PAGE, 'action' => 'remove']);

        self::assertTrue($result->isError);
        self::assertSame('File reference, content element or file not found, or not permitted.', $result->content);
        self::assertSame(0, (int)($this->referenceRow(self::ON_A_PAGE)['deleted'] ?? 1));
    }

    #[Test]
    public function thePreviewNamesBothFilesAndWhatIsNotCarriedOverAndWritesNothing(): void
    {
        $arguments = ['reference' => self::FIRST, 'action' => 'replace', 'file' => self::FILE_THREE, 'title' => 'New caption'];

        self::assertSame([
            'Replace file in content element',
            'Content element: “Gallery”',
            'Location: on page “Host page”',
            'Language: default language',
            'Field: ' . $this->tcaLabelIn('en', 'tt_content', 'columns', 'assets', 'label'),
            'Position: file 1 of 2',
            'Currently: “one.jpg”',
            'Proposed: “three.jpg”',
            'Title: “New caption”',
            "Alternative text: the file's own, not taken over from the current reference",
            "Description: the file's own, not taken over from the current reference",
            'Important: references in translations, removed with the current one: 1. The translations get no reference to the new file.',
            'Technical details: table tt_content, UID 10, page UID 1, language UID 0, fields assets, file reference UID 101, current file UID 1, file UID 5, translated file references UID 112, translated content elements UID 12',
        ], $this->previewIn('en', $arguments));
        $german = $this->previewIn('de', $arguments);
        self::assertSame([
            'Datei im Inhaltselement ersetzen',
            'Inhaltselement: „Gallery“',
            'Ort: auf der Seite „Host page“',
            'Sprache: Standardsprache',
            'Feld: ' . $this->tcaLabelIn('de', 'tt_content', 'columns', 'assets', 'label'),
            'Position: Datei 1 von 2',
            'Aktuell: „one.jpg“',
            'Vorschlag: „three.jpg“',
            'Titel: „New caption“',
            'Alternativtext: der Wert der Datei selbst, nicht vom bisherigen Verweis übernommen',
            'Beschreibung: der Wert der Datei selbst, nicht vom bisherigen Verweis übernommen',
            'Wichtig: Verweise in Übersetzungen, die mit dem bisherigen Verweis entfernt werden: 1. Die Übersetzungen erhalten keinen Verweis auf die neue Datei.',
            'Technische Details: Tabelle tt_content, UID 10, Seite UID 1, Sprach-UID 0, Felder assets, Dateiverweis UID 101, bisherige Datei UID 1, Datei UID 5, übersetzte Dateiverweise UID 112, übersetzte Inhaltselemente UID 12',
        ], $german);
        self::assertGermanEditorLines($german);
        self::assertSame([self::FIRST, self::SECOND], $this->liveReferences(self::ELEMENT));
    }

    #[Test]
    public function theViewerGateAnswersForTheViewerNotTheRun(): void
    {
        $arguments = ['reference' => self::ON_CLOSED, 'action' => 'remove'];

        self::assertTrue($this->tool->mayViewerReadPreview($arguments, $this->actor(1)));
        self::assertFalse($this->tool->mayViewerReadPreview($arguments, $this->actor(2)));
    }

    /**
     * The overlay of SECOND loses its element: it points at one that does
     * not exist.
     */
    private function orphanTheOverlayOfSecond(): void
    {
        $this->connectionPool->getConnectionForTable('sys_file_reference')
            ->update('sys_file_reference', ['uid_foreign' => 999], ['uid' => self::OVERLAY_OF_SECOND]);
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
        $user = $this->setUpBackendUser($uid);
        $user->groupData['explicit_allowdeny'] = 'tt_content:CType:textmedia';
        // The core StoragePermissionsAspect only attaches mounts and
        // permissions when the storage object is built inside a BACKEND request.
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://typo3-testing.local/typo3/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

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
     * @return list<int>
     */
    private function liveReferences(int $elementUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();

        $uids = $queryBuilder
            ->select('uid')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($elementUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter('tt_content')),
                $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter('assets')),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('sorting_foreign', 'ASC')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map(static fn(mixed $uid): int => is_numeric($uid) ? (int)$uid : 0, $uids);
    }

    /**
     * @return array<string, mixed>
     */
    private function referenceRow(int $uid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('*')
            ->from('sys_file_reference')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($row);

        return $row;
    }

    private function counter(int $elementUid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->select('assets')
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($elementUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
    }
}
