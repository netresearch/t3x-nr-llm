<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Error;
use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Service\CacheManager;
use Netresearch\NrLlm\Service\Feature\TranslationPromptBuilder;
use Netresearch\NrLlm\Service\Feature\TranslationService;
use Netresearch\NrLlm\Service\Glossary\GlossaryResolver;
use Netresearch\NrLlm\Service\LlmConfigurationServiceInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Tool\Builtin\CreateTranslationDraftTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Translation\TranslatorInterface;
use Netresearch\NrLlm\Specialized\Translation\TranslatorRegistryInterface;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\FailsLikeAFlashMessageHook;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\RegistersTheFailingHookTrait;
use Netresearch\NrLlm\Tests\Fixtures\Translation\RecordingTranslator;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager as Typo3CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * The write path of the fifth writing tool (ADR-146), against a real database,
 * the real {@see \TYPO3\CMS\Core\DataHandling\DataHandler} and a real site
 * configuration.
 *
 * The site configuration is not scaffolding: core's
 * {@see \TYPO3\CMS\Core\DataHandling\DataHandler::localize()} resolves the
 * target language through it and refuses a language the site does not define.
 * The tool deliberately does not re-implement that check, so a test without a
 * site would prove the refusal rather than the translation.
 *
 * The machine translation of the text (ADR-209) runs through the real
 * {@see TranslationService}, the real glossary lookup and the real cache
 * manager; only the translators are recording doubles, which answer
 * "[<target>] <text>" so a translated field is told from a copied one by
 * its prefix.
 */
#[CoversClass(CreateTranslationDraftTool::class)]
final class CreateTranslationDraftToolTest extends AbstractFunctionalTestCase
{
    use RegistersTheFailingHookTrait;

    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend'];

    private const ROOT_PAGE = 1;

    private const CHILD_PAGE = 2;

    private const ELEMENT = 20;

    /** Defined by the site configuration below. */
    private const GERMAN = 1;

    /** NOT defined by the site configuration below. */
    private const UNDEFINED_LANGUAGE = 9;

    private CreateTranslationDraftTool $tool;

    private ConnectionPool $connectionPool;

    private RecordingTranslator $llm;

    private RecordingTranslator $deepl;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->connectionPool = $connectionPool;

        $this->importFixture('BeUsers.csv');

        $pages = $this->connectionPool->getConnectionForTable('pages');
        $pages->insert('pages', [
            'uid' => self::ROOT_PAGE, 'pid' => 0, 'title' => 'Root', 'doktype' => 1, 'slug' => '/',
            'sorting' => 1, 'is_siteroot' => 1, 'sys_language_uid' => 0, 'l10n_parent' => 0,
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
        ]);
        $pages->insert('pages', [
            'uid' => self::CHILD_PAGE, 'pid' => self::ROOT_PAGE, 'title' => 'Child', 'doktype' => 1,
            'slug' => '/child', 'sorting' => 1, 'sys_language_uid' => 0, 'l10n_parent' => 0,
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
        ]);

