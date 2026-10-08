<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewTranslator;
use Netresearch\NrLlm\Service\Tool\Builtin\UpdateContentElementTool;
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
 * `update_content_element` against a real database, the real core TCA and
 * the real DataHandler (ADR-198): the field set is the element type's form,
 * values are checked by the rules the creating writer applies (ADR-196), and
 * only the acting user's own rights write.
 */
#[CoversClass(UpdateContentElementTool::class)]
final class UpdateContentElementToolTest extends AbstractFunctionalTestCase
{
    use AssertsGermanPreviewTrait;
    use RegistersTheInterferingHookTrait;

    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend'];

    private const OPEN_PAGE = 1;

    private const CLOSED_PAGE = 2;

    private const NARROWED_PAGE = 3;

    private const TEXT = 20;

    private const RAW_HTML = 21;

    private const ON_CLOSED = 22;

    private const TRANSLATION = 23;

    private const ON_NARROWED = 24;

    private UpdateContentElementTool $tool;

    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->connectionPool = $connectionPool;

        $this->importFixture('BeUsers.csv');

        $pages = $this->connectionPool->getConnectionForTable('pages');
        foreach ([
            [self::OPEN_PAGE, 'Open', Permission::ALL, ''],
            [self::CLOSED_PAGE, 'Closed', Permission::ALL & ~Permission::CONTENT_EDIT, ''],
            [self::NARROWED_PAGE, 'Narrowed', Permission::ALL, 'TCEFORM.tt_content.subheader.disabled = 1'],
        ] as [$uid, $title, $everybody, $tsConfig]) {
            $pages->insert('pages', [
                'uid' => $uid, 'pid' => 0, 'title' => $title, 'doktype' => 1, 'slug' => '/' . $uid,
                'TSconfig' => $tsConfig,
                'perms_userid' => 1, 'perms_user' => Permission::ALL,
                'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => $everybody,
            ]);
        }

        $content = $this->connectionPool->getConnectionForTable('tt_content');
        foreach ([
            [self::TEXT, self::OPEN_PAGE, 'text', 'Old header', 'Old body', 0, 0],
            [self::RAW_HTML, self::OPEN_PAGE, 'html', 'Markup', '<b>raw</b>', 0, 0],
            [self::ON_CLOSED, self::CLOSED_PAGE, 'text', 'Guarded', '', 0, 0],
            [self::TRANSLATION, self::OPEN_PAGE, 'text', 'Alte Überschrift', '', 1, self::TEXT],
            [self::ON_NARROWED, self::NARROWED_PAGE, 'text', 'Narrowed', '', 0, 0],
        ] as [$uid, $pid, $type, $header, $body, $language, $parent]) {
            $content->insert('tt_content', [
                'uid' => $uid, 'pid' => $pid, 'colPos' => 0, 'CType' => $type, 'header' => $header,
                'bodytext' => $body, 'sys_language_uid' => $language, 'l18n_parent' => $parent,
            ]);
        }

        $groups = $this->connectionPool->getConnectionForTable('be_groups');
        $groups->insert('be_groups', ['uid' => 7, 'pid' => 0, 'title' => 'Editors', 'db_mountpoints' => '1,2,3']);
        $groups->update('be_users', ['usergroup' => '7', 'options' => 3], ['uid' => 2]);

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');

