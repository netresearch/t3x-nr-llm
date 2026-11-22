<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Service\Agent\ApprovalDecision;
use Netresearch\NrLlm\Service\Agent\PendingTurnDigest;
use Netresearch\NrLlm\Service\Tool\Scope\AssignmentScopeTransaction;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\FlushesADatabaseCacheHook;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\RegistersTheFailingHookTrait;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\RollsTheTransactionBackHook;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

/**
 * An approved write of the page metadata skill on an installation whose
 * DataHandler hook flushes a cache on a database backend (NEXT-240): the
 * report the A2A client reads matches what is stored.
 *
 * The flush truncates the cache tables inside the transaction of the scoped
 * call (ADR-295). On MySQL and MariaDB a `TRUNCATE` commits implicitly, so the
 * draft used to be stored while the call reported its changes as possibly not
 * saved and the task's `writes` artifact said no write was executed. On
 * SQLite and PostgreSQL, where the flush stays part of the transaction, the
 * same test checks that it stays part of the call.
 */
#[CoversClass(AssignmentScopeTransaction::class)]
#[CoversClass(ToolLoopService::class)]
final class A2aPageMetadataImplicitCommitTest extends AbstractFunctionalTestCase
{
    use A2aPageMetadataSkillFixture;
    use RegistersTheFailingHookTrait;

    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend', 'workspaces'];

    /** @var array<string, mixed> */
    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'caching' => [
                'cacheConfigurations' => [
                    FlushesADatabaseCacheHook::CACHE => [
                        'frontend' => VariableFrontend::class,
                        'backend'  => Typo3DatabaseBackend::class,
                        'options'  => ['defaultLifetime' => 300],
                        'groups'   => ['pages'],
                    ],
                ],
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSkill();
    }

    protected function tearDown(): void
    {
        $this->unregisterHook('processDatamapClass', FlushesADatabaseCacheHook::class);
        $this->unregisterHook('processDatamapClass', RollsTheTransactionBackHook::class);
        $this->tearDownSkill();
        parent::tearDown();
    }

    #[Test]
    public function anApprovedDraftIsReportedAsWrittenWhenAHookFlushesADatabaseCache(): void
    {
        $cache = $this->getService(CacheManager::class)->getCache(FlushesADatabaseCacheHook::CACHE);
        $cache->set('count', 7);
        self::assertTrue($cache->has('count'), 'the cache runs on its database tables');

        [$run, $taskId] = $this->approvedWriteWith(FlushesADatabaseCacheHook::class);

        self::assertFalse($cache->has('count'), 'the hook flushed the cache inside the call');

        // What is stored.
        self::assertSame('Eine kurze Beschreibung.', $this->draftDescription(), "the value is a draft in the client's workspace");
        self::assertSame('Old ' . self::TRANSLATION, $this->description(self::TRANSLATION), 'live is unchanged');

        // What is reported: to the model, and to the A2A client.
        $toolResult = $this->toolResultOf($this->stored($run));
        self::assertStringNotContainsString('NOT committed', $toolResult);
        self::assertStringContainsString('Updated page [' . self::TRANSLATION . ']', $toolResult);

        $writes = $this->writesOf($taskId);
        self::assertStringContainsString('pages:' . self::TRANSLATION . ' in workspace ' . self::WORKSPACE, $writes);
        self::assertStringNotContainsString('No write was executed', $writes);
        self::assertStringNotContainsString('may be incomplete', $writes);

        self::assertSame(0, $this->rows('sys_registry', ['entry_namespace' => 'tx_nrllm_scope']), 'no marker of the call stays behind');
    }

