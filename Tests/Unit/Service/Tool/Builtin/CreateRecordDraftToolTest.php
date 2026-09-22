<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Doctrine\DBAL\Result;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\ValueObject\EditorAction;
use Netresearch\NrLlm\Service\Tool\Builtin\CreateRecordDraftTool;
use Netresearch\NrLlm\Service\Tool\EditorActionInterface;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeEditorActionTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeRecordCreatorTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\TypoScript\AST\Node\RootNode;
use TYPO3\CMS\Core\TypoScript\PageTsConfig;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Argument validation of the generic record creator (ADR-197).
 *
 * Every assertion here stops the call BEFORE the write. The one row it reads
 * is the page the call addresses, which the {@see ConnectionPool} stub hands
 * out — to an admin, who may edit content on every page — with no TSconfig,
 * seeded in the runtime cache; a query for any other table fails the test.
 * The table and column rules read the TCA, the deny-list reads the extension
 * configuration, and the writer lookup reads the tagged tool set. The creation
 * itself — the page permission, the grants, the page's TSconfig, the
 * read-back — is exercised against a real database in
 * {@see \Netresearch\NrLlm\Tests\Functional\Service\Tool\CreateRecordDraftToolTest}.
 */
#[CoversClass(CreateRecordDraftTool::class)]
final class CreateRecordDraftToolTest extends AbstractUnitTestCase
{
    private const TABLE = 'tx_demo_domain_model_item';

    private CreateRecordDraftTool $tool;

    /** @var array<string, mixed> */
    private array $globalsBackup = [];

    private CacheManager $cacheManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->globalsBackup = [
            'TCA'            => $GLOBALS['TCA'] ?? null,
            'LANG'           => $GLOBALS['LANG'] ?? null,
            'BE_USER'        => $GLOBALS['BE_USER'] ?? null,
            'TYPO3_CONF_VARS' => $GLOBALS['TYPO3_CONF_VARS'] ?? null,
        ];