        $this->tool = new UpdateContentElementTool($this->connectionPool, new ApprovalPreviewTranslator($this->getService(LanguageServiceFactory::class)));
    }

    protected function tearDown(): void
    {
        $this->unregisterInterferingHook();
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function anAdminChangesHeaderAndBodytext(): void
    {
        $result = $this->tool->execute(
            ['uid' => self::TEXT, 'fields' => ['header' => 'New header', 'bodytext' => '<p>New body</p>']],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(WriteKind::UPDATED, $result->writeKind);
        self::assertSame(self::TEXT, $result->writeTarget?->uid);

        $row = $this->elementRow(self::TEXT);
        self::assertSame('New header', $row['header'] ?? null);
        self::assertIsString($row['bodytext'] ?? null);
        self::assertStringContainsString('New body', $row['bodytext']);
    }

    #[Test]
    public function anEditorWithTheFieldGrantSetsAnExcludeColumn(): void
    {
        $result = $this->tool->execute(
            ['uid' => self::TEXT, 'fields' => ['subheader' => 'A subheader']],
            ToolExecutionContext::fromBackendUser($this->editor('tt_content:subheader')),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame('A subheader', $this->elementRow(self::TEXT)['subheader'] ?? null);
    }

    #[Test]
    public function aColumnDroppedAfterTheChecksIsNamedAndWhatTookIsReportedAsWritten(): void
    {
        $this->registerInterferingHook();
        InterferesWithAnUpdateHook::$dropColumn = 'subheader';

        $result = $this->tool->execute(
            ['uid' => self::TEXT, 'fields' => ['header' => 'New header', 'subheader' => 'A subheader']],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('in part: header took. Did not take: subheader', $result->content);
        self::assertSame(self::TEXT, $result->writeTarget?->uid);
        self::assertSame('New header', $this->elementRow(self::TEXT)['header'] ?? null);
    }

    #[Test]
    public function anUpdateThatTookIsReportedAsWrittenThoughTypo3Complained(): void
    {
        $this->registerInterferingHook();
        InterferesWithAnUpdateHook::$complain = true;

        $result = $this->tool->execute(
            ['uid' => self::TEXT, 'fields' => ['header' => 'New header']],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertStringStartsWith('Updated content element [20] "Old header": header. TYPO3 reported:', $result->content);
        self::assertStringContainsString('A test hook complains and carries on', $result->content);
        self::assertSame(WriteKind::UPDATED, $result->writeKind);
        self::assertSame('New header', $this->elementRow(self::TEXT)['header'] ?? null);
    }

    /**
     * @return iterable<string, array{string, array{int, string, string}}>
     */
    public static function changesAReaderCannotSee(): iterable
    {
        // Shown as "Old header" → "Old header", each of these would hide the
        // change it is.
        yield 'a no-break space for a space' => ["Old\u{00A0}header", [4, '“ ”', '“\\u{00A0}”']];
        yield 'a zero-width space, no whitespace to the excerpt' => ["Old header\u{200B}", [11, '(nothing)', '“\\u{200B}”']];
        yield 'a bidi isolate' => ["Old header\u{2066}", [11, '(nothing)', '“\\u{2066}”']];
        yield 'a tag character' => ["Old header\u{E0041}", [11, '(nothing)', '“\\u{E0041}”']];
        yield 'a soft hyphen' => ["Old head\u{00AD}er", [9, '(nothing)', '“\\u{00AD}”']];
        yield 'a backspace' => ["Old header\x08", [11, '(nothing)', '“\\u{0008}”']];
        // And a value that spells an escape out is not taken for one.
        yield 'a spelled-out escape beside a real one' => [
            "Old header\\u{00A0}\u{00A0}",
            [11, '(nothing)', '“\\\\u{00A0}\\u{00A0}”'],
        ];
    }

    /**
     * @param array{int, string, string} $expected the character the change starts at, the old and the new section
     */
    #[Test]
    #[DataProvider('changesAReaderCannotSee')]
    public function thePreviewNamesACharacterAReaderCannotSee(string $header, array $expected): void
    {
        [$from, $old, $new] = $expected;

        self::assertSame(
            sprintf('%s: changed from character %d on, currently %s, proposed %s', $this->headerLabelIn('en'), $from, $old, $new),
            $this->previewIn('en', ['uid' => self::TEXT, 'fields' => ['header' => $header]])[5] ?? null,
        );
    }

    #[Test]
    public function anEditorWithoutTheFieldGrantIsRefusedBeforeTheWrite(): void
    {
        $result = $this->tool->execute(
            ['uid' => self::TEXT, 'fields' => ['header' => 'Changed', 'subheader' => 'A subheader']],
            ToolExecutionContext::fromBackendUser($this->editor('')),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('grant for tt_content:subheader. Nothing was written.', $result->content);
        // Refused whole: the header the editor could set is not written either.
        self::assertSame('Old header', $this->elementRow(self::TEXT)['header'] ?? null);
    }

    #[Test]
    public function anEditorMayNotChangeAnElementOnAPageTheyMayNotEdit(): void
    {
        $result = $this->tool->execute(
            ['uid' => self::ON_CLOSED, 'fields' => ['header' => 'Changed']],
            ToolExecutionContext::fromBackendUser($this->editor('')),
        );

        self::assertTrue($result->isError);
        self::assertSame('Content element not found or not permitted.', $result->content);
        self::assertSame('Guarded', $this->elementRow(self::ON_CLOSED)['header'] ?? null);
    }

    #[Test]
    public function anElementOfATypeOutsideTheExclusionRuleIsRefused(): void
    {
        $result = $this->tool->execute(
            ['uid' => self::RAW_HTML, 'fields' => ['bodytext' => '<script>alert(1)</script>']],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('of type "html", which this tool does not edit', $result->content);
        self::assertSame('<b>raw</b>', $this->elementRow(self::RAW_HTML)['bodytext'] ?? null);
    }

    #[Test]
    public function aRelationColumnIsRefusedAndTheOfferedColumnsNamed(): void
    {
        $result = $this->tool->execute(
            ['uid' => self::TEXT, 'fields' => ['categories' => '1']],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('"categories" is not a scalar column of content type "text"', $result->content);
        self::assertStringContainsString('header', $result->content);
        self::assertStringContainsString('bodytext', $result->content);
    }

    #[Test]
    public function theHiddenColumnIsNotAFieldOfThisTool(): void
    {
        $result = $this->tool->execute(
            ['uid' => self::TEXT, 'fields' => ['hidden' => 0]],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('publish_record', $result->content);
    }

    #[Test]
    public function aSelectValueOutsideTheItemsIsRefused(): void
    {
        $result = $this->tool->execute(
            ['uid' => self::TEXT, 'fields' => ['header_layout' => '99']],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('the value for "header_layout" must be one of', $result->content);
    }

    #[Test]
    public function aColumnATranslationTakesFromItsDefaultLanguageElementIsRefused(): void
    {
        // Core declares no user column `l10n_mode = exclude`; an installation
        // may, and this is the one the test sets.
        $tca = $GLOBALS['TCA'];
        self::assertIsArray($tca);
        $content = $tca['tt_content'] ?? null;
        self::assertIsArray($content);
        $columns = $content['columns'] ?? null;
        self::assertIsArray($columns);
        $subheader = $columns['subheader'] ?? null;
        self::assertIsArray($subheader);
        $subheader['l10n_mode'] = 'exclude';
        $columns['subheader']   = $subheader;
        $content['columns']     = $columns;
        $tca['tt_content']      = $content;
        $GLOBALS['TCA']         = $tca;

        $result = $this->tool->execute(
            ['uid' => self::TRANSLATION, 'fields' => ['subheader' => 'Untertitel']],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('taken from its default-language element [20]', $result->content);
    }

    #[Test]
    public function aColumnThePageTsConfigHidesIsRefused(): void
    {
        $result = $this->tool->execute(
            ['uid' => self::ON_NARROWED, 'fields' => ['subheader' => 'Hidden in the form']],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('TCEFORM.tt_content.subheader.disabled', $result->content);
    }

    /**
     * #1017: page TSconfig is read for the run's ACTING user. The ambient
     * backend user is the approver at resume, or nobody in a worker; neither
     * may change what plan() refuses.
     */
    #[Test]
    public function thePageTsConfigIsReadForTheActingUserNotTheAmbientOne(): void
    {
        $rule      = 'TCEFORM.tt_content.subheader.disabled';
        $arguments = ['uid' => self::TEXT, 'fields' => ['subheader' => 'Shown in the form']];
        $users     = $this->connectionPool->getConnectionForTable('be_users');

        // The approver's user TSconfig hides the column; the acting admin's does not.
        $users->update('be_users', ['TSconfig' => 'page.' . $rule . ' = 1'], ['uid' => 2]);

        $acting  = ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1));
        $ambient = $this->setUpBackendUser(2);
        self::assertStringNotContainsString($rule, implode("\n", $this->tool->previewCall($arguments, $acting)));
        self::assertSame($ambient, $GLOBALS['BE_USER'], 'the preview must not replace the ambient user');

        // The acting admin's own override applies, with no ambient user at all.
        $users->update('be_users', ['TSconfig' => 'page.' . $rule . ' = 1'], ['uid' => 1]);
        $acting = ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1));
        unset($GLOBALS['BE_USER']);
        self::assertStringContainsString($rule, implode("\n", $this->tool->previewCall($arguments, $acting)));
        self::assertArrayNotHasKey('BE_USER', $GLOBALS, 'the preview must not leave an ambient user behind');
    }

    #[Test]
    public function aMissingElementIsRefusedInTheSameWordsAsAForbiddenOne(): void
    {
        $result = $this->tool->execute(
            ['uid' => 987654, 'fields' => ['header' => 'x']],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertTrue($result->isError);
        self::assertSame('Content element not found or not permitted.', $result->content);
    }

    #[Test]
    public function thePreviewShowsEveryColumnBeforeAndAfterAndWritesNothing(): void
    {
        $arguments = ['uid' => self::TEXT, 'fields' => ['header' => 'New header', 'bodytext' => 'Old body']];

        self::assertSame([
            'Change content element',
            'Content element: “Old header”',
            'Content type: ' . $this->typeLabelIn('en'),
            'Location: on page “Open”',
            'Language: default language',
            $this->headerLabelIn('en') . ': currently “Old header”, proposed “New header”',
            $this->bodytextLabelIn('en') . ': unchanged, “Old body”',
            'Technical details: table tt_content, UID 20, page UID 1, language UID 0, content type text, fields header, bodytext',
        ], $this->previewIn('en', $arguments));
        $german = $this->previewIn('de', $arguments);
        self::assertSame([
            'Inhaltselement ändern',
            'Inhaltselement: „Old header“',
            'Inhaltstyp: ' . $this->typeLabelIn('de'),
            'Ort: auf der Seite „Open“',
            'Sprache: Standardsprache',
            $this->headerLabelIn('de') . ': aktuell „Old header“, Vorschlag „New header“',
            $this->bodytextLabelIn('de') . ': unverändert, „Old body“',
            'Technische Details: Tabelle tt_content, UID 20, Seite UID 1, Sprach-UID 0, Inhaltstyp text, Felder header, bodytext',
        ], $german);
        self::assertGermanEditorLines($german);
        self::assertSame('Old header', $this->elementRow(self::TEXT)['header'] ?? null);
    }

    /**
     * The record type's `columnsOverrides` label wins over the column's own,
     * pinned with a literal so the expectation is not computed the way the
     * code computes it; a label reference that resolves to nothing falls back
     * to the column name instead of printing the key (ADR-213).
     */
    #[Test]
    public function thePreviewNamesAFieldByTheLabelOfTheElementsType(): void
    {
        $tca = $GLOBALS['TCA'];
        self::setTcaAt('Pinned headline label', 'tt_content', 'types', 'text', 'columnsOverrides', 'header', 'label');
        self::setTcaAt('nrllm_missing.domain:nothing', 'tt_content', 'types', 'text', 'columnsOverrides', 'bodytext', 'label');
        self::setTcaAt('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:nrllm.missing', 'tt_content', 'columns', 'bodytext', 'label');

        try {
            $lines = $this->previewIn('en', ['uid' => self::TEXT, 'fields' => ['header' => 'New header', 'bodytext' => 'New body']]);
        } finally {
            $GLOBALS['TCA'] = $tca;
        }

        self::assertSame('Pinned headline label: currently “Old header”, proposed “New header”', $lines[5] ?? null);
        self::assertSame('bodytext: currently “Old body”, proposed “New body”', $lines[6] ?? null);
    }

    #[Test]
    public function thePreviewOfATranslationSaysSo(): void
    {
        $german = $this->previewIn('de', ['uid' => self::TRANSLATION, 'fields' => ['header' => 'Neue Überschrift']]);

        self::assertSame('Sprache: Übersetzung, nicht die Standardsprache', $german[4] ?? null);
        self::assertStringContainsString('Sprach-UID 1', $german[6] ?? '');
    }

    #[Test]
    public function thePreviewShowsAChangeInsideALongBodyAndBindsTheWholeValue(): void
    {
        $body = rtrim(str_repeat('Paragraph text that runs well past the excerpt. ', 5));
        $this->connectionPool->getConnectionForTable('tt_content')
            ->update('tt_content', ['bodytext' => $body], ['uid' => self::TEXT]);
        $changed = substr_replace($body, '[X](https://evil.example)', 180, 0);

        $german = $this->previewIn('de', ['uid' => self::TEXT, 'fields' => ['bodytext' => $changed]]);

        self::assertSame(
            $this->bodytextLabelIn('de') . ': geändert ab Zeichen 181, aktuell (nichts), Vorschlag „[X](https://evil.example)“',
            $german[5] ?? null,
        );
        self::assertStringEndsWith(
            sprintf(
                'Felder bodytext, bodytext vorher: %d Zeichen, sha256:%s; nachher: %d Zeichen, sha256:%s',
                mb_strlen($body),
                substr(hash('sha256', $body), 0, 12),
                mb_strlen($changed),
                substr(hash('sha256', $changed), 0, 12),
            ),
            $german[6] ?? '',
        );
        self::assertGermanEditorLines($german);
    }

    #[Test]
    public function theViewerGateAnswersForTheViewerNotTheRun(): void
    {
        $arguments = ['uid' => self::ON_CLOSED, 'fields' => ['header' => 'x']];

        self::assertTrue($this->tool->mayViewerReadPreview($arguments, $this->setUpBackendUser(1)));
        self::assertFalse($this->tool->mayViewerReadPreview($arguments, $this->editor('')));
    }

    private function editor(string $nonExcludeFields): BackendUserAuthentication
    {
        $editor                                  = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify']      = 'tt_content';
        $editor->groupData['non_exclude_fields'] = $nonExcludeFields;
        $editor->groupData['explicit_allowdeny'] = 'tt_content:CType:text';

        return $editor;
    }

    /**
     * @return array<string, mixed>
     */
    private function elementRow(int $uid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('*')
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($row);

        return $row;
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
        $admin               = $this->setUpBackendUser(1);
        $admin->user['lang'] = $language;

        return $this->tool->previewCall($arguments, ToolExecutionContext::fromBackendUser($admin));
    }

    private function headerLabelIn(string $language): string
    {
        return $this->tcaColumnLabelIn($language, 'tt_content', 'header', 'text');
    }

    private function bodytextLabelIn(string $language): string
    {
        return $this->tcaColumnLabelIn($language, 'tt_content', 'bodytext', 'text');
    }

    private function typeLabelIn(string $language): string
    {
        return $this->tcaItemLabelIn($language, 'tt_content', 'CType', 'text');
    }
}