    /**
     * The other direction: a hook ends the transaction with a rollback. The
     * draft is gone, so neither the model nor the A2A client is told that it
     * was written.
     */
    #[Test]
    public function aDraftAHookRolledBackIsNotReportedAsWritten(): void
    {
        [$run, $taskId] = $this->approvedWriteWith(RollsTheTransactionBackHook::class);

        self::assertFalse($this->draftDescription(), 'the rollback took the draft');
        self::assertSame('Old ' . self::TRANSLATION, $this->description(self::TRANSLATION), 'live is unchanged');

        // The call is an error. The effect check cannot read the row the
        // rollback took, counts it as outside the scope, and the model is told
        // to review changes that were NOT rolled back (a known limit in
        // ADR-295). It is never told that the page was updated, and the A2A
        // client is never told that a write was executed.
        $toolResult = $this->toolResultOf($this->stored($run));
        self::assertStringContainsString('Error: tool "update_page_metadata"', $toolResult);
        self::assertStringNotContainsString('Updated page [' . self::TRANSLATION . ']', $toolResult);

        $writes = $this->writesOf($taskId);
        self::assertStringNotContainsString('pages:' . self::TRANSLATION . ' in workspace', $writes);
        // What the scope found it could not read is not called rolled back:
        // the call could not confirm its rollback, and the client is told to
        // check, as the model is (#1448).
        self::assertStringNotContainsString('refused or rolled back', $writes);
        self::assertStringContainsString("Writes the scope of the task's assignment refused or found outside it", $writes);
        self::assertStringContainsString('The rollback of a tool call that wrote outside the scope could not be confirmed', $writes);

        self::assertSame(0, $this->rows('sys_registry', ['entry_namespace' => 'tx_nrllm_scope']), 'no marker of the call stays behind');
    }

    /**
     * Sends the request, runs it to the approval of `update_page_metadata`
     * with the hook registered, and approves it.
     *
     * @param class-string $hook
     *
     * @return array{0: AgentRun, 1: string}
     */
    private function approvedWriteWith(string $hook): array
    {
        $this->registerHook('processDatamapClass', $hook);

        $this->db('be_groups')->update('be_groups', ['tables_select' => 'pages', 'tables_modify' => 'pages', 'non_exclude_fields' => 'pages:description'], ['uid' => self::GROUP]);
        $taskId = self::text($this->send(['page' => self::PAGE, 'language' => self::GERMAN, 'fields' => ['description']])['id'] ?? '');
        $run    = $this->runOf($taskId);

        $this->responses = [
            $this->callOf('get_page_content', ['uid' => self::PAGE, 'language' => self::GERMAN]),
            $this->callOf('check_page_metadata', ['uid' => self::TRANSLATION, 'description' => 'Eine kurze Beschreibung.']),
            $this->callOf('update_page_metadata', ['uid' => self::TRANSLATION, 'description' => 'Eine kurze Beschreibung.']),
        ];
        $runtime = $this->runtime();
        $runtime->runQueued($run->uuid);

        $waiting = $this->stored($run);
        self::assertSame(AgentRunStatus::WAITING_FOR_APPROVAL, $waiting->statusEnum());

        $this->responses = [$this->answer()];
        $state           = SuspendedRunState::fromArray(self::objectOf(json_decode($waiting->suspendedState ?? '', true)));
        $result          = $runtime->approve(AiActorContext::backendUser(1, isAdmin: true), $run->uuid, new ApprovalDecision(true, 1, (new PendingTurnDigest())->forState($state)));
        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome, (string) $result->error?->getMessage());

        return [$run, $taskId];
    }

    private function draftDescription(): mixed
    {
        return $this->db('pages')->select(['description'], 'pages', ['t3ver_oid' => self::TRANSLATION, 't3ver_wsid' => self::WORKSPACE])->fetchOne();
    }

    /**
     * The `writes` artifact of the completed task, as the A2A client reads it.
     */
    private function writesOf(string $taskId): string
    {
        $task = $this->taskOf($this->call('GetTask', ['id' => $taskId]));
        self::assertSame('TASK_STATE_COMPLETED', self::j($task, 'status.state'));
        self::assertSame('writes', self::j($task, 'artifacts.1.name'));

        return self::text(self::j($task, 'artifacts.1.parts.0.text'));
    }

    private function answer(): CompletionResponse
    {
        return new CompletionResponse('Done.', 'fake', UsageStatistics::fromTokens(1, 1));
    }
}