        $GLOBALS['TCA'] = [
            'pages'      => ['ctrl' => ['enablecolumns' => ['disabled' => 'hidden']], 'columns' => ['title' => ['config' => ['type' => 'input']]]],
            'tt_content' => ['ctrl' => ['enablecolumns' => ['disabled' => 'hidden']], 'columns' => ['header' => ['config' => ['type' => 'input']]]],
            'sys_demo'   => ['ctrl' => ['enablecolumns' => ['disabled' => 'hidden']], 'columns' => ['title' => ['config' => ['type' => 'input']]]],
            'be_users'   => ['ctrl' => ['enablecolumns' => ['disabled' => 'disable']], 'columns' => ['username' => ['config' => ['type' => 'input']]]],
            'tx_demo_admin_only' => ['ctrl' => ['adminOnly' => true, 'enablecolumns' => ['disabled' => 'hidden']], 'columns' => []],
            'tx_demo_hidden'     => ['ctrl' => ['hideTable' => true, 'enablecolumns' => ['disabled' => 'hidden']], 'columns' => []],
            'tx_demo_read_only'  => ['ctrl' => ['readOnly' => true, 'enablecolumns' => ['disabled' => 'hidden']], 'columns' => []],
            'tx_demo_no_hidden'  => ['ctrl' => ['delete' => 'deleted'], 'columns' => ['title' => ['config' => ['type' => 'input']]]],
            // The record type lives in a related record (`field:field`).
            'tx_demo_foreign_type' => [
                'ctrl'    => ['type' => 'parent:kind', 'enablecolumns' => ['disabled' => 'hidden']],
                'types'   => ['0' => ['showitem' => 'title']],
                'columns' => ['title' => ['config' => ['type' => 'input']]],
            ],
            self::TABLE => [
                'ctrl' => [
                    'title'                 => 'Demo item',
                    'type'                  => 'kind',
                    'delete'                => 'deleted',
                    'tstamp'                => 'tstamp',
                    'languageField'         => 'sys_language_uid',
                    'transOrigPointerField' => 'l10n_parent',
                    'editlock'              => 'editlock',
                    'enablecolumns'         => ['disabled' => 'hidden', 'starttime' => 'starttime', 'fe_group' => 'fe_group'],
                ],
                'types' => [
                    'note'  => [
                        'showitem'         => 'title, teaser, kind, --palette--;;timing, --div--;More, featured, contact, tone, related, colour, rank, topic, section, code, handle, alias, reply_to, shade, summary, lede, story, rating, serial, byline, motto, remark, cc, locked, stamp, founded',
                        // A legacy `required` left in an override's eval:
                        // TcaMigration moves it out of the base column only,
                        // and the DataHandler ignores a token it does not know.
                        // `stamp` is read-only for this type only.
                        'columnsOverrides' => [
                            'byline' => ['config' => ['eval' => 'trim,required']],
                            'stamp'  => ['config' => ['readOnly' => true]],
                        ],
                    ],
                    'story' => ['showitem' => 'title, kind, --palette--;;timing'],
                ],
                'palettes' => [
                    'timing' => ['showitem' => 'published_at, --linebreak--, priority'],
                ],
                'columns' => [
                    'hidden'           => ['exclude' => true, 'config' => ['type' => 'check']],
                    'starttime'        => ['config' => ['type' => 'datetime']],
                    'fe_group'         => ['config' => ['type' => 'select', 'renderType' => 'selectSingle', 'items' => []]],
                    'sys_language_uid' => ['config' => ['type' => 'language']],
                    'l10n_parent'      => ['config' => ['type' => 'select', 'renderType' => 'selectSingle', 'items' => [], 'foreign_table' => self::TABLE]],
                    'editlock'         => ['config' => ['type' => 'check']],
                    'title'            => ['label' => 'Title', 'config' => ['type' => 'input', 'max' => 100, 'required' => true]],
                    'teaser'           => ['label' => 'Teaser', 'config' => ['type' => 'text']],
                    'kind'             => ['label' => 'Kind', 'config' => ['type' => 'select', 'renderType' => 'selectSingle', 'items' => [['label' => 'Note', 'value' => 'note'], ['label' => 'Story', 'value' => 'story']], 'default' => 'note']],
                    'published_at'     => ['label' => 'Published at', 'config' => ['type' => 'datetime']],
                    'priority'         => ['label' => 'Priority', 'config' => ['type' => 'number', 'range' => ['lower' => 1, 'upper' => 5]]],
                    'featured'         => ['label' => 'Featured', 'config' => ['type' => 'check']],
                    'contact'          => ['label' => 'Contact', 'config' => ['type' => 'email']],
                    'tone'             => ['label' => 'Tone', 'config' => ['type' => 'radio', 'items' => [['label' => 'Calm', 'value' => 'calm'], ['label' => 'Loud', 'value' => 'loud']]]],
                    'related'          => ['label' => 'Related', 'config' => ['type' => 'group', 'allowed' => 'pages']],
                    'colour'           => ['label' => 'Colour', 'config' => ['type' => 'color']],
                    'archived_on'      => ['label' => 'Archived on', 'config' => ['type' => 'datetime', 'format' => 'date']],
                    // `exclude` written the pre-boolean way: core treats any
                    // truthy value as access-controlled.
                    'rank'             => ['label' => 'Rank', 'exclude' => 1, 'config' => ['type' => 'number']],
                    // Static items AND a foreign table: the value may be a uid.
                    'topic'            => ['label' => 'Topic', 'config' => ['type' => 'select', 'renderType' => 'selectSingle', 'items' => [['label' => 'None', 'value' => 0]], 'foreign_table' => 'tx_demo_topic']],
                    // What the DataHandler rewrites on the way in (ADR-197):
                    // evaluations, uniqueness, a colour cut to 7 characters,
                    // a value below `min` stored empty, a decimal clamped.
                    'code'             => ['label' => 'Code', 'config' => ['type' => 'input', 'eval' => 'trim,upper']],
                    'handle'           => ['label' => 'Handle', 'config' => ['type' => 'input', 'eval' => 'uniqueInPid']],
                    'alias'            => ['label' => 'Alias', 'config' => ['type' => 'text', 'eval' => 'trim,tx_demo_evaluation']],
                    'reply_to'         => ['label' => 'Reply to', 'config' => ['type' => 'email', 'eval' => 'unique']],
                    'shade'            => ['label' => 'Shade', 'config' => ['type' => 'color', 'opacity' => true]],
                    'summary'          => ['label' => 'Summary', 'config' => ['type' => 'input', 'min' => 5, 'eval' => 'trim']],
                    'lede'             => ['label' => 'Lede', 'config' => ['type' => 'text', 'min' => 8]],
                    'story'            => ['label' => 'Story', 'config' => ['type' => 'text', 'min' => 8, 'enableRichtext' => true]],
                    'rating'           => ['label' => 'Rating', 'config' => ['type' => 'number', 'format' => 'decimal', 'range' => ['lower' => 0.5, 'upper' => 4.5]]],
                    'section'          => ['label' => 'Section', 'config' => ['type' => 'select', 'renderType' => 'selectSingle', 'items' => [['label' => 'Group', 'value' => '--div--'], ['label' => 'A', 'value' => 'a']]]],
                    // An extension's evaluation on an input; the class is
                    // registered in setUp(), where the DataHandler looks it up.
                    'serial'           => ['label' => 'Serial', 'config' => ['type' => 'input', 'eval' => 'trim,tx_demo_evaluation']],
                    'byline'           => ['label' => 'Byline', 'config' => ['type' => 'input']],
                    // Tokens the DataHandler does not act on for a text: an
                    // unregistered class and an input-only token.
                    'motto'            => ['label' => 'Motto', 'config' => ['type' => 'text', 'eval' => 'trim,tx_unregistered_evaluation']],
                    'remark'           => ['label' => 'Remark', 'config' => ['type' => 'text', 'eval' => 'upper']],
                    // An email runs only the uniqueness tokens, never a registered class.
                    'cc'               => ['label' => 'Cc', 'config' => ['type' => 'email', 'eval' => 'tx_demo_evaluation']],
                    // Rendered read-only by FormEngine, stored by the DataHandler.
                    'locked'           => ['label' => 'Locked', 'config' => ['type' => 'input', 'readOnly' => true]],
                    'stamp'            => ['label' => 'Stamp', 'config' => ['type' => 'input']],
                    // `year`: 13.4's checkValue_input_Eval() casts the value to
                    // an integer, 14.3's no longer knows the token.
                    'founded'          => ['label' => 'Founded', 'config' => ['type' => 'input', 'eval' => 'trim,year']],
                ],
            ],
        ];
        $GLOBALS['LANG']    = self::createStub(LanguageService::class);
        $GLOBALS['BE_USER'] = $this->liveUser();

