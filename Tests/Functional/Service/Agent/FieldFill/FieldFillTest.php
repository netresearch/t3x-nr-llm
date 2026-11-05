<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\FieldFill;

use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Enum\FieldFillOutcome;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\FieldFillTarget;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRuntime;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Agent\FieldFill\FieldFillHook;
use Netresearch\NrLlm\Service\Agent\FieldFill\FieldFillPlanner;
use Netresearch\NrLlm\Service\Agent\FieldFill\FieldFillSettings;
use Netresearch\NrLlm\Service\Agent\FieldFill\FieldFillWriter;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolver;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolverInterface;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\AgentRunRepository;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Service\Tool\Builtin\ToolDataHandler;
use Netresearch\NrLlm\Service\Tool\RecordTextReader;
use Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Stringable;
use Symfony\Component\Messenger\MessageBusInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The fill on save, from a real DataHandler save to the field (ADR-237). The hook
 * is the one ext_localconf.php registers; only the model is scripted.
 */
#[CoversClass(FieldFillHook::class)]
#[CoversClass(FieldFillPlanner::class)]
#[CoversClass(FieldFillWriter::class)]
final class FieldFillTest extends AbstractFunctionalTestCase
{
    private const PAGE = 1;

    private const PAGE_TEXT = 'A page about the repair of old bicycles, their frames, their gears and the people who still do it by hand.';

    private ConnectionPool $pool;

    private AgentRunRepository $repository;

    private BackendUserAuthentication $admin;

    /** @var array<string, mixed> */
    private array $extensionSettings = [];

    private ?EventDispatcherInterface $dispatcher = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $this->importFixture('Providers.csv');
        $this->importFixture('Models.csv');
        $this->importFixture('LlmConfigurations.csv');

        $this->pool       = $this->getService(ConnectionPool::class);
        $this->repository = new AgentRunRepository($this->pool, $this->getService(AgentStateCodec::class));

        $this->admin     = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');

        // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
        $configuration = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm'] ?? [];
        self::assertIsArray($configuration);
        /** @var array<string, mixed> $configuration */
        $this->extensionSettings = $configuration;
        $this->optIn('pages.description');

