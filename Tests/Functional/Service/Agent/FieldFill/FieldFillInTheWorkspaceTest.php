<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\FieldFill;

use Netresearch\NrLlm\Domain\Enum\FieldFillOutcome;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\FieldFillTarget;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntime;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Agent\FieldFill\FieldFillHook;
use Netresearch\NrLlm\Service\Agent\FieldFill\FieldFillPlanner;
use Netresearch\NrLlm\Service\Agent\FieldFill\FieldFillSettings;
use Netresearch\NrLlm\Service\Agent\FieldFill\FieldFillWriter;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolver;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\AgentRunRepository;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Service\Tool\RecordTextReader;
use Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The fill of an empty field after a save in a draft workspace (ADR-265): the
 * run records the workspace, reads the record and its page as the workspace
 * shows them, and writes a version there through the DataHandler — never the
 * live row, wherever the editor works when the worker picks the run up.
 */
#[CoversClass(FieldFillHook::class)]
#[CoversClass(FieldFillPlanner::class)]
#[CoversClass(FieldFillWriter::class)]
#[CoversClass(RecordTextReader::class)]
final class FieldFillInTheWorkspaceTest extends AbstractFunctionalTestCase
{
    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'workspaces'];

    /** @var non-empty-string[] */
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'typo3conf/ext/nr_llm/Tests/Functional/Fixtures/Extensions/nrllm_writer_fixture',
    ];

    private const DRAFT = 1;

    private const OTHER_DRAFT = 2;

    private const PAGE = 1;

    private const PAGE_TEXT = 'A page about the repair of old bicycles, their frames, their gears and the people who still do it by hand.';

    private const DRAFT_TEXT = 'In the draft the page is about tandem bicycles, built for two riders, and the clubs that still ride them.';

    private ConnectionPool $pool;

    private AgentRunRepository $repository;

    private BackendUserAuthentication $admin;

    /** @var array<string, mixed> */
    private array $extensionSettings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $this->importFixture('Providers.csv');
        $this->importFixture('Models.csv');
        $this->importFixture('LlmConfigurations.csv');

        $this->pool       = $this->getService(ConnectionPool::class);
        $this->repository = new AgentRunRepository($this->pool, $this->getService(AgentStateCodec::class));

        $workspaces = $this->pool->getConnectionForTable('sys_workspace');
        $workspaces->insert('sys_workspace', ['uid' => self::DRAFT, 'pid' => 0, 'title' => 'Entwurf', 'members' => 'be_users_2']);
        $workspaces->insert('sys_workspace', ['uid' => self::OTHER_DRAFT, 'pid' => 0, 'title' => 'Other', 'members' => '']);

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
            'uid' => 1, 'pid' => self::PAGE, 'CType' => 'text', 'header' => 'Repair', 'bodytext' => '<p>' . self::PAGE_TEXT . '</p>', 'sorting' => 1,
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
    public function aSaveInTheDraftIsFilledInTheDraftWhereverTheEditorWorksLater(): void
    {
        $this->inDraft();
        $this->save(['title' => 'Bicycles in the draft']);

        // Positive control: core stored the save as a version in the draft.
        self::assertSame('Bicycles in the draft', $this->versionOf(self::PAGE)['title'] ?? null);
        self::assertSame('Bicycles', $this->liveRow(self::PAGE)['title']);

        // The run is the live record's, and it records the draft.
        self::assertSame(['fill:pages:1:description'], $this->queuedOrigins());
        self::assertSame(self::DRAFT, $this->queuedWorkspace());

        // The editor goes back to live before the worker runs.
        $this->pool->getConnectionForTable('be_users')->update('be_users', ['workspace_id' => 0], ['uid' => 1]);

        $this->work('Tandem bicycles and their clubs.');

        self::assertSame(FieldFillOutcome::WRITTEN->value, $this->lastRun()->outcome);
        $version = $this->versionOf(self::PAGE);
        self::assertSame('Tandem bicycles and their clubs.', $version['description'] ?? null);
        self::assertSame(self::DRAFT, (int) ($version['t3ver_wsid'] ?? 0));
        self::assertSame(1, (int) ($version['tx_nrllm_ai_written'] ?? 0), 'The version carries the AI mark.');
        self::assertSame(1, $this->pool->getConnectionForTable('pages')->count('uid', 'pages', ['t3ver_oid' => self::PAGE]));

        $live = $this->liveRow(self::PAGE);
        $this->assertNoText($live['description'], 'Live stays as it was.');
        self::assertSame(0, (int) $live['tx_nrllm_ai_written'], 'Live is not marked.');

        // The worker's write in the draft is no save of an editor.
        self::assertSame([], $this->queuedOrigins());
    }

    #[Test]
    public function theModelReadsThePageAsTheDraftShowsIt(): void
    {
        $content = $this->pool->getConnectionForTable('tt_content');
        $content->insert('tt_content', [
            'uid'        => 2, 'pid' => self::PAGE, 'CType' => 'text', 'header' => 'Tandems', 'bodytext' => self::DRAFT_TEXT, 'sorting' => 1,
            't3ver_wsid' => self::DRAFT, 't3ver_oid' => 1,
        ]);
        $content->insert('tt_content', [
            'uid'        => 3, 'pid' => self::PAGE, 'CType' => 'text', 'header' => 'Only in the draft', 'bodytext' => 'A club ride every Sunday.', 'sorting' => 2,
            't3ver_wsid' => self::DRAFT, 't3ver_state' => 1,
        ]);
        $content->insert('tt_content', [
            'uid'        => 4, 'pid' => self::PAGE, 'CType' => 'text', 'header' => 'Secret', 'bodytext' => 'Text of another workspace.', 'sorting' => 3,
            't3ver_wsid' => self::OTHER_DRAFT, 't3ver_state' => 1,
        ]);

        $this->inDraft();
        $this->save(['title' => 'Bicycles in the draft']);

        $seen = [];
        $this->work('Tandem bicycles and their clubs.', $seen);

        self::assertCount(1, $seen);
        self::assertStringContainsString(self::DRAFT_TEXT, $seen[0]);
        self::assertStringContainsString('A club ride every Sunday.', $seen[0]);
        self::assertStringContainsString('Bicycles in the draft', $seen[0]);
        self::assertStringNotContainsString('old bicycles', $seen[0], 'The text the draft replaced does not reach the model.');
        self::assertStringNotContainsString('another workspace', $seen[0], "Another workspace's draft never reaches the model.");
    }

    #[Test]
    public function aRecordNewInTheDraftIsFilledInPlace(): void
    {
        $this->inDraft();
        $handler = GeneralUtility::makeInstance(DataHandler::class);
        $handler->start(['pages' => ['NEW1' => ['pid' => self::PAGE, 'title' => 'New', 'doktype' => 1, 'abstract' => str_repeat('A long abstract about tandems. ', 5)]]], [], $this->admin);
        $handler->process_datamap();
        self::assertSame([], $handler->errorLog);

        $uid = (int) ($handler->substNEWwithIDs['NEW1'] ?? 0);
        self::assertGreaterThan(self::PAGE, $uid);
        self::assertSame(['fill:pages:' . $uid . ':description'], $this->queuedOrigins());

        $this->work('A page about tandems.');

        // New pages are hidden, so read past the restrictions.
        $row = $this->rowWhere(['uid' => $uid]);
        self::assertIsArray($row);
        self::assertSame('A page about tandems.', $row['description']);
        self::assertSame(self::DRAFT, (int) $row['t3ver_wsid']);
        self::assertSame(1, (int) $row['t3ver_state'], 'Still new in the draft.');
        self::assertSame(1, (int) $row['tx_nrllm_ai_written']);
        self::assertSame(1, $this->pool->getConnectionForTable('pages')->count('uid', 'pages', ['t3ver_wsid' => 0]), 'Nothing new in live.');
    }

    /**
     * The field holds a value in the draft, so there is nothing to fill there,
     * although the live row is empty.
     */
    #[Test]
    public function aFieldTheDraftHoldsIsNotQueued(): void
    {
        $this->inDraft();
        $this->save(['description' => 'Written by a person in the draft']);
        self::assertSame([], $this->queuedOrigins());

        $this->save(['title' => 'Bicycles in the draft']);

        self::assertSame([], $this->queuedOrigins());
        $this->assertNoText($this->liveRow(self::PAGE)['description'], 'positive control: the live row is empty');
    }

    #[Test]
    public function aTextWrittenInTheDraftWhileTheRunWaitedStands(): void
    {
        $this->inDraft();
        $this->save(['title' => 'Bicycles in the draft']);
        $this->pool->getConnectionForTable('pages')->update('pages', ['description' => 'Written by a person'], ['t3ver_oid' => self::PAGE, 't3ver_wsid' => self::DRAFT]);

        $this->work('Written by a model.');

        self::assertSame(FieldFillOutcome::NOT_EMPTY->value, $this->lastRun()->outcome);
        self::assertSame('Written by a person', $this->versionOf(self::PAGE)['description'] ?? null);
    }

    /**
     * Sent on to review while the run waited: any write would pull the draft
     * back to editing, and nobody chose that.
     */
    #[Test]
    public function aDraftSentToReviewWhileTheRunWaitedIsNotFilled(): void
    {
        $this->inDraft();
        $this->save(['title' => 'Bicycles in the draft']);
        $this->pool->getConnectionForTable('pages')->update('pages', ['t3ver_stage' => -10], ['t3ver_oid' => self::PAGE, 't3ver_wsid' => self::DRAFT]);

        $this->work('Tandem bicycles and their clubs.');

        self::assertSame(FieldFillOutcome::NOT_WRITTEN->value, $this->lastRun()->outcome);
        $version = $this->versionOf(self::PAGE);
        $this->assertNoText($version['description'] ?? null);
        self::assertSame(-10, (int) ($version['t3ver_stage'] ?? 0), 'The draft stays where it was sent.');
    }

    #[Test]
    public function aFillSavedInLiveStaysLiveWhenTheEditorMovesIntoADraft(): void
    {
        $this->save(['title' => 'Bicycles and more']);
        self::assertSame(0, $this->queuedWorkspace());

        $this->pool->getConnectionForTable('be_users')->update('be_users', ['workspace_id' => self::DRAFT], ['uid' => 1]);

        $this->work('Old bicycles, repaired by hand.');

        self::assertSame(FieldFillOutcome::WRITTEN->value, $this->lastRun()->outcome);
        self::assertSame('Old bicycles, repaired by hand.', $this->liveRow(self::PAGE)['description']);
        self::assertNull($this->versionOf(self::PAGE));
    }

    /**
     * Queued before the workspace was recorded, and the user's record now names
     * a draft: the fill neither guesses the workspace nor writes live.
     */
    #[Test]
    public function aRunWithoutARecordedWorkspaceWritesNothingInADraft(): void
    {
        $this->pool->getConnectionForTable('be_users')->update('be_users', ['workspace_id' => self::DRAFT], ['uid' => 1]);
        self::assertSame(self::DRAFT, (new ActingBackendUserResolver())->resolve(AiActorContext::backendUser(1))?->workspace, 'positive control: the user comes up in the draft');

        self::assertSame(
            FieldFillOutcome::NOT_WRITTEN,
            $this->writer()->write(new FieldFillTarget('pages', self::PAGE, 'description'), 'A text.', AiActorContext::backendUser(1)),
        );
        $this->assertNoText($this->liveRow(self::PAGE)['description']);
        self::assertNull($this->versionOf(self::PAGE));
    }

    #[Test]
    public function aDraftOfAnotherWorkspaceIsNeitherReadNorFilled(): void
    {
        $this->pool->getConnectionForTable('pages')->insert('pages', [
            'uid'        => 7, 'pid' => self::PAGE, 'title' => 'Elsewhere', 'doktype' => 1, 'abstract' => str_repeat('A long abstract about tandems. ', 5),
            't3ver_wsid' => self::OTHER_DRAFT, 't3ver_state' => 1,
        ]);
        // And a version of the live page in that other workspace, by its own uid.
        $this->pool->getConnectionForTable('pages')->insert('pages', [
            'uid'        => 8, 'pid' => 0, 'title' => 'Bicycles elsewhere', 'doktype' => 1,
            't3ver_wsid' => self::OTHER_DRAFT, 't3ver_oid' => self::PAGE,
        ]);
        $this->inDraft();

        self::assertSame(0, $this->planner()->afterSave('update', 'pages', 7, ['title' => 'x'], $this->admin));
        self::assertSame(0, $this->planner()->afterSave('update', 'pages', 8, ['title' => 'x'], $this->admin));
        self::assertSame(1, $this->planner()->afterSave('update', 'pages', self::PAGE, ['title' => 'x'], $this->admin), 'positive control: the live page is planned in the draft');
        self::assertSame(
            FieldFillOutcome::NOT_WRITTEN,
            $this->writer()->write(new FieldFillTarget('pages', 7, 'description'), 'A text.', AiActorContext::backendUser(1, true, [], [], self::DRAFT)),
        );
        $this->assertNoText($this->pool->getConnectionForTable('pages')->select(['description'], 'pages', ['uid' => 7])->fetchOne());
    }

    /**
     * A table that keeps no versions would be written live from a draft — core
     * allows that where the workspace permits live editing. The fill does
     * neither: no plan and no write.
     */
    #[Test]
    public function aTableWithoutVersionsIsNeitherPlannedNorWrittenInADraft(): void
    {
        $this->pool->getConnectionForTable('sys_workspace')->update('sys_workspace', ['live_edit' => 1], ['uid' => self::DRAFT]);
        $this->pool->getConnectionForTable('tx_writerfixture_item')->insert('tx_writerfixture_item', [
            'uid' => 5, 'pid' => self::PAGE, 'title' => 'Tandems', 'kind' => 'note', 'body' => self::DRAFT_TEXT,
        ]);
        $this->optIn('tx_writerfixture_item.teaser');
        $target = new FieldFillTarget('tx_writerfixture_item', 5, 'teaser');

        // Positive control: planned in live.
        self::assertSame(1, $this->planner()->afterSave('update', 'tx_writerfixture_item', 5, ['title' => 'x'], $this->admin));

        $this->inDraft();
        self::assertSame(0, $this->planner()->afterSave('update', 'tx_writerfixture_item', 5, ['title' => 'x'], $this->admin));

        $this->pool->getConnectionForTable('be_users')->update('be_users', ['workspace_id' => self::DRAFT], ['uid' => 1]);
        self::assertSame(
            FieldFillOutcome::NOT_WRITTEN,
            $this->writer()->write($target, 'A teaser about tandems.', AiActorContext::backendUser(1, true, [], [], self::DRAFT)),
        );
        $this->assertNoText($this->pool->getConnectionForTable('tx_writerfixture_item')->select(['teaser'], 'tx_writerfixture_item', ['uid' => 5])->fetchOne());

        // Positive control: written in live.
        self::assertSame(
            FieldFillOutcome::WRITTEN,
            $this->writer()->write($target, 'A teaser about tandems.', AiActorContext::backendUser(1, true, [], [], 0)),
        );
    }

    /**
     * The worker's write is no save of an editor: a second field, still empty
     * when the worker writes, would be queued by any save that reached the
     * hook.
     */
    #[Test]
    public function theWorkersOwnWriteQueuesNoFill(): void
    {
        $this->inDraft();
        $this->save(['title' => 'Bicycles in the draft']);
        self::assertSame(['fill:pages:1:description'], $this->queuedOrigins());
        $this->optIn('pages.description,pages.abstract');

        $this->work('Old bicycles, repaired by hand.');

        self::assertSame(FieldFillOutcome::WRITTEN->value, $this->lastRun()->outcome);
        self::assertSame('Old bicycles, repaired by hand.', $this->versionOf(self::PAGE)['description'] ?? null);
        $this->assertNoText($this->versionOf(self::PAGE)['abstract'] ?? null, 'positive control: the second field is still empty');
        self::assertSame([], $this->queuedOrigins());
    }

    /**
     * The save's draft was discarded while the run waited: the fill does not
     * make a draft of its own.
     */
    #[Test]
    public function aDiscardedDraftIsNotMadeAgainForTheFill(): void
    {
        $this->inDraft();
        $this->save(['title' => 'Bicycles in the draft']);
        $this->pool->getConnectionForTable('pages')->delete('pages', ['t3ver_oid' => self::PAGE, 't3ver_wsid' => self::DRAFT]);
        self::assertNull($this->versionOf(self::PAGE));

        $this->work('Old bicycles, repaired by hand.');

        self::assertSame(FieldFillOutcome::NOT_WRITTEN->value, $this->lastRun()->outcome);
        self::assertNull($this->versionOf(self::PAGE));
        $this->assertNoText($this->liveRow(self::PAGE)['description'], 'Live stays as it was.');
    }

    /**
     * A table core edits live from a draft — `versioningWS_alwaysAllowLiveEdit`,
     * which TYPO3 13.4 honours even for a versioned table — is neither planned
     * nor written there.
     */
    #[Test]
    public function aTableCoreEditsLiveFromADraftIsNeitherPlannedNorWritten(): void
    {
        $this->inDraft();
        self::assertSame(1, $this->planner()->afterSave('update', 'pages', self::PAGE, ['title' => 'x'], $this->admin), 'positive control: planned in the draft');

        // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
        $GLOBALS['TCA']['pages']['ctrl']['versioningWS_alwaysAllowLiveEdit'] = true;

        try {
            self::assertSame(0, $this->planner()->afterSave('update', 'pages', self::PAGE, ['title' => 'x'], $this->admin));
            self::assertSame(
                FieldFillOutcome::NOT_WRITTEN,
                $this->writer()->write(new FieldFillTarget('pages', self::PAGE, 'description'), 'A text.', AiActorContext::backendUser(1, true, [], [], self::DRAFT)),
            );
        } finally {
            // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
            unset($GLOBALS['TCA']['pages']['ctrl']['versioningWS_alwaysAllowLiveEdit']);
        }

        $this->assertNoText($this->liveRow(self::PAGE)['description']);
        self::assertNull($this->versionOf(self::PAGE));
    }

    #[Test]
    public function aUserInNoWorkspaceAtAllQueuesNothing(): void
    {
        self::assertSame(1, $this->planner()->afterSave('update', 'pages', self::PAGE, ['title' => 'x'], $this->admin), 'positive control: live');

        // Core's "not initialised".
        $this->admin->workspace = -99;

        self::assertSame(0, $this->planner()->afterSave('update', 'pages', self::PAGE, ['title' => 'x'], $this->admin));
    }

    /**
     * An empty column: NULL or '' — not a missing row, which fetchOne() answers with false.
     */
    private function assertNoText(mixed $value, string $message = ''): void
    {
        self::assertTrue($value === null || $value === '', $message !== '' ? $message : 'Expected no text, got ' . var_export($value, true));
    }

    private function inDraft(): void
    {
        self::assertTrue($this->admin->setTemporaryWorkspace(self::DRAFT));
        self::assertSame(self::DRAFT, $this->admin->workspace, 'positive control: the editor works in the draft');
    }

    private function optIn(string $fields): void
    {
        // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm'] = ['fieldFill' => ['fields' => $fields]] + $this->extensionSettings;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function save(array $fields): void
    {
        $handler = GeneralUtility::makeInstance(DataHandler::class);
        $handler->start(['pages' => [self::PAGE => $fields]], [], $this->admin);
        $handler->process_datamap();

        self::assertSame([], $handler->errorLog);
    }

    /**
     * @param list<string> $seen the prompts the model got
     */
    private function work(string $answer, array &$seen = []): void
    {
        $loop = self::createStub(ToolLoopServiceInterface::class);
        $loop->method('runLoop')->willReturnCallback(static function (array $messages) use ($answer, &$seen): ToolLoopResult {
            $seen[] = json_encode($messages, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

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

    private function writer(): FieldFillWriter
    {
        return new FieldFillWriter(
            $this->settings(),
            new RecordTextReader($this->pool),
            new ActingBackendUserResolver(),
            $this->getService(LanguageServiceFactory::class),
            $this->getService(EventDispatcherInterface::class),
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

    /**
     * The workspace the one queued run's actor recorded.
     */
    private function queuedWorkspace(): ?int
    {
        $queued = $this->repository->findQueued(50);
        self::assertCount(1, $queued);
        $run = $this->repository->findByUuid($queued[0]->uuid);
        self::assertInstanceOf(AgentRun::class, $run);
        self::assertIsString($run->queuedRequest);

        $request = json_decode($run->queuedRequest, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($request);
        $actor = $request['actor'] ?? null;
        self::assertIsArray($actor);
        $workspace = $actor['workspaceId'] ?? null;

        return is_int($workspace) ? $workspace : null;
    }

    private function lastRun(): AgentRun
    {
        $uuid = $this->pool->getConnectionForTable('tx_nrllm_agentrun')->select(['uuid'], 'tx_nrllm_agentrun', [], [], ['uid' => 'DESC'], 1)->fetchOne();
        self::assertIsString($uuid);

        $run = $this->repository->findByUuid($uuid);
        self::assertInstanceOf(AgentRun::class, $run);

        return $run;
    }

    /**
     * @return array<string, mixed>
     */
    private function liveRow(int $uid): array
    {
        $row = $this->rowWhere(['uid' => $uid]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function versionOf(int $uid): ?array
    {
        return $this->rowWhere(['t3ver_oid' => $uid, 't3ver_wsid' => self::DRAFT]);
    }

    /**
     * A row of `pages` past every restriction.
     *
     * @param array<string, int> $where
     *
     * @return array<string, mixed>|null
     */
    private function rowWhere(array $where): ?array
    {
        $queryBuilder = $this->pool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->select('*')->from('pages');
        foreach ($where as $column => $value) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq($column, $queryBuilder->createNamedParameter($value, Connection::PARAM_INT)));
        }

        $row = $queryBuilder->executeQuery()->fetchAssociative();

        return is_array($row) ? $row : null;
    }
}