        $this->connectionPool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => self::ELEMENT, 'pid' => self::CHILD_PAGE, 'colPos' => 0, 'sorting' => 1,
            'CType' => 'text', 'header' => 'Original', 'bodytext' => 'Original body',
            'sys_language_uid' => 0, 'l18n_parent' => 0,
        ]);

        $groups = $this->connectionPool->getConnectionForTable('be_groups');
        $groups->insert('be_groups', [
            'uid' => 7, 'pid' => 0, 'title' => 'Editors', 'db_mountpoints' => '1,2',
        ]);
        $groups->update('be_users', ['usergroup' => '7', 'options' => 3], ['uid' => 2]);

        $siteWriter = $this->get(SiteWriter::class);
        self::assertInstanceOf(SiteWriter::class, $siteWriter);
        $siteWriter->write('testing', [
            'rootPageId' => self::ROOT_PAGE,
            'base'       => 'https://example.com/',
            'languages'  => [
                [
                    'languageId' => 0,
                    'title'      => 'English',
                    'base'       => '/',
                    'locale'     => 'en_US.UTF-8',
                    'flag'       => 'us',
                ],
                [
                    'languageId'   => self::GERMAN,
                    'title'        => 'German',
                    'base'         => '/de/',
                    'locale'       => 'de_DE.UTF-8',
                    'flag'         => 'de',
                    'fallbackType' => 'strict',
                ],
            ],
        ]);

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');

        $this->llm   = new RecordingTranslator('llm', 'Language model');
        $this->deepl = new RecordingTranslator('deepl', 'DeepL');
        $this->tool  = $this->toolWith($this->llm, $this->deepl);
    }

    protected function tearDown(): void
    {
        $this->unregisterFailingHook();
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function aPageTranslationIsCreatedHiddenAndConnected(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['table' => 'pages', 'uid' => self::CHILD_PAGE, 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('Created hidden translation', $result->content);
        self::assertStringContainsString('not visible until a human unhides it', $result->content);

        $translation = $this->translationOf('pages', self::CHILD_PAGE, 'l10n_parent');
        self::assertSame(self::GERMAN, (int)($translation['sys_language_uid'] ?? -1));
        self::assertSame(self::CHILD_PAGE, (int)($translation['l10n_parent'] ?? 0));
        self::assertSame(1, (int)($translation['hidden'] ?? 0), 'a drafted translation must never be visible');
        // The source is untouched, in particular still visible.
        self::assertSame(0, (int)($this->row('pages', self::CHILD_PAGE)['hidden'] ?? 1));
    }

    /**
     * NEXT-167: the result leads with the new uid, as create_page_draft's
     * does, so a follow-up call does not pick up the source uid instead.
     */
    #[Test]
    public function theResultLeadsWithTheNewUid(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::ELEMENT, 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        $newUid = (int)($this->translationOf('tt_content', self::ELEMENT, 'l18n_parent')['uid'] ?? 0);
        self::assertGreaterThan(self::ELEMENT, $newUid);
        self::assertStringStartsWith(sprintf('New translation uid: %d.', $newUid), $result->content);
    }

    /**
     * Core writes a translation through a DataHandler of its own, like a
     * copy. A hook that fails there fails before core records the new uid,
     * and "no translation was created" would be false where one exists — a
     * visible one, since the tool hides it only afterwards. The call fails
     * (ADR-206).
     */
    #[Test]
    public function aHookThatFailsInTheTranslationRunEndsTheCall(): void
    {
        $context = ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1));
        $this->failInTheNextWrite(FailsLikeAFlashMessageHook::AFTER_ALL_OPERATIONS);

        try {
            $this->tool->execute(['table' => 'tt_content', 'uid' => self::ELEMENT, 'language' => self::GERMAN], $context);
            self::fail('The failure in the translation run did not end the call.');
        } catch (Error $failure) {
            self::assertSame('Call to a member function set() on null', $failure->getMessage());
        }

        // A translation registers itself as a nested call; the run's reset
        // ran before the rethrow, so the next run in this process starts clean.
        $runtimeCache = $this->get('cache.runtime');
        self::assertInstanceOf(FrontendInterface::class, $runtimeCache);
        self::assertFalse($runtimeCache->has('core-datahandler-nestedElementCalls-'));
    }

    #[Test]
    public function aContentElementTranslationIsCreatedHiddenAndConnected(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::ELEMENT, 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);

        $translation = $this->translationOf('tt_content', self::ELEMENT, 'l18n_parent');
        self::assertSame(self::GERMAN, (int)($translation['sys_language_uid'] ?? -1));
        self::assertSame(self::ELEMENT, (int)($translation['l18n_parent'] ?? 0));
        self::assertSame(1, (int)($translation['hidden'] ?? 0));
        // The text is machine-translated from the SOURCE row (ADR-209), not
        // from the copy core prefixed with "[Translate to German:]": the
        // prefix must not reach the translator, and it is gone from the draft.
        self::assertSame('[de] Original', $translation['header'] ?? null);
        self::assertStringContainsString('[de] Original body', $this->stringOf($translation['bodytext'] ?? null));
        self::assertSame(['Original', 'Original body'], array_column($this->llm->calls, 'text'));
        self::assertSame(['en'], array_values(array_unique(array_column($this->llm->calls, 'source'))));
        self::assertSame(['de'], array_values(array_unique(array_column($this->llm->calls, 'target'))));
        self::assertStringContainsString(
            'Machine-translated 2 text field(s) (header, bodytext) from "en" to "de" with Language model (llm)',
            $result->content,
        );
        self::assertSame(WriteKind::CREATED, $result->writeKind);
    }

    #[Test]
    public function aPageTranslationCarriesTheTranslatedTitle(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['table' => 'pages', 'uid' => self::CHILD_PAGE, 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame('[de] Child', $this->translationOf('pages', self::CHILD_PAGE, 'l10n_parent')['title'] ?? null);
        self::assertSame('Child', $this->row('pages', self::CHILD_PAGE)['title'] ?? null, 'the source is untouched');
    }

    /**
     * Rich text goes to the translator as HTML — DeepL's `tag_handling` — and
     * a plain field does not, so the markup survives and a headline is not
     * treated as markup.
     */
    #[Test]
    public function richTextGoesAsHtmlAndKeepsItsMarkup(): void
    {
        $this->connectionPool->getConnectionForTable('tt_content')->update(
            'tt_content',
            ['bodytext' => '<p>Original <strong>body</strong></p>'],
            ['uid' => self::ELEMENT],
        );
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::ELEMENT, 'language' => self::GERMAN, 'translator' => 'deepl'],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        $options = array_column($this->deepl->calls, 'options', 'text');
        self::assertSame('html', $options['<p>Original <strong>body</strong></p>']['tag_handling'] ?? null);
        self::assertArrayNotHasKey('tag_handling', $options['Original'] ?? []);
        self::assertSame([], $this->llm->calls, 'the named translator, and only it, is used');
        self::assertStringContainsString('<strong>body</strong>', $this->stringOf($this->translationOf('tt_content', self::ELEMENT, 'l18n_parent')['bodytext'] ?? null));
        self::assertStringContainsString('with DeepL (deepl)', $result->content);
    }

    /**
     * The site of the record is passed on, so the glossary that site keeps
     * for the pair reaches the translator (ADR-208).
     */
    #[Test]
    public function theSiteGlossaryReachesTheTranslator(): void
    {
        $this->connectionPool->getConnectionForTable('tx_nrllm_glossary')->insert('tx_nrllm_glossary', [
            'uid' => 1, 'pid' => 0, 'name' => 'Terms', 'site_identifier' => 'testing',
            'source_language' => 'en', 'target_language' => 'de', 'entries' => 'Original = Ursprung',
        ]);
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::ELEMENT, 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertNotSame([], $this->llm->calls);
        foreach ($this->llm->calls as $call) {
            self::assertSame(['Original' => 'Ursprung'], $call['options']['glossary'] ?? null);
        }
    }

    /**
     * A failed machine translation is said plainly and leaves the draft with
     * the copied source text — no half-translated record, and no success that
     * did not happen. The record exists, so the write is still announced.
     */
    #[Test]
    public function aFailedTranslationLeavesTheSourceTextAndSaysSo(): void
    {
        $admin = $this->setUpBackendUser(1);
        $this->llm->failNext = new RuntimeException('the provider is down');

        $result = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::ELEMENT, 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('The text was NOT machine-translated', $result->content);
        self::assertStringContainsString('the provider is down', $result->content);
        self::assertStringNotContainsString('Machine-translated', $result->content);
        self::assertSame(WriteKind::CREATED, $result->writeKind);

        $translation = $this->translationOf('tt_content', self::ELEMENT, 'l18n_parent');
        self::assertSame(1, (int)($translation['hidden'] ?? 0));
        self::assertStringContainsString('Translate to German', $this->stringOf($translation['header'] ?? null));
        self::assertStringNotContainsString('[de]', $this->stringOf($translation['bodytext'] ?? null));
    }

    /**
     * The same text, pair, translator and glossary are translated once: an
     * overwritten draft is re-translated from the cache (ADR-209).
     */
    #[Test]
    public function aRepeatedTranslationIsAnsweredFromTheCache(): void
    {
        $admin = $this->setUpBackendUser(1);

        $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::ELEMENT, 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($admin),
        );
        $again = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::ELEMENT, 'language' => self::GERMAN, 'overwrite' => true],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($again->isError, $again->content);
        self::assertCount(2, $this->llm->calls, 'two fields, translated once');
        self::assertStringContainsString('(2 from the translation cache)', $again->content);
        self::assertSame('[de] Original', $this->translationOf('tt_content', self::ELEMENT, 'l18n_parent')['header'] ?? null);
    }

    #[Test]
    public function aTranslatorThatIsNotConfiguredIsRefusedBeforeAnythingIsCreated(): void
    {
        $this->tool = $this->toolWith($this->llm, new RecordingTranslator('deepl', 'DeepL', available: false));
        $admin      = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['table' => 'tt_content', 'uid' => self::ELEMENT, 'language' => self::GERMAN, 'translator' => 'deepl'],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('"deepl" is not configured', $result->content);
        self::assertNull($this->maybeTranslationOf('tt_content', self::ELEMENT, 'l18n_parent'));
    }

    /**
     * A `text` column whose type renders it in a code editor holds code, not
     * prose, and is left as core copied it.
     */
    #[Test]
    public function aColumnInACodeEditorIsNotTranslated(): void
    {
        $tca = $GLOBALS['TCA'];
        self::assertIsArray($tca);

        try {
            $GLOBALS['TCA'] = array_replace_recursive($tca, [
                'tt_content' => ['types' => ['text' => ['columnsOverrides' => ['bodytext' => ['config' => ['renderType' => 'codeEditor']]]]]],
            ]);

            $result = $this->tool->execute(
                ['table' => 'tt_content', 'uid' => self::ELEMENT, 'language' => self::GERMAN],
                ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
            );
        } finally {
            $GLOBALS['TCA'] = $tca;
        }

        self::assertFalse($result->isError, $result->content);
        self::assertSame(['Original'], array_column($this->llm->calls, 'text'));
        self::assertStringContainsString('(header)', $result->content);
    }

    #[Test]
    public function anExistingTranslationStopsTheCallAndIsNamed(): void
    {
        $admin = $this->setUpBackendUser(1);

        $first = $this->tool->execute(
            ['table' => 'pages', 'uid' => self::CHILD_PAGE, 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($admin),
        );
        self::assertFalse($first->isError, $first->content);
        $firstUid = (int)$this->translationOf('pages', self::CHILD_PAGE, 'l10n_parent')['uid'];

        $second = $this->tool->execute(
            ['table' => 'pages', 'uid' => self::CHILD_PAGE, 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertTrue($second->isError);
        self::assertStringContainsString('already has a translation', $second->content);
        self::assertStringContainsString('uid ' . $firstUid, $second->content);
        self::assertStringContainsString('"overwrite": true', $second->content);
        // Still exactly one.
        self::assertSame($firstUid, (int)$this->translationOf('pages', self::CHILD_PAGE, 'l10n_parent')['uid']);
    }

    #[Test]
    public function overwriteDiscardsTheOldTranslationAndCreatesAFreshOne(): void
    {
        $admin = $this->setUpBackendUser(1);

        $first = $this->tool->execute(
            ['table' => 'pages', 'uid' => self::CHILD_PAGE, 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($admin),
        );
        self::assertFalse($first->isError, $first->content);
        $oldUid = (int)$this->translationOf('pages', self::CHILD_PAGE, 'l10n_parent')['uid'];

        $second = $this->tool->execute(
            ['table' => 'pages', 'uid' => self::CHILD_PAGE, 'language' => self::GERMAN, 'overwrite' => true],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($second->isError, $second->content);
        self::assertStringContainsString('replacing translation [' . $oldUid . ']', $second->content);

        // The old one is deleted rather than gone: recoverable, and in the log.
        self::assertSame(1, (int)($this->row('pages', $oldUid)['deleted'] ?? 0));

        $fresh = $this->translationOf('pages', self::CHILD_PAGE, 'l10n_parent');
        self::assertNotSame($oldUid, (int)$fresh['uid']);
        self::assertSame(1, (int)($fresh['hidden'] ?? 0));
    }

    #[Test]
    public function aLanguageTheSiteDoesNotDefineIsRefusedByCoreAndSurfaced(): void
    {
        // The tool deliberately does not re-implement this check; core's
        // localize() does it and its complaint has to reach the caller.
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['table' => 'pages', 'uid' => self::CHILD_PAGE, 'language' => self::UNDEFINED_LANGUAGE],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('refused by TYPO3', $result->content);
        self::assertStringContainsString('9', $result->content);
    }

    #[Test]
    public function aSourceThatIsItselfATranslationIsRefused(): void
    {
        $admin = $this->setUpBackendUser(1);

        $first = $this->tool->execute(
            ['table' => 'pages', 'uid' => self::CHILD_PAGE, 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($admin),
        );
        self::assertFalse($first->isError, $first->content);
        $translationUid = (int)$this->translationOf('pages', self::CHILD_PAGE, 'l10n_parent')['uid'];

        $result = $this->tool->execute(
            ['table' => 'pages', 'uid' => $translationUid, 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('is itself a translation', $result->content);
    }

    #[Test]
    public function anEditorMayNotTranslateAPageTheyMayNotEdit(): void
    {
        $closed = $this->connectionPool->getConnectionForTable('pages');
        $closed->insert('pages', [
            'uid' => 5, 'pid' => self::ROOT_PAGE, 'title' => 'Closed', 'doktype' => 1, 'slug' => '/closed',
            'sorting' => 5, 'sys_language_uid' => 0, 'l10n_parent' => 0,
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::PAGE_SHOW,
        ]);

        $editor                             = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify'] = 'pages';

        $result = $this->tool->execute(
            ['table' => 'pages', 'uid' => 5, 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        self::assertSame('Record not found or not permitted.', $result->content);
        self::assertNull($this->maybeTranslationOf('pages', 5, 'l10n_parent'));
    }

    #[Test]
    public function thePreviewNamesTheDiscardOnItsOwnLine(): void
    {
        $admin = $this->setUpBackendUser(1);

        $first = $this->tool->execute(
            ['table' => 'pages', 'uid' => self::CHILD_PAGE, 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($admin),
        );
        self::assertFalse($first->isError, $first->content);
        $oldUid = (int)$this->translationOf('pages', self::CHILD_PAGE, 'l10n_parent')['uid'];

        $lines = $this->tool->previewCall(
            ['table' => 'pages', 'uid' => self::CHILD_PAGE, 'language' => self::GERMAN, 'overwrite' => true],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertCount(5, $lines);
        self::assertStringContainsString('Translate pages [2] "Child" into language 1', $lines[0]);
        self::assertStringContainsString('DISCARDS the existing translation [' . $oldUid . ']', $lines[1]);
        self::assertStringContainsString('hidden', $lines[4]);

        // A preview is a read: the translation it says it would discard is
        // still there afterwards.
        self::assertSame(0, (int)($this->row('pages', $oldUid)['deleted'] ?? 1));
    }

    #[Test]
    public function thePreviewWithoutADiscardHasNoDiscardLine(): void
    {
        $admin = $this->setUpBackendUser(1);

        $lines = $this->tool->previewCall(
            ['table' => 'tt_content', 'uid' => self::ELEMENT, 'language' => self::GERMAN],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertCount(4, $lines);
        foreach ($lines as $line) {
            self::assertStringNotContainsString('DISCARDS', $line);
        }
    }

    /**
     * The approver reads that the text will be machine-translated, which
     * fields, and by which service it is sent to (ADR-209). A preview is a
     * read: nothing is translated to produce it.
     */
    #[Test]
    public function thePreviewNamesTheMachineTranslationAndTheTranslator(): void
    {
        $admin = $this->setUpBackendUser(1);

        $lines = $this->tool->previewCall(
            ['table' => 'tt_content', 'uid' => self::ELEMENT, 'language' => self::GERMAN, 'translator' => 'deepl'],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertSame(
            'text: MACHINE-TRANSLATED by DeepL (deepl) — header, bodytext; the glossary of the record\'s site applies',
            $lines[2] ?? null,
        );
        self::assertSame([], $this->deepl->calls);
    }

    private function toolWith(RecordingTranslator $llm, RecordingTranslator $deepl): CreateTranslationDraftTool
    {
        $translators = ['llm' => $llm, 'deepl' => $deepl];
        $registry    = self::createStub(TranslatorRegistryInterface::class);
        $registry->method('get')->willReturnCallback(
            static fn(string $identifier): TranslatorInterface => $translators[$identifier]
                ?? throw new ServiceUnavailableException('No translator ' . $identifier, 'translation'),
        );
        $registry->method('has')->willReturnCallback(static fn(string $identifier): bool => isset($translators[$identifier]));

        $typo3Caches = self::createStub(Typo3CacheManager::class);
        $typo3Caches->method('getCache')->willReturn(new VariableFrontend('nrllm_responses', new TransientMemoryBackend()));

        $siteFinder = $this->get(SiteFinder::class);
        self::assertInstanceOf(SiteFinder::class, $siteFinder);

        return new CreateTranslationDraftTool(
            $this->connectionPool,
            new TranslationService(
                self::createStub(LlmServiceManagerInterface::class),
                $registry,
                self::createStub(LlmConfigurationServiceInterface::class),
                new TranslationPromptBuilder(),
                null,
                new GlossaryResolver($this->connectionPool),
                null,
                new CacheManager($typo3Caches),
            ),
            $siteFinder,
        );
    }

    private function stringOf(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function translationOf(string $table, int $uid, string $parentField): array
    {
        $row = $this->maybeTranslationOf($table, $uid, $parentField);
        self::assertIsArray($row, sprintf('%s [%d] must have a translation', $table, $uid));

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function maybeTranslationOf(string $table, int $uid, string $parentField): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq($parentField, $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        self::assertLessThanOrEqual(1, count($rows), 'at most one undeleted translation may exist');

        return $rows[0] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $table, int $uid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('*')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($row, sprintf('%s [%d] must exist', $table, $uid));

        return $row;
    }
}