        // The page every call addresses has no TSconfig. getPagesTSconfig()
        // answers from the runtime cache before it builds a rootline, which
        // would need a database; the two keys are the same in TYPO3 13.4 and
        // 14.3 (BackendUtility::getPagesTSconfig()).
        $this->cacheManager = new CacheManager();
        $this->cacheManager->setCacheConfigurations([
            'runtime' => ['frontend' => VariableFrontend::class, 'backend' => TransientMemoryBackend::class, 'options' => [], 'groups' => []],
        ]);
        GeneralUtility::setSingletonInstance(CacheManager::class, $this->cacheManager);
        $runtimeCache = $this->cacheManager->getCache('runtime');
        $runtimeCache->set('pageTsConfig-pid-to-hash-7', 'nrllm-unit-page-7');
        $runtimeCache->set('pageTsConfig-hash-to-object-nrllm-unit-page-7', new PageTsConfig(new RootNode(), []));

        // Every call must stop before the write. One that passes every rule
        // meets this DataHandler and fails the test, rather than dying on the
        // container a unit test does not have.
        $dataHandler = self::createStub(DataHandler::class);
        $dataHandler->method('start')->willReturnCallback(
            static fn(): never => self::fail('The call reached the DataHandler, past every rule.'),
        );
        GeneralUtility::addInstance(DataHandler::class, $dataHandler);

        $this->tool = $this->toolWith(deniedTables: '');

