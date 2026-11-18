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
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskStore;
use Netresearch\NrLlm\Service\Agent\ApprovalDecision;
use Netresearch\NrLlm\Service\Agent\PendingTurnDigest;
use Netresearch\NrLlm\Service\Tool\Scope\AssignmentScopeHook;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\RegistersTheFailingHookTrait;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\RunsANestedToolDataHandlerHook;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

/**
 * An approved write of the page metadata skill to a default-language page
 * that has translations, the shape of the A2A acceptance on the demo: the
 * request names language 0, so the run's scope admits language 0 only, and
 * core's data-map processor adds an entry for each translation of the page
 * to the write. The write is stored as a draft and reported as written.
 *
 * The report the A2A client reads matches what is stored in the other two
 * outcomes too: a call the scope refused a part of keeps what was in scope,
 * and the `writes` artifact names it; a call that is rolled back stores
 * nothing and names nothing.
 */
#[CoversClass(AssignmentScopeHook::class)]
#[CoversClass(A2aTaskStore::class)]
final class A2aPageMetadataTranslatedPageTest extends AbstractFunctionalTestCase
{
    use A2aPageMetadataSkillFixture;
    use RegistersTheFailingHookTrait;

    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend', 'workspaces'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSkill();
        RunsANestedToolDataHandlerHook::reset();
    }

    protected function tearDown(): void
    {
        $this->unregisterHook('processDatamapClass', RunsANestedToolDataHandlerHook::class);
        RunsANestedToolDataHandlerHook::reset();
        $this->tearDownSkill();
        parent::tearDown();
    }

    #[Test]
    public function aDraftOfAPageWithTranslationsIsStoredAndReportedAsWritten(): void
    {
        $translations = $this->rowsOfTheTranslations();

        [$run, $taskId] = $this->approvedWrite();

        self::assertSame('Eine kurze Beschreibung.', $this->draftDescription(), "the value is a draft in the client's workspace");
        self::assertSame('Old ' . self::PAGE, $this->description(self::PAGE), 'live is unchanged');
        self::assertSame($translations, $this->rowsOfTheTranslations(), 'the translations are not written and get no draft');

        $toolResult = $this->toolResultOf($this->stored($run));
        self::assertStringContainsString('Updated page [' . self::PAGE . ']', $toolResult);
        self::assertStringNotContainsString('did not complete', $toolResult);

        $writes = $this->writesOf($taskId);
        self::assertStringContainsString('Writes executed (1)', $writes);
        self::assertStringContainsString('pages:' . self::PAGE . ' in workspace ' . self::WORKSPACE, $writes);
        self::assertStringNotContainsString('No write was executed', $writes);
        self::assertStringNotContainsString('did not complete', $writes);
    }

    /**
     * An installation hook writes a page outside the scope after the page's
     * write. The scope refuses that write, the call is an error, and the draft
     * it stored before stands: the `writes` artifact names it.
     */
    #[Test]
    public function aDraftThatStandsBesideARefusedWriteIsNamedInTheWritesArtifact(): void
    {
        RunsANestedToolDataHandlerHook::$datamap = ['pages' => [self::NEIGHBOUR => ['description' => 'Written by a hook']]];

        [$run, $taskId] = $this->approvedWrite(RunsANestedToolDataHandlerHook::class);

        self::assertSame('Eine kurze Beschreibung.', $this->draftDescription(), 'the draft stands');
        self::assertFalse($this->draftOf(self::NEIGHBOUR), 'the hook did not write');

        $toolResult = $this->toolResultOf($this->stored($run));
        self::assertStringContainsString('did not complete', $toolResult);
        self::assertStringContainsString('pages:' . self::NEIGHBOUR, $toolResult);

        $writes = $this->writesOf($taskId);
        self::assertStringNotContainsString('No write was executed', $writes);
        self::assertStringContainsString('Records written by tool calls that did not complete (1)', $writes);
        // The draft is a new version row of the page, so the change reads "created".
        self::assertStringContainsString('- pages:' . self::PAGE . ' in workspace ' . self::WORKSPACE . ' (created)', $writes);
    }

    /**
     * The same hook with the DataHandler layer of the scope taken away: the
     * check of the effect finds the write outside the scope and rolls the call
     * back, the draft included, and the `writes` artifact names nothing.
     */
    #[Test]
    public function aCallThatIsRolledBackStoresNothingAndIsReportedAsNoWrite(): void
    {
        $this->unregisterHook('processDatamapClass', AssignmentScopeHook::class);
        $this->unregisterHook('processCmdmapClass', AssignmentScopeHook::class);
        RunsANestedToolDataHandlerHook::$datamap = ['pages' => [self::NEIGHBOUR => ['description' => 'Written by a hook']]];

        [$run, $taskId] = $this->approvedWrite(RunsANestedToolDataHandlerHook::class);

        self::assertFalse($this->draftDescription(), 'the rollback took the draft');
        self::assertFalse($this->draftOf(self::NEIGHBOUR), 'and the write of the hook');
        self::assertStringContainsString('All database changes of this call were rolled back.', $this->toolResultOf($this->stored($run)));
        self::assertStringStartsWith('No write was executed.', $this->writesOf($taskId));
    }

    /**
     * A failed call whose capture missed writes, or whose list was cut, is not
     * reported as no write: the artifact says the list may miss records. Only
     * the steps of a failed call count; a successful call's are left out.
     */
    #[Test]
    public function aFailedCallWhoseChangesWereNotAllCapturedIsNotReportedAsNoWrite(): void
    {
        $events = [
            ['tool', ['toolIsError' => false]],
            ['tool_changes', ['changes' => [['table' => 'pages', 'uid' => 7, 'action' => 'updated', 'workspace' => 0]], 'changeCount' => 1, 'changeCoverage' => 'datahandler', 'changesOmitted' => 0]],
            ['tool', ['toolIsError' => true]],
            ['tool_changes', ['changes' => [], 'changeCount' => 0, 'changeCoverage' => 'not_captured', 'changesOmitted' => 0]],
            ['tool', ['toolIsError' => true]],
            ['tool_changes', ['changes' => [['table' => 'pages', 'uid' => 9, 'action' => 'created', 'workspace' => 3, 'liveUid' => 8]], 'changeCount' => 1, 'changeCoverage' => 'datahandler', 'changesOmitted' => 0]],
        ];
        foreach ($events as $sequence => [$kind, $payload]) {
            $this->db('tx_nrllm_agentrun_event')->insert('tx_nrllm_agentrun_event', ['run' => 900, 'sequence' => $sequence, 'kind' => $kind, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
        }

        $artifact = (new ReflectionMethod(A2aTaskStore::class, 'writesArtifact'))->invoke($this->getService(A2aTaskStore::class), 900, false);
        $text     = self::text(self::j(self::objectOf($artifact), 'parts.0.text'));

        self::assertStringNotContainsString('No write was executed', $text);
        self::assertStringContainsString("did not complete (1), each in the workspace named (0 is live); whether each change is stored has to be checked:\n- pages:8 in workspace 3 (created)", $text);
        self::assertStringContainsString('may have written records this list does not name', $text);
        self::assertStringNotContainsString('pages:7', $text, 'the changes of a call that succeeded are not listed');
    }

    /**
     * Sends a request for the default-language page, runs it to the approval
     * of `update_page_metadata` and approves it.
     *
     * @param class-string|null $hook a DataHandler hook registered for the run
     *
     * @return array{0: AgentRun, 1: string}
     */
    private function approvedWrite(?string $hook = null): array
    {
        if ($hook !== null) {
            $this->registerHook('processDatamapClass', $hook);
        }

        $this->db('be_groups')->update('be_groups', ['tables_select' => 'pages', 'tables_modify' => 'pages', 'non_exclude_fields' => 'pages:description'], ['uid' => self::GROUP]);
        $taskId = self::text($this->send(['page' => self::PAGE, 'language' => 0, 'fields' => ['description']])['id'] ?? '');
        $run    = $this->runOf($taskId);

        $this->responses = [
            $this->callOf('get_page_content', ['uid' => self::PAGE, 'language' => 0]),
            $this->callOf('check_page_metadata', ['uid' => self::PAGE, 'description' => 'Eine kurze Beschreibung.']),
            $this->callOf('update_page_metadata', ['uid' => self::PAGE, 'description' => 'Eine kurze Beschreibung.']),
        ];
        $runtime = $this->runtime();
        $runtime->runQueued($run->uuid);

        $waiting = $this->stored($run);
        self::assertSame(AgentRunStatus::WAITING_FOR_APPROVAL, $waiting->statusEnum());

        $this->responses = [new CompletionResponse('Done.', 'fake', UsageStatistics::fromTokens(1, 1))];
        $state           = SuspendedRunState::fromArray(self::objectOf(json_decode($waiting->suspendedState ?? '', true)));
        $result          = $runtime->approve(AiActorContext::backendUser(1, isAdmin: true), $run->uuid, new ApprovalDecision(true, 1, (new PendingTurnDigest())->forState($state)));
        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome, (string) $result->error?->getMessage());

        return [$run, $taskId];
    }

    private function draftDescription(): mixed
    {
        return $this->draftOf(self::PAGE);
    }

    private function draftOf(int $page): mixed
    {
        return $this->db('pages')->select(['description'], 'pages', ['t3ver_oid' => $page, 't3ver_wsid' => self::WORKSPACE])->fetchOne();
    }

    /**
     * The translations of the page and their workspace versions, as stored.
     *
     * @return list<array<string, mixed>>
     */
    private function rowsOfTheTranslations(): array
    {
        return $this->db('pages')
            ->executeQuery('SELECT * FROM pages WHERE l10n_parent = ? OR t3ver_oid IN (?, ?) ORDER BY uid', [self::PAGE, self::TRANSLATION, self::FOREIGN_LANGUAGE_TRANSLATION])
            ->fetchAllAssociative();
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
}