        $this->pool->getConnectionForTable('pages')->insert('pages', [
            'uid' => self::PAGE, 'pid' => 0, 'title' => 'Bicycles', 'doktype' => 1, 'perms_userid' => 1, 'perms_everybody' => 31,
        ]);
        $this->pool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 1, 'pid' => self::PAGE, 'CType' => 'text', 'header' => 'Repair', 'bodytext' => '<p>' . self::PAGE_TEXT . '</p>',
        ]);
    }

    protected function tearDown(): void
    {
        // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm'] = $this->extensionSettings;
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function aFailingListenerDoesNotFailTheWrite(): void
    {
        $dispatcher = self::createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willThrowException(new RuntimeException('listener broke'));
        $this->dispatcher = $dispatcher;

        $this->save(['title' => 'Bicycles and more']);
        $this->work('Old bicycles, repaired by hand.');

        self::assertSame(FieldFillOutcome::WRITTEN->value, $this->lastRun()->outcome);
    }

    #[Test]
    public function aSaveQueuesTheFieldAndTheWorkerWritesIt(): void
    {
        $this->save(['title' => 'Bicycles and more']);

        // The save is over, the run waits for the worker, and no model was asked.
        self::assertSame(['fill:pages:1:description'], $this->queuedOrigins());
        self::assertSame('', $this->valueOf('description'));

        $seen = [];
        $this->work('Old bicycles, repaired by hand.', $seen);

        self::assertSame('Old bicycles, repaired by hand.', $this->valueOf('description'));
        self::assertSame(FieldFillOutcome::WRITTEN->value, $this->lastRun()->outcome);
        self::assertSame(1, (int) $this->pool->getConnectionForTable('pages')->select(['tx_nrllm_ai_written'], 'pages', ['uid' => self::PAGE])->fetchOne(), 'The filled record is marked as AI written.');
        self::assertSame(AgentRunStatus::COMPLETED, $this->lastRun()->statusEnum());

        // The model read the page, and not as HTML.
        self::assertStringContainsString(self::PAGE_TEXT, $seen[0]);
        self::assertStringNotContainsString('<p>', $seen[0]);
    }

    #[Test]
    public function aFillRunHasNoTools(): void
    {
        $runtime = $this->createMock(AgentRuntimeInterface::class);
        $runtime->expects(self::once())->method('defer')->with(self::callback(
            static fn (AgentRunRequest $request): bool => $request->allowedToolNames === [],
        ))->willReturn('uuid');

        self::assertSame(1, $this->planner($runtime)->afterSave('update', 'pages', self::PAGE, ['title' => 'x'], $this->admin));
    }

    #[Test]
    public function theFillIsNotASaveOfAnEditor(): void
    {
        $this->save(['title' => 'Bicycles and more']);

        // A second empty field, and only the worker's write to set it off.
        $this->optIn('pages.description,pages.abstract');
        $this->work('A text.');

        self::assertSame('A text.', $this->valueOf('description'));
        self::assertSame([], $this->queuedOrigins());
    }

    #[Test]
    public function clearingTheAiMarkQueuesNothing(): void
    {
        $this->pool->getConnectionForTable('pages')->update('pages', ['tx_nrllm_ai_written' => 1], ['uid' => self::PAGE]);

        $this->save(['tx_nrllm_ai_written' => 0]);

        self::assertSame([], $this->queuedOrigins());
    }

    #[Test]
    public function aSaveOfAnOtherFieldDoesNotQueueAFieldThatHoldsAValue(): void
    {
        $this->pool->getConnectionForTable('pages')->update('pages', ['description' => 'Written by a person'], ['uid' => self::PAGE]);

        $this->save(['title' => 'Bicycles and more']);

        self::assertSame([], $this->queuedOrigins());
    }

    #[Test]
    public function anEditorWhoEmptiesAFieldKeepsItEmpty(): void
    {
        $this->pool->getConnectionForTable('pages')->update('pages', ['description' => 'Written by a person'], ['uid' => self::PAGE]);

        $this->save(['description' => '']);
        self::assertSame([], $this->queuedOrigins());

        // A later save that leaves the field alone is a save like any other.
        $this->save(['title' => 'Bicycles again']);
        self::assertSame(['fill:pages:1:description'], $this->queuedOrigins());
    }

    #[Test]
    public function nothingIsQueuedWithoutTheOptIn(): void
    {
        $this->optIn('');

        $this->save(['title' => 'Bicycles and more']);

        self::assertSame([], $this->queuedOrigins());
    }

    #[Test]
    public function aFieldThatIsNotPlainTextIsNotQueuedWhateverTheListSays(): void
    {
        $this->optIn('pages.slug');

        $this->save(['title' => 'Bicycles and more']);

        self::assertSame([], $this->queuedOrigins());
    }

    #[Test]
    public function aFieldWithAnOpenRunIsNotQueuedTwice(): void
    {
        $this->save(['title' => 'One']);
        $this->save(['title' => 'Two']);

        self::assertCount(1, $this->queuedOrigins());
    }

    #[Test]
    public function aNewRecordIsQueuedUnderItsRealUid(): void
    {
        $this->pool->getConnectionForTable('tt_content')->update('tt_content', ['pid' => 2], ['uid' => 1]);

        $handler = GeneralUtility::makeInstance(DataHandler::class);
        $handler->start(['pages' => ['NEW1' => ['pid' => self::PAGE, 'title' => 'New', 'doktype' => 1, 'abstract' => str_repeat('A long abstract. ', 8)]]], [], $this->admin);
        $handler->process_datamap();

        $origins = $this->queuedOrigins();
        self::assertCount(1, $origins);
        self::assertMatchesRegularExpression('/^fill:pages:[1-9][0-9]*:description$/', $origins[0]);
    }

    #[Test]
    public function aPageWithHardlyAnyTextIsNotQueued(): void
    {
        $this->pool->getConnectionForTable('tt_content')->delete('tt_content', ['uid' => 1]);

        $this->save(['title' => 'Bicycles and more']);

        self::assertSame([], $this->queuedOrigins());
    }

    #[Test]
    public function aCopyMadeByCoreIsNotQueued(): void
    {
        $this->pool->getConnectionForTable('pages')->insert('pages', ['uid' => 2, 'pid' => 0, 'title' => 'Target', 'doktype' => 1, 'perms_userid' => 1, 'perms_user' => 31]);
        // The page alone has text enough, because its content is copied after it.
        $this->pool->getConnectionForTable('pages')->update('pages', ['abstract' => self::PAGE_TEXT], ['uid' => self::PAGE]);

        $handler = GeneralUtility::makeInstance(DataHandler::class);
        $handler->start([], ['pages' => [self::PAGE => ['copy' => 2]]], $this->admin);
        $handler->process_cmdmap();

        self::assertSame([], $handler->errorLog);
        self::assertSame([], $this->queuedOrigins());
        // The copy is there, hidden, so a count through the restrictions misses it.
        self::assertSame(3, (int) $this->pool->getConnectionForTable('pages')->executeQuery('SELECT COUNT(*) FROM pages')->fetchOne());
    }

    #[Test]
    public function aScheduledSaveAsCliUserQueuesNothing(): void
    {
        $this->pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 99, 'username' => '_cli_', 'admin' => 1]);

        $this->save(['title' => 'Imported'], $this->setUpBackendUser(99));

        self::assertSame([], $this->queuedOrigins());
    }

    #[Test]
    public function anImportQueuesNothing(): void
    {
        $handler              = GeneralUtility::makeInstance(DataHandler::class);
        $handler->isImporting = true;
        $handler->start(['pages' => [self::PAGE => ['title' => 'Imported']]], [], $this->admin);
        $handler->process_datamap();

        self::assertSame([], $this->queuedOrigins());
    }

    #[Test]
    public function aToolWriteQueuesNothing(): void
    {
        $handler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $handler->start(['pages' => [self::PAGE => ['title' => 'Written by a tool']]], [], $this->admin);
        $handler->process_datamap();

        self::assertSame([], $this->queuedOrigins());
    }

    #[Test]
    public function aUserWhoMayNotUseTheConfigurationQueuesNothing(): void
    {
        $this->pool->getConnectionForTable('tx_nrllm_configuration')->update('tx_nrllm_configuration', ['allowed_groups' => 1], ['uid' => 1]);
        $this->pool->getConnectionForTable('be_groups')->insert('be_groups', ['uid' => 77, 'title' => 'Allowed']);
        $this->pool->getConnectionForTable('tx_nrllm_configuration_begroups_mm')->insert('tx_nrllm_configuration_begroups_mm', ['uid_local' => 1, 'uid_foreign' => 77]);
        $this->admin->user['admin']                   = 0;
        $this->admin->groupData['non_exclude_fields'] = 'pages:description';

        $runtime = $this->createMock(AgentRuntimeInterface::class);
        $runtime->expects(self::never())->method('defer');

        self::assertSame(0, $this->planner($runtime)->afterSave('update', 'pages', self::PAGE, ['title' => 'x'], $this->admin));
    }

    #[Test]
    public function aUserWhoMayNotEditTheFieldQueuesNothing(): void
    {
        $this->admin->user['admin'] = 0;

        self::assertSame(0, $this->planner()->afterSave('update', 'pages', self::PAGE, ['title' => 'x'], $this->admin));

        $this->admin->groupData['non_exclude_fields'] = 'pages:description';

        self::assertSame(1, $this->planner()->afterSave('update', 'pages', self::PAGE, ['title' => 'x'], $this->admin));
    }

    #[Test]
    public function aFailureToQueueDoesNotFailTheSave(): void
    {
        $runtime = self::createStub(AgentRuntimeInterface::class);
        $runtime->method('defer')->willThrowException(new RuntimeException('database gone', 1798000101));
        GeneralUtility::addInstance(FieldFillPlanner::class, $this->planner($runtime));

        $hook    = new FieldFillHook($this->settings(), new NullLogger());
        $handler = GeneralUtility::makeInstance(DataHandler::class);
        $handler->start([], [], $this->admin);

        $hook->processDatamap_afterDatabaseOperations('update', 'pages', self::PAGE, ['title' => 'x'], $handler);

        self::assertSame([], $this->queuedOrigins());
    }

    #[Test]
    public function oneSaveQueuesABoundedNumberOfRuns(): void
    {
        $runtime = $this->createMock(AgentRuntimeInterface::class);
        $runtime->expects(self::exactly(FieldFillHook::MAX_RUNS_PER_SAVE))->method('defer')->willReturn('uuid');

        for ($uid = 10; $uid < 40; ++$uid) {
            $this->pool->getConnectionForTable('pages')->insert('pages', ['uid' => $uid, 'pid' => 0, 'title' => str_repeat('A long title. ', 8), 'doktype' => 1]);
        }

        for ($i = 0; $i < FieldFillHook::MAX_RUNS_PER_SAVE; ++$i) {
            GeneralUtility::addInstance(FieldFillPlanner::class, $this->planner($runtime));
        }

        $hook = new FieldFillHook($this->settings(), new NullLogger());

        for ($uid = 10; $uid < 40; ++$uid) {
            $handler = GeneralUtility::makeInstance(DataHandler::class);
            $handler->start([], [], $this->admin);
            $hook->processDatamap_afterDatabaseOperations('update', 'pages', $uid, ['title' => 'x'], $handler);
        }

        self::assertSame([], $this->queuedOrigins());
    }

    #[Test]
    public function aTextAddedWhileTheRunWaitedStands(): void
    {
        $this->save(['title' => 'Bicycles and more']);
        $this->pool->getConnectionForTable('pages')->update('pages', ['description' => 'Written by a person'], ['uid' => self::PAGE]);

        $this->work('Written by a model.');

        self::assertSame('Written by a person', $this->valueOf('description'));
        self::assertSame(FieldFillOutcome::NOT_EMPTY->value, $this->lastRun()->outcome);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function answersThatAreNotWritten(): iterable
    {
        yield 'nothing to write from' => [FieldFillWriter::NOTHING];
        yield 'nothing, as a sentence' => ['Nothing.'];
        yield 'nothing, in bold' => ['**NOTHING**'];
        yield 'empty' => ['  '];
        yield 'only markup' => ['<br>'];
        yield 'too long' => [str_repeat('x', FieldFillSettings::DEFAULT_MAX_LENGTH + 1)];
    }

    #[Test]
    #[DataProvider('answersThatAreNotWritten')]
    public function anUnusableAnswerLeavesTheFieldEmpty(string $answer): void
    {
        $this->save(['title' => 'Bicycles and more']);

        $this->work($answer);

        self::assertSame('', $this->valueOf('description'));
        self::assertNotSame(FieldFillOutcome::WRITTEN->value, $this->lastRun()->outcome);
    }

    #[Test]
    public function theLongestAllowedAnswerIsWritten(): void
    {
        $answer = str_repeat('x', FieldFillSettings::DEFAULT_MAX_LENGTH);
        $this->save(['title' => 'Bicycles and more']);

        $this->work($answer);

        self::assertSame($answer, $this->valueOf('description'));
    }

    #[Test]
    public function quotesAndTagsAroundAnAnswerDoNotReachTheField(): void
    {
        $this->save(['title' => 'Bicycles and more']);

        $this->work("\"<b>Old bicycles</b>, repaired.\"\n");

        self::assertSame('Old bicycles, repaired.', $this->valueOf('description'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function answersWithOuterCharactersBeyondAscii(): iterable
    {
        yield 'cyrillic' => ['Новый материал', 'Новый материал'];
        yield 'hebrew' => ['שלום עולם', 'שלום עולם'];
        yield 'an emoji' => ['Neue Räder 🚀', 'Neue Räder 🚀'];
        yield 'a currency' => ['€ 20 Rabatt', '€ 20 Rabatt'];
        yield 'typographic quotes' => ['„Alte Räder“', 'Alte Räder'];
        yield 'guillemets' => ['«Vieux vélos»', 'Vieux vélos'];
        yield 'a title at the start' => ['"Faust" ist ein Drama', '"Faust" ist ein Drama'];
        yield 'a title at the end' => ['Im Stück „Faust“', 'Im Stück „Faust“'];
        yield 'two titles' => ['"Faust" und "Iphigenie"', '"Faust" und "Iphigenie"'];
        yield 'a comparison' => ['Kinder <12 Jahre zahlen weniger', 'Kinder <12 Jahre zahlen weniger'];
    }

    #[Test]
    #[DataProvider('answersWithOuterCharactersBeyondAscii')]
    public function anAnswerKeepsItsCharactersWhole(string $answer, string $stored): void
    {
        $this->save(['title' => 'Bicycles and more']);

        $this->work($answer);

        self::assertSame($stored, $this->valueOf('description'));
    }

    /**
     * Core writes what a translation inherits on each save of the default record,
     * and that comes through the hook as a save of the translation.
     */
    #[Test]
    public function aSaveOfAPageDoesNotQueueItsTranslations(): void
    {
        $this->pool->getConnectionForTable('pages')->insert('pages', [
            'uid'      => 2, 'pid' => 0, 'l10n_parent' => self::PAGE, 'sys_language_uid' => 1, 'title' => 'Fahrräder', 'doktype' => 1,
            'abstract' => self::PAGE_TEXT, 'perms_everybody' => 31,
        ]);

        // A column the translation inherits, so core has something to carry over.
        $this->save(['title' => 'Bicycles and more', 'layout' => 1]);

        self::assertSame(['fill:pages:1:description'], $this->queuedOrigins());
        self::assertSame(1, (int) $this->pool->getConnectionForTable('pages')->executeQuery('SELECT layout FROM pages WHERE uid = 2')->fetchOne());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function changesThatAreNoEdit(): iterable
    {
        yield 'a slug, also what redirects writes below a page' => [['slug' => '/new', 'tstamp' => 1]];
        yield 'a value translations inherit' => [['endtime' => 1900000000, 'l10n_state' => '{}']];
        yield 'nothing at all' => [[]];
    }

    /**
     * @param array<string, mixed> $changed
     */
    #[Test]
    #[DataProvider('changesThatAreNoEdit')]
    public function aChangeThatIsNoEditQueuesNothing(array $changed): void
    {
        self::assertSame(0, $this->planner()->afterSave('update', 'pages', self::PAGE, $changed, $this->admin));
    }

    #[Test]
    public function theNotesOfTheEditorsDoNotReachTheModel(): void
    {
        $text = (new RecordTextReader($this->pool))->textOf('tt_content', ['header' => 'Repair', 'bodytext' => 'Old frames', 'rowDescription' => 'Client hates this']);

        self::assertStringContainsString('Old frames', $text);
        self::assertStringNotContainsString('Client hates this', $text);
    }

    /**
     * A foreign hook may log an error about a write that landed.
     */
    #[Test]
    public function aWriteThatLandedCountsWhateverTheDataHandlerLogged(): void
    {
        // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['nrLlmFieldFillTest'] = NoisyDataHandlerHook::class;

        try {
            self::assertSame(
                FieldFillOutcome::WRITTEN,
                $this->writer()->write(new FieldFillTarget('pages', self::PAGE, 'description'), 'A text.', AiActorContext::backendUser(1)),
            );
        } finally {
            // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
            unset($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['nrLlmFieldFillTest']);
        }
    }

    /**
     * A hook failing after the row is written, in a process without a session.
     */
    #[Test]
    public function aWriteThatLandedCountsWhenAHookThrowsAfterIt(): void
    {
        // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['nrLlmFieldFillTest'] = ThrowingDataHandlerHook::class;

        try {
            self::assertSame(
                FieldFillOutcome::WRITTEN,
                $this->writer()->write(new FieldFillTarget('pages', self::PAGE, 'description'), 'A text.', AiActorContext::backendUser(1)),
            );
        } finally {
            // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
            unset($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['nrLlmFieldFillTest']);
        }
    }

    #[Test]
    public function aFailedRunWritesNothingAndShowsInTheHistory(): void
    {
        $this->save(['title' => 'Bicycles and more']);

        $this->work(null);

        self::assertSame('', $this->valueOf('description'));
        self::assertNotSame(AgentRunStatus::COMPLETED, $this->lastRun()->statusEnum());
        self::assertSame('', $this->lastRun()->outcome);
    }

    #[Test]
    public function withdrawingTheOptInStopsWhatWasQueued(): void
    {
        $this->save(['title' => 'Bicycles and more']);
        $this->optIn('');

        $seen = [];
        $this->work('Written by a model.', $seen);

        self::assertSame([], $seen);
        self::assertSame('', $this->valueOf('description'));
        self::assertSame(AgentRunStatus::CANCELLED, $this->lastRun()->statusEnum());
    }

    #[Test]
    public function anOptInWithdrawnWhileTheModelWorkedStopsTheWrite(): void
    {
        $this->optIn('');

        self::assertSame(
            FieldFillOutcome::NOT_WRITTEN,
            $this->writer()->write(new FieldFillTarget('pages', self::PAGE, 'description'), 'A text.', AiActorContext::backendUser(1)),
        );
        self::assertSame('', $this->valueOf('description'));
    }

    #[Test]
    public function aUserInADraftWorkspaceGetsNoWrite(): void
    {
        $user            = self::createStub(BackendUserAuthentication::class);
        $user->workspace = 3;

        $users = self::createStub(ActingBackendUserResolverInterface::class);
        $users->method('resolve')->willReturn($user);

        $writer = new FieldFillWriter($this->settings(), new RecordTextReader($this->pool), $users, $this->getService(LanguageServiceFactory::class), $this->getService(EventDispatcherInterface::class));

        self::assertSame(
            FieldFillOutcome::NOT_WRITTEN,
            $writer->write(new FieldFillTarget('pages', self::PAGE, 'description'), 'A text.', AiActorContext::backendUser(1)),
        );
        self::assertSame('', $this->valueOf('description'));
    }

    #[Test]
    public function theRightsOfTheUserWhoSavedDecideTheWrite(): void
    {
        $this->pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 7, 'username' => 'reader', 'admin' => 0]);
        $logger = new class extends AbstractLogger {
            public int $warnings = 0;

            public function log($level, string|Stringable $message, array $context = []): void
            {
                ++$this->warnings;
            }
        };

        self::assertSame(
            FieldFillOutcome::NOT_WRITTEN,
            $this->writer($logger)->write(new FieldFillTarget('pages', self::PAGE, 'description'), 'A text.', AiActorContext::backendUser(7)),
        );
        self::assertSame(1, $logger->warnings);
        self::assertSame('', $this->valueOf('description'));
    }

    /**
     * A write the DataHandler drops without a word is the case a read-back exists
     * for: a column the user may not set is taken out of the save silently.
     */
    #[Test]
    public function aWriteThatDidNotLandIsNotReportedAsWritten(): void
    {
        $this->pool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 8, 'title' => 'Editors', 'tables_modify' => 'pages', 'tables_select' => 'pages',
        ]);
        $this->pool->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => 9, 'username' => 'editor', 'admin' => 0, 'usergroup' => '8', 'db_mountpoints' => '1',
        ]);
        $this->pool->getConnectionForTable('pages')->update('pages', ['perms_everybody' => 31], ['uid' => self::PAGE]);

        self::assertSame(
            FieldFillOutcome::NOT_WRITTEN,
            $this->writer()->write(new FieldFillTarget('pages', self::PAGE, 'description'), 'A text.', AiActorContext::backendUser(9)),
        );
        self::assertSame('', $this->valueOf('description'));
    }

    #[Test]
    public function theWorkerLeavesTheGlobalsAsItFoundThem(): void
    {
        $this->save(['title' => 'Bicycles and more']);
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);

        $this->work('A text.');

        self::assertSame('A text.', $this->valueOf('description'));
        self::assertArrayNotHasKey('BE_USER', $GLOBALS);
        self::assertArrayNotHasKey('LANG', $GLOBALS);
    }

    #[Test]
    public function aWorkerInsideARequestGetsItsOwnUserBack(): void
    {
        $this->save(['title' => 'Bicycles and more']);
        $language = $GLOBALS['LANG'];

        $this->work('A text.');

        self::assertSame($this->admin, $GLOBALS['BE_USER']);
        self::assertSame($language, $GLOBALS['LANG']);
    }

    private function optIn(string $fields): void
    {
        // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm'] = ['fieldFill' => ['fields' => $fields]] + $this->extensionSettings;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function save(array $fields, ?BackendUserAuthentication $user = null): void
    {
        $handler = GeneralUtility::makeInstance(DataHandler::class);
        $handler->start(['pages' => [self::PAGE => $fields]], [], $user ?? $this->admin);
        $handler->process_datamap();

        self::assertSame([], $handler->errorLog);
    }

    /**
     * @param list<string> $seen the prompts the model got
     */
    private function work(?string $answer, array &$seen = []): void
    {
        $loop = self::createStub(ToolLoopServiceInterface::class);
        $loop->method('runLoop')->willReturnCallback(static function (array $messages) use ($answer, &$seen): ToolLoopResult {
            $seen[] = json_encode($messages, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($answer === null) {
                throw new RuntimeException('provider gone', 1798000102);
            }

            return new ToolLoopResult($answer, [], 1, false, UsageStatistics::fromTokens(1, 1));
        });

        $runtime = new AgentRuntime(
            $loop,
            $this->persister(),
            $this->getService(LlmConfigurationRepository::class),
            new NullLogger(),
            self::createStub(MessageBusInterface::class),
            actingBackendUserResolver: new ActingBackendUserResolver(),
            privacyPolicy: new FixedPrivacyPolicy(PrivacyLevel::METADATA),
            fieldFillWriter: $this->writer(),
        );

        foreach ($this->repository->findQueued(10) as $run) {
            $runtime->runQueued($run->uuid);
        }
    }

    private function writer(?LoggerInterface $logger = null): FieldFillWriter
    {
        return new FieldFillWriter(
            $this->settings(),
            new RecordTextReader($this->pool),
            new ActingBackendUserResolver(),
            $this->getService(LanguageServiceFactory::class),
            $this->dispatcher ?? $this->getService(EventDispatcherInterface::class),
            $logger,
        );
    }

    private function settings(): FieldFillSettings
    {
        return new FieldFillSettings($this->getService(ExtensionConfiguration::class));
    }

    private function planner(?AgentRuntimeInterface $runtime = null): FieldFillPlanner
    {
        return new FieldFillPlanner(
            $this->settings(),
            new RecordTextReader($this->pool),
            $runtime ?? self::createStub(AgentRuntimeInterface::class),
            $this->persister(),
            $this->getService(LlmConfigurationRepository::class),
            new ConfigurationResolver(),
        );
    }

    private function persister(): AgentRunPersister
    {
        return new AgentRunPersister($this->repository, FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL), new NullLogger());
    }

    /**
     * @return list<string>
     */
    private function queuedOrigins(): array
    {
        return array_map(static fn (AgentRun $run): string => $run->origin, $this->repository->findQueued(50));
    }

    private function lastRun(): AgentRun
    {
        $uuid = $this->pool->getConnectionForTable('tx_nrllm_agentrun')->select(['uuid'], 'tx_nrllm_agentrun', [], [], ['uid' => 'DESC'], 1)->fetchOne();
        self::assertIsString($uuid);

        $run = $this->repository->findByUuid($uuid);
        self::assertInstanceOf(AgentRun::class, $run);

        return $run;
    }

    private function valueOf(string $column): string
    {
        $value = $this->pool->getConnectionForTable('pages')->select([$column], 'pages', ['uid' => self::PAGE])->fetchOne();

        return is_string($value) ? $value : '';
    }
}