        // An extension's evaluation class, registered where the DataHandler
        // looks it up (checkValue_input_Eval(), checkValue_text_Eval()).
        $confVars                   = is_array($GLOBALS['TYPO3_CONF_VARS'] ?? null) ? $GLOBALS['TYPO3_CONF_VARS'] : [];
        $confVars['SC_OPTIONS']     = ['tce' => ['formevals' => ['tx_demo_evaluation' => '']]];
        $GLOBALS['TYPO3_CONF_VARS'] = $confVars;
    }

    protected function tearDown(): void
    {
        GeneralUtility::removeSingletonInstance(CacheManager::class, $this->cacheManager);
        GeneralUtility::purgeInstances();

        foreach ($this->globalsBackup as $key => $value) {
            if ($value === null) {
                unset($GLOBALS[$key]);

                continue;
            }

            $GLOBALS[$key] = $value;
        }

        parent::tearDown();
    }

    #[Test]
    public function itDeclaresANonIdempotentWriteEffect(): void
    {
        // A creation with no caller-supplied key: two runs leave two records.
        self::assertInstanceOf(ToolEffectInterface::class, $this->tool);
        self::assertSame(ToolEffect::NON_IDEMPOTENT_WRITE, $this->tool->getEffect());
        self::assertTrue($this->tool->getEffect()->isWrite());
        self::assertFalse($this->tool->getEffect()->isSafeToRetry());
    }

    #[Test]
    public function itShipsDisabledAndIsNotAdminOnly(): void
    {
        self::assertFalse($this->tool->isEnabledByDefault(), 'a writing tool is never on by default');
        self::assertFalse($this->tool->requiresAdmin(), 'tables_modify and the page permission decide, not the admin flag');
        self::assertSame('editing', $this->tool->getGroup());
    }

    #[Test]
    public function itOffersAPreviewAndNoEditorAction(): void
    {
        self::assertInstanceOf(ToolPreviewInterface::class, $this->tool);
        // A declaration names the tables a writer OWNS (ADR-152); the fallback
        // owns none, so it is offered through the assistant only.
        self::assertNotInstanceOf(EditorActionInterface::class, $this->tool);
    }

    #[Test]
    public function theSpecTakesATableAPidAndFieldsAndNothingElse(): void
    {
        $spec = $this->tool->getSpec();

        self::assertSame('create_record_draft', $spec->name);
        self::assertSame(['table', 'pid', 'fields'], $spec->parameters['required'] ?? null);

        $properties = $spec->parameters['properties'] ?? null;
        self::assertIsArray($properties);
        self::assertSame(['table', 'pid', 'fields'], array_keys($properties));
        // Always hidden, always the default language; there is no argument for
        // either, and the description says so where a model reads it.
        self::assertStringContainsString('HIDDEN', $spec->description);
        self::assertStringContainsString('default language', $spec->description);
    }

    #[Test]
    public function itFailsClosedWithoutAnActingBackendUser(): void
    {
        $result = $this->tool->execute($this->call(['title' => 'x']), ToolExecutionContext::none());

        self::assertTrue($result->isError);
        self::assertSame('Page not found or not permitted.', $result->content);
    }

    #[Test]
    public function itRefusesOutsideTheLiveWorkspace(): void
    {
        $draftUser            = $this->liveUser();
        $draftUser->workspace = 1;

        $result = $this->tool->execute($this->call(['title' => 'x']), $this->contextFor($draftUser));

        self::assertTrue($result->isError);
        self::assertStringContainsString('live workspace', $result->content);
    }

    #[Test]
    public function itRefusesAndNamesEachMissingPieceOfTheBackendEnvironment(): void
    {
        unset($GLOBALS['TCA'], $GLOBALS['LANG'], $GLOBALS['BE_USER']);

        $result = $this->tool->execute($this->call(['title' => 'x']), $this->contextFor($this->liveUser()));

        self::assertTrue($result->isError);
        self::assertStringContainsString('TCA', $result->content);
        self::assertStringContainsString('language service', $result->content);
        self::assertStringContainsString('backend user', $result->content);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusedArguments(): iterable
    {
        $call  = static fn(array $fields, string $table = self::TABLE, int $pid = 7): array => ['table' => $table, 'pid' => $pid, 'fields' => $fields];
        $valid = ['title' => 'x'];

        yield 'unknown argument'       => [$call($valid) + ['language' => 1], 'not an argument of this tool'];
        yield 'no table'               => [['pid' => 7, 'fields' => $valid], '"table" must be the name'];
        yield 'table not a string'     => [['table' => 7, 'pid' => 7, 'fields' => $valid], '"table" must be the name'];
        yield 'table with odd chars'   => [['table' => 'tx_x; drop', 'pid' => 7, 'fields' => $valid], '"table" must be the name'];
        yield 'table not in TCA'       => [$call($valid, table: 'tx_nowhere'), 'not a table this installation declares'];
        yield 'pages'                  => [$call($valid, table: 'pages'), 'create_page_draft'];
        yield 'tt_content'             => [$call($valid, table: 'tt_content'), 'create_content_element_draft'];
        yield 'a sys_ table'           => [$call($valid, table: 'sys_demo'), 'system or sensitive table'];
        yield 'a sensitive table'      => [$call($valid, table: 'be_users'), 'system or sensitive table'];
        yield 'adminOnly table'        => [$call($valid, table: 'tx_demo_admin_only'), 'adminOnly'];
        yield 'hideTable table'        => [$call($valid, table: 'tx_demo_hidden'), 'hideTable'];
        yield 'readOnly table'         => [$call($valid, table: 'tx_demo_read_only'), 'readOnly'];
        yield 'no disabled column'     => [$call($valid, table: 'tx_demo_no_hidden'), 'no "disabled" enable column'];
        yield 'type in a related record' => [$call($valid, table: 'tx_demo_foreign_type'), 'depends on a related record'];
        yield 'zero pid'               => [$call($valid, pid: 0), 'positive uid of exactly one page'];
        yield 'negative pid'           => [$call($valid, pid: -3), 'positive uid of exactly one page'];
        yield 'no fields'              => [['table' => self::TABLE, 'pid' => 7], '"fields" must be an object'];
        yield 'empty fields'           => [$call([]), '"fields" must be an object'];
        yield 'fields not an object'   => [['table' => self::TABLE, 'pid' => 7, 'fields' => 'title=x'], '"fields" must be an object'];
        yield 'hidden smuggled in'     => [$call($valid + ['hidden' => 0]), 'never arguments'];
        yield 'pid smuggled in'        => [$call($valid + ['pid' => 9]), 'never arguments'];
        yield 'uid smuggled in'        => [$call($valid + ['uid' => 9]), 'never arguments'];
        yield 'language column'        => [$call($valid + ['sys_language_uid' => 1]), 'never arguments'];
        yield 'translation parent'     => [$call($valid + ['l10n_parent' => 1]), 'never arguments'];
        yield 'starttime'              => [$call($valid + ['starttime' => 1]), 'never arguments'];
        yield 'fe_group'               => [$call($valid + ['fe_group' => '-2']), 'never arguments'];
        yield 'editlock'               => [$call($valid + ['editlock' => 1]), 'never arguments'];
        yield 'a perms_ column'        => [$call($valid + ['perms_userid' => 1]), 'never arguments'];
        yield 'a t3ver_ column'        => [$call($valid + ['t3ver_state' => 1]), 'never arguments'];
        yield 'deleted'                => [$call($valid + ['deleted' => 0]), 'never arguments'];
        yield 'tstamp'                 => [$call($valid + ['tstamp' => 1]), 'never arguments'];
        yield 'unknown column'         => [$call($valid + ['subtitle' => 'x']), 'not a column of ' . self::TABLE];
        yield 'a column name with odd chars' => [$call($valid + ['title;x' => 'x']), 'not a valid identifier'];
        yield 'relation column'        => [$call($valid + ['related' => '1']), 'only scalar columns'];
        yield 'date-only datetime'     => [$call($valid + ['archived_on' => 1]), 'only scalar columns'];
        yield 'select with a foreign table' => [$call($valid + ['topic' => 0]), 'only scalar columns'];
        yield 'a divider as a value'   => [$call($valid + ['section' => '--div--']), 'must be one of: "a".'];
        yield 'not in the showitem'    => [$call($valid + ['kind' => 'story', 'teaser' => 'x']), 'not shown for record type "story"'];
        yield 'select outside items'   => [$call($valid + ['kind' => 'novel']), 'must be one of'];
        yield 'radio outside items'    => [$call($valid + ['tone' => 'shrill']), 'must be one of'];
        yield 'select value not scalar' => [$call($valid + ['kind' => ['note']]), 'must be one of'];
        yield 'number below range'     => [$call($valid + ['priority' => 0]), 'outside the range 1..5'];
        yield 'number above range'     => [$call($valid + ['priority' => 6]), 'outside the range 1..5'];
        yield 'number not a number'    => [$call($valid + ['priority' => 'high']), 'must be an integer'];
        yield 'check not 0 or 1'       => [$call($valid + ['featured' => 2]), 'must be 0 or 1'];
        yield 'check a string'         => [$call($valid + ['featured' => 'yes']), 'must be 0 or 1'];
        yield 'invalid email'          => [$call($valid + ['contact' => 'nobody']), 'not a valid e-mail address'];
        yield 'invalid colour'         => [$call($valid + ['colour' => 'teal']), 'hexadecimal colour'];
        yield 'unparseable datetime'   => [$call($valid + ['published_at' => 'soonish']), 'UNIX timestamp or an ISO 8601'];
        yield 'nonexistent date'       => [$call($valid + ['published_at' => '2026-02-30T10:00:00+00:00']), 'UNIX timestamp or an ISO 8601'];
        yield 'nonexistent time'       => [$call($valid + ['published_at' => '2026-02-10T25:00:00+00:00']), 'UNIX timestamp or an ISO 8601'];
        yield 'ISO end-of-day midnight' => [$call($valid + ['published_at' => '2026-02-28T24:00:00+00:00']), 'write midnight at the end of a day as 00:00 of the next day'];
        yield 'negative timestamp'     => [$call($valid + ['published_at' => -5]), 'UNIX timestamp or an ISO 8601'];
        yield 'title not a string'     => [$call(['title' => ['x']]), 'must be a string'];
        yield 'title over max'         => [$call(['title' => str_repeat('a', 101)]), 'exceeds 100 characters'];
        yield 'teaser over the bound'  => [$call($valid + ['teaser' => str_repeat('a', 20001)]), 'exceeds 20000 characters'];
        yield 'an input eval that rewrites' => [$call($valid + ['code' => 'abc']), 'eval "upper"'];
        yield 'an input eval that uniquifies' => [$call($valid + ['handle' => 'abc']), 'eval "uniqueInPid"'];
        yield 'a text eval of an extension' => [$call($valid + ['alias' => 'abc']), 'eval "tx_demo_evaluation"'];
        yield 'an email eval that uniquifies' => [$call($valid + ['reply_to' => 'a@example.com']), 'eval "unique"'];
        yield 'an input eval of an extension' => [$call($valid + ['serial' => 'abc']), 'eval "tx_demo_evaluation"'];
        yield 'a read-only column'     => [$call($valid + ['locked' => 'x']), '"locked" is read-only'];
        yield 'read-only for the record type' => [$call($valid + ['stamp' => 'x']), '"stamp" is read-only'];
        yield 'an 8-digit colour without opacity' => [$call($valid + ['colour' => '#2f99a4cc']), 'hexadecimal colour such as #2f99a4'];
        yield 'an input below min'     => [$call($valid + ['summary' => 'abcd']), 'at least 5 characters'];
        yield 'a text below min'       => [$call($valid + ['lede' => 'short']), 'at least 8 characters'];
        yield 'a decimal TYPO3 clamps up' => [$call($valid + ['rating' => 4.2]), 'outside the range 0.5..4.5'];
        yield 'a decimal TYPO3 clamps down' => [$call($valid + ['rating' => 0.8]), 'outside the range 0.5..4.5'];
        yield 'a decimal TYPO3 rounds down' => [$call($valid + ['rating' => 1.234]), 'more than two decimal places'];
        yield 'a decimal TYPO3 rounds up' => [$call($valid + ['rating' => 0.125]), 'more than two decimal places'];
        yield 'a decimal string with three places' => [$call($valid + ['rating' => '2.675']), 'more than two decimal places'];
        // Where a tolerance relative to the value would already reach a half hundredth.
        yield 'a large decimal with three places' => [$call($valid + ['rating' => 6000000.005]), 'more than two decimal places'];
        yield 'required missing'       => [$call(['teaser' => 'x']), '"title" is required'];
        yield 'required empty'         => [$call(['title' => '  ']), '"title" is required'];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('refusedArguments')]
    public function itRefusesInvalidArguments(array $arguments, string $expectedFragment): void
    {
        $result = $this->tool->execute($arguments, $this->contextFor($this->liveUser()));

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString($expectedFragment, $result->content);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('refusedArguments')]
    public function thePreviewRefusesTheSameArguments(array $arguments, string $expectedFragment): void
    {
        $lines = $this->tool->previewCall($arguments, $this->contextFor($this->liveUser()));

        self::assertCount(1, $lines);
        self::assertStringContainsString($expectedFragment, $lines[0]);
    }

    /**
     * The other direction of each rule: what TYPO3 stores unchanged passes the
     * rule and is stopped only by the invalid `kind` behind it.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function valuesTypo3StoresUnchanged(): iterable
    {
        yield 'an 8-digit colour with opacity'  => [['shade' => '#2f99a4cc']];
        yield 'an input at min'                 => [['summary' => 'abcde']];
        yield 'an empty input below min'        => [['summary' => '']];
        yield 'rich text below min'             => [['story' => 'Hi']];
        yield 'a decimal that stays in range'   => [['rating' => 4.0]];
        yield 'a decimal at the lower bound'    => [['rating' => 1]];
        yield 'a decimal with two places'       => [['rating' => 2.25]];
        yield 'a decimal string with a trailing zero' => [['rating' => '2.50']];
        // 1.1 + 2.2: two places, off by one unit in the last binary digit.
        yield 'a two-place decimal with binary noise' => [['rating' => 3.3000000000000003]];
        yield 'a legacy required in an override eval' => [['byline' => 'By the desk']];
        yield 'an unregistered eval on a text'  => [['motto' => 'Onwards']];
        yield 'an input-only eval on a text'    => [['remark' => 'As written']];
        yield 'a registered eval on an email'   => [['cc' => 'desk@example.com']];
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[Test]
    #[DataProvider('valuesTypo3StoresUnchanged')]
    public function aValueTypo3StoresUnchangedPassesTheRule(array $fields): void
    {
        $result = $this->tool->execute($this->call(['title' => 'x'] + $fields + ['kind' => 'novel']), $this->contextFor($this->liveUser()));

        self::assertTrue($result->isError);
        self::assertStringContainsString('must be one of', $result->content);
    }

    /**
     * The midnight hint belongs to an hour of 24, not to any "24:00" in the
     * text: a nonexistent date at 12:24 is refused without it.
     */
    #[Test]
    public function aNonexistentDateAtTwentyFourMinutesPastIsRefusedWithoutTheMidnightHint(): void
    {
        $result = $this->tool->execute(
            $this->call(['title' => 'x', 'published_at' => '2026-02-30T12:24:00+00:00']),
            $this->contextFor($this->liveUser()),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('UNIX timestamp or an ISO 8601', $result->content);
        self::assertStringNotContainsString('00:00 of the next day', $result->content);
    }

    /**
     * `year` is refused where the running core rewrites it on the way in —
     * 13.4 casts the value to an integer — and passes the rule on 14, whose
     * DataHandler ignores the token; there the call is stopped only by the
     * invalid `kind` behind it.
     *
     * @return iterable<string, array{int, string}>
     */
    public static function yearEvaluationsByCoreVersion(): iterable
    {
        yield 'TYPO3 13 acts on it' => [13, 'eval "year"'];
        yield 'TYPO3 14 ignores it' => [14, 'must be one of'];
    }

    #[Test]
    #[DataProvider('yearEvaluationsByCoreVersion')]
    public function aYearEvaluationIsRefusedOnlyWhereTheCoreActsOnIt(int $majorVersion, string $expectedFragment): void
    {
        $version = self::createStub(Typo3Version::class);
        $version->method('getMajorVersion')->willReturn($majorVersion);

        $result = $this->toolWith(deniedTables: '', typo3Version: $version)->execute(
            $this->call(['title' => 'x', 'founded' => '1999', 'kind' => 'novel']),
            $this->contextFor($this->liveUser()),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString($expectedFragment, $result->content);
    }

    #[Test]
    public function aTableNameIsEchoedBackOnlyWhenItIsAnIdentifier(): void
    {
        $result = $this->tool->execute(
            ['table' => "tx_x\n<script>", 'pid' => 7, 'fields' => ['title' => 'x']],
            $this->contextFor($this->liveUser()),
        );

        self::assertTrue($result->isError);
        self::assertStringNotContainsString('<', $result->content);
        self::assertStringNotContainsString('tx_x', $result->content);
    }

    #[Test]
    public function aColumnMarkedExcludeWithATruthyValueNeedsTheFieldGrant(): void
    {
        // The page check of a non-admin walks the rootline, which needs a
        // database; the grant this test is about is asked after it.
        $editor = $this->getMockBuilder(BackendUserAuthentication::class)->onlyMethods(['doesUserHaveAccess'])->getMock();
        $editor->expects(self::once())->method('doesUserHaveAccess')->willReturn(true);
        $editor->workspace                       = 0;
        $editor->user                            = ['uid' => 5, 'admin' => 0];
        $editor->groupData['allowed_languages']  = '0';
        $editor->groupData['tables_modify']      = self::TABLE;
        $editor->groupData['non_exclude_fields'] = self::TABLE . ':hidden';

        $result = $this->tool->execute($this->call(['title' => 'x', 'rank' => 3]), $this->contextFor($editor));

        self::assertTrue($result->isError);
        self::assertStringContainsString('exclude field', $result->content);
        self::assertStringContainsString(self::TABLE . ':rank', $result->content);
    }

    /**
     * Present but unreadable refuses every table; only an ABSENT setting
     * means "no exclusions".
     *
     * @return iterable<string, array{mixed}>
     */
    public static function unreadableDenyLists(): iterable
    {
        yield 'deniedTables is a list'       => [['tools' => ['createRecordDraft' => ['deniedTables' => [self::TABLE]]]]];
        yield 'deniedTables is a number'     => [['tools' => ['createRecordDraft' => ['deniedTables' => 7]]]];
        yield 'createRecordDraft is a string' => [['tools' => ['createRecordDraft' => 'deniedTables=' . self::TABLE]]];
        yield 'tools is a string'            => [['tools' => 'createRecordDraft']];
        yield 'the configuration is a string' => ['tools.createRecordDraft.deniedTables=' . self::TABLE];
    }

    #[Test]
    #[DataProvider('unreadableDenyLists')]
    public function aDenyListThatIsPresentButNotAStringRefusesEveryTable(mixed $configuration): void
    {
        $result = $this->toolReading($configuration)->execute($this->call(['title' => 'x']), $this->contextFor($this->liveUser()));

        self::assertTrue($result->isError);
        self::assertStringContainsString('could not be read', $result->content);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function absentDenyLists(): iterable
    {
        yield 'no tools key'            => [[]];
        yield 'no createRecordDraft key' => [['tools' => []]];
        yield 'no deniedTables key'     => [['tools' => ['createRecordDraft' => []]]];
    }

    #[Test]
    #[DataProvider('absentDenyLists')]
    public function anAbsentDenyListExcludesNothing(mixed $configuration): void
    {
        // Stopped by a column rule, past every table rule.
        $result = $this->toolReading($configuration)->execute($this->call(['title' => 'x', 'kind' => 'novel']), $this->contextFor($this->liveUser()));

        self::assertTrue($result->isError);
        self::assertStringContainsString('must be one of', $result->content);
    }

    #[Test]
    public function itRefusesATableTheExtensionConfigurationDenies(): void
    {
        $denied = $this->toolWith(deniedTables: ' tx_other , ' . self::TABLE . ' ');

        $result = $denied->execute($this->call(['title' => 'x']), $this->contextFor($this->liveUser()));

        self::assertTrue($result->isError);
        self::assertStringContainsString('tools.createRecordDraft.deniedTables', $result->content);
    }

    #[Test]
    public function aDenyListNamingOtherTablesDoesNotRefuseThisOne(): void
    {
        $tool = $this->toolWith(deniedTables: 'tx_other, tx_another');

        // The call proceeds past the table rules and is stopped by a column
        // rule instead — the configuration did not refuse it.
        $result = $tool->execute($this->call(['title' => 'x', 'kind' => 'novel']), $this->contextFor($this->liveUser()));

        self::assertTrue($result->isError);
        self::assertStringNotContainsString('deniedTables', $result->content);
        self::assertStringContainsString('must be one of', $result->content);
    }

    #[Test]
    public function itRefusesEveryTableWhenTheConfigurationCannotBeRead(): void
    {
        // The early-setup failure ExtensionConfiguration::get() raises when no
        // configuration exists yet, provoked the way the governance resolver's
        // test provokes it.
        $throwing = self::createStub(ExtensionConfiguration::class);
        $throwing->method('get')->willThrowException(new RuntimeException('config unreadable', 1758500000));

        $tool = new CreateRecordDraftTool(
            self::createStub(ConnectionPool::class),
            new TableReadAccessService(),
            [],
            $throwing,
        );

        $result = $tool->execute($this->call(['title' => 'x']), $this->contextFor($this->liveUser()));

        self::assertTrue($result->isError);
        self::assertStringContainsString('could not be read', $result->content);
    }

    #[Test]
    public function itRefusesATableAnotherRegisteredCreatorDeclaresAndNamesIt(): void
    {
        $tool = $this->toolWith(deniedTables: '', writers: [
            new FakeRecordCreatorTool('other_creator', ['tx_other']),
            new FakeRecordCreatorTool('demo_item_creator', ['tx_other', self::TABLE]),
        ]);

        $result = $tool->execute($this->call(['title' => 'x']), $this->contextFor($this->liveUser()));

        self::assertTrue($result->isError);
        self::assertStringContainsString('demo_item_creator', $result->content);
        self::assertStringNotContainsString('other_creator', $result->content);
    }

    #[Test]
    public function aCreatorDeclaringOtherTablesDoesNotStandInTheWay(): void
    {
        $tool = $this->toolWith(deniedTables: '', writers: [new FakeRecordCreatorTool('other_creator', ['tx_other'])]);

        $result = $tool->execute($this->call(['title' => 'x', 'kind' => 'novel']), $this->contextFor($this->liveUser()));

        self::assertTrue($result->isError);
        self::assertStringNotContainsString('other_creator', $result->content);
        self::assertStringContainsString('must be one of', $result->content);
    }

    /**
     * An editor action names the SUBJECT its arguments identify (ADR-152), not
     * the table it writes: a writer that updates the table, or creates in
     * another one from a record of it, must not take the fallback away.
     */
    #[Test]
    public function aWriterNamingTheTableOnlyAsItsEditorActionSubjectDoesNotBlock(): void
    {
        $tool = $this->toolWith(deniedTables: '', writers: [
            new FakeEditorActionTool('demo_item_updater', 'editing', new EditorAction('LLL:fake.label', 'LLL:fake.description', 'fake-icon', [self::TABLE])),
        ]);

        $result = $tool->execute($this->call(['title' => 'x', 'kind' => 'novel']), $this->contextFor($this->liveUser()));

        self::assertTrue($result->isError);
        self::assertStringNotContainsString('demo_item_updater', $result->content);
        self::assertStringContainsString('must be one of', $result->content);
    }

    /**
     * A declaration that cannot be read may be the one covering the table, so
     * it refuses rather than being skipped — and the refusal names the tool
     * even when its spec cannot be read either, without throwing out of the
     * call or the preview.
     *
     * @return iterable<string, array{FakeRecordCreatorTool, string}>
     */
    public static function unreadableCreators(): iterable
    {
        yield 'the declaration throws'              => [new FakeRecordCreatorTool('broken_creator', null), 'broken_creator'];
        yield 'the declaration and the spec throw' => [new FakeRecordCreatorTool('broken_creator', null, true), FakeRecordCreatorTool::class];
    }

    #[Test]
    #[DataProvider('unreadableCreators')]
    public function aCreatorWhoseDeclarationCannotBeReadRefusesTheCall(FakeRecordCreatorTool $creator, string $named): void
    {
        $tool = $this->toolWith(deniedTables: '', writers: [$creator]);

        $result = $tool->execute($this->call(['title' => 'x']), $this->contextFor($this->liveUser()));

        self::assertTrue($result->isError);
        self::assertStringContainsString('could not be read', $result->content);
        self::assertStringContainsString($named, $result->content);

        $lines = $tool->previewCall($this->call(['title' => 'x']), $this->contextFor($this->liveUser()));
        self::assertCount(1, $lines);
        self::assertStringContainsString($named, $lines[0]);
    }

    #[Test]
    public function itRefusesAUserWhoMayNotEditTheDefaultLanguage(): void
    {
        $restricted                                 = $this->liveUser();
        $restricted->user                           = ['uid' => 5, 'admin' => 0];
        $restricted->groupData['allowed_languages'] = '2';
        $restricted->groupData['tables_modify']     = self::TABLE;

        $result = $this->tool->execute($this->call(['title' => 'x']), $this->contextFor($restricted));

        self::assertTrue($result->isError);
        self::assertStringContainsString('default language', $result->content);
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function call(array $fields): array
    {
        return ['table' => self::TABLE, 'pid' => 7, 'fields' => $fields];
    }

    /**
     * @param list<ToolInterface> $writers
     */
    private function toolWith(string $deniedTables, array $writers = [], ?Typo3Version $typo3Version = null): CreateRecordDraftTool
    {
        // Where ExtensionConfiguration::get() reads it; the global is untyped,
        // so it is narrowed step by step.
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        if (!is_array($confVars)) {
            $confVars = [];
        }

        $extensions = $confVars['EXTENSIONS'] ?? [];
        if (!is_array($extensions)) {
            $extensions = [];
        }

        $extensions['nr_llm']       = ['tools' => ['createRecordDraft' => ['deniedTables' => $deniedTables]]];
        $confVars['EXTENSIONS']     = $extensions;
        $GLOBALS['TYPO3_CONF_VARS'] = $confVars;

        return new CreateRecordDraftTool(
            $this->connectionPoolWithThePage(),
            new TableReadAccessService(),
            $writers,
            new ExtensionConfiguration(),
            null,
            $typo3Version,
        );
    }

    private function toolReading(mixed $configuration): CreateRecordDraftTool
    {
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($configuration);

        return new CreateRecordDraftTool(
            $this->connectionPoolWithThePage(),
            new TableReadAccessService(),
            [],
            $extensionConfiguration,
        );
    }

    /**
     * A connection pool that answers one query: the page every call addresses.
     *
     * Every call in this class must stop before the write, so any other table
     * fails the test — a rule that lets a value through would otherwise die on
     * an unconfigured stub, a PHP error rather than the failed expectation it
     * is.
     */
    private function connectionPoolWithThePage(): ConnectionPool
    {
        $result = self::createStub(Result::class);
        $result->method('fetchAssociative')->willReturn(['uid' => 7, 'pid' => 0, 'title' => 'Demo folder', 'deleted' => 0]);

        $restrictions = self::createStub(QueryRestrictionContainerInterface::class);
        $restrictions->method('removeAll')->willReturnSelf();
        $restrictions->method('add')->willReturnSelf();

        $queryBuilder = self::createStub(QueryBuilder::class);
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn(self::createStub(ExpressionBuilder::class));
        $queryBuilder->method('createNamedParameter')->willReturn(':uid');
        $queryBuilder->method('executeQuery')->willReturn($result);

        $connectionPool = self::createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturnCallback(
            static function (string $table) use ($queryBuilder): QueryBuilder {
                if ($table !== 'pages') {
                    self::fail(sprintf('The call reached the database for %s, past every rule.', $table));
                }

                // The DeletedRestriction the page query adds asks the container
                // for the TcaSchemaFactory; one stub for exactly that query.
                GeneralUtility::addInstance(TcaSchemaFactory::class, self::createStub(TcaSchemaFactory::class));

                return $queryBuilder;
            },
        );

        return $connectionPool;
    }

    private function liveUser(): BackendUserAuthentication
    {
        $user            = new BackendUserAuthentication();
        $user->user      = ['uid' => 1, 'admin' => 1];
        $user->workspace = 0;

        return $user;
    }

    private function contextFor(BackendUserAuthentication $user): ToolExecutionContext
    {
        return ToolExecutionContext::fromBackendUser($user);
    }
}
