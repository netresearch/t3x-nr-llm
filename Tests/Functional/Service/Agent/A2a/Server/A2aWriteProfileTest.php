<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Event\AfterAiRecordWrittenEvent;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSendMessageHandler;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aServerRecordSaveHook;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskStore;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aWriteRecorder;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The first write capability of the server role (spec 051 A2A-MT5, A2A-MT6):
 * a writing profile writes drafts only, into the client's pinned workspace,
 * and its result lists the writes that actually ran.
 */
#[CoversClass(A2aServerRecordSaveHook::class)]
#[CoversClass(A2aSendMessageHandler::class)]
#[CoversClass(A2aWriteRecorder::class)]
#[CoversClass(A2aTaskStore::class)]
final class A2aWriteProfileTest extends AbstractFunctionalTestCase
{
    use A2aServerFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServer();
    }

    protected function tearDown(): void
    {
        $this->serverSettings(null);
        $this->getService(CacheManager::class)->getCache('ratelimiter')->flush();
        parent::tearDown();
    }

    /**
     * A2A-MT5 at configuration time: a profile a live client may call cannot
     * be made writing, and a live client cannot be given a writing profile.
     * Pinned to a draft workspace, both are stored.
     */
    #[Test]
    public function aWritingProfileAndALiveClientDoNotGoTogether(): void
    {
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->createFromUserPreferences($this->setUpBackendUser(1));

        $this->dataHandler(['tx_nrllm_a2a_profile' => [1 => ['may_write' => 1]]]);
        self::assertSame(0, $this->mayWrite(1), 'client 1 is live and may call profile 1');

        $this->db('tx_nrllm_a2a_client')->update('tx_nrllm_a2a_client', ['workspace' => 3], ['uid' => 1]);
        $this->db('tx_nrllm_a2a_client')->update('tx_nrllm_a2a_client', ['workspace' => 3], ['uid' => 2]);
        $this->db('tx_nrllm_a2a_client')->update('tx_nrllm_a2a_client', ['workspace' => 3], ['uid' => 4]);
        $this->dataHandler(['tx_nrllm_a2a_profile' => [1 => ['may_write' => 1]]]);
        self::assertSame(1, $this->mayWrite(1));

        $this->dataHandler(['tx_nrllm_a2a_client' => [3 => ['profiles' => '4,1']]]);
        self::assertSame('4', $this->db('tx_nrllm_a2a_client')->select(['profiles'], 'tx_nrllm_a2a_client', ['uid' => 3])->fetchOne(), 'a live client gets no writing profile');

        $this->dataHandler(['tx_nrllm_a2a_client' => [1 => ['workspace' => 0]]]);
        self::assertSame(3, (int) $this->db('tx_nrllm_a2a_client')->select(['workspace'], 'tx_nrllm_a2a_client', ['uid' => 1])->fetchOne(), 'a writing client is not moved to live');
    }

    /**
     * A2A-MT5 at acceptance: a writing profile called by a live client is
     * refused before a task exists; in a draft workspace its run is queued
     * with the profile's writing tools.
     */
    #[Test]
    public function aWritingProfileRunsOnlyInADraftWorkspace(): void
    {
        $this->db('tx_nrllm_a2a_profile')->update('tx_nrllm_a2a_profile', ['may_write' => 1, 'tools' => 'get_page_content,update_content_element'], ['uid' => 1]);

        $live = $this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]]);
        self::assertSame([-32004, 'UNSUPPORTED_OPERATION'], array_slice(array_values($this->errorOf($live)), 0, 2));
        self::assertSame(0, $this->rows('tx_nrllm_a2a_task'));

        $this->db('tx_nrllm_a2a_client')->update('tx_nrllm_a2a_client', ['workspace' => 3], ['uid' => 1]);
        $task    = $this->taskOf($this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]]));
        $request = $this->queuedRequestOf(self::text($task['id'] ?? ''));
        self::assertSame(['get_page_content', 'update_content_element'], $request['allowedToolNames'] ?? null);
        self::assertSame(3, self::j($request, 'actor.workspaceId'));

        $this->db('tx_nrllm_a2a_profile')->update('tx_nrllm_a2a_profile', ['may_write' => 0], ['uid' => 1]);
        $readOnly = $this->taskOf($this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]]));
        self::assertSame(['get_page_content'], $this->queuedRequestOf(self::text($readOnly['id'] ?? ''))['allowedToolNames'] ?? null);
    }

    /**
     * A2A-MT6: the artifact of a writing task lists the writes the write
     * events announced, with table, uid, workspace and kind, and only those;
     * the model's answer is not the source. A run with no write says so.
     */
    #[Test]
    public function theResultOfAWritingTaskNamesTheExecutedWrites(): void
    {
        $this->db('tx_nrllm_a2a_profile')->update('tx_nrllm_a2a_profile', ['may_write' => 1], ['uid' => 1]);
        $this->db('tx_nrllm_a2a_client')->update('tx_nrllm_a2a_client', ['workspace' => 3], ['uid' => 1]);

        $wrote = $this->taskOf($this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]]));
        $run   = $this->runOf(self::text($wrote['id'] ?? ''));
        $this->claim($run);
        $events = $this->getService(EventDispatcherInterface::class);
        $events->dispatch(new AfterAiRecordWrittenEvent($run->uuid, new RecordReference('tt_content', 12), WriteKind::UPDATED, 3));
        $this->writeStep($run);
        $this->complete($run, 'I updated tt_content 12 and pages 4.');

        $task = $this->taskOf($this->call('GetTask', ['id' => $wrote['id']]));
        self::assertSame('TASK_STATE_COMPLETED', self::j($task, 'status.state'));
        self::assertSame('writes', self::j($task, 'artifacts.1.name'));
        self::assertSame("Writes executed (1), each in the workspace named (0 is live):\n- tt_content:12 in workspace 3 (updated)", self::j($task, 'artifacts.1.parts.0.text'));

        $none = $this->taskOf($this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]]));
        $idle = $this->runOf(self::text($none['id'] ?? ''));
        $this->claim($idle);
        $this->complete($idle, 'I updated everything.');
        self::assertSame('No write was executed.', self::j($this->taskOf($this->call('GetTask', ['id' => $none['id']])), 'artifacts.1.parts.0.text'));

        $this->db('tx_nrllm_a2a_profile')->update('tx_nrllm_a2a_profile', ['may_write' => 0], ['uid' => 1]);
        $readOnly = $this->taskOf($this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]]));
        $plain    = $this->runOf(self::text($readOnly['id'] ?? ''));
        $this->claim($plain);
        $this->complete($plain, 'Summary.');
        self::assertNull(self::j($this->taskOf($this->call('GetTask', ['id' => $readOnly['id']])), 'artifacts.1'), 'a read-only task carries no write list');
    }

    /**
     * A2A-MT6: "may write" is cleared while a segment that already runs goes
     * on. Its writes were executed and are still the client's to read, so the
     * artifact is kept although the profile no longer writes.
     */
    #[Test]
    public function theWritesOfARunSurviveTheProfileLosingMayWrite(): void
    {
        $this->db('tx_nrllm_a2a_profile')->update('tx_nrllm_a2a_profile', ['may_write' => 1], ['uid' => 1]);
        $this->db('tx_nrllm_a2a_client')->update('tx_nrllm_a2a_client', ['workspace' => 3], ['uid' => 1]);
        $task = $this->taskOf($this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]]));
        $run  = $this->runOf(self::text($task['id'] ?? ''));
        $this->claim($run);
        $this->getService(EventDispatcherInterface::class)->dispatch(new AfterAiRecordWrittenEvent($run->uuid, new RecordReference('tt_content', 12), WriteKind::UPDATED, 3));
        $this->writeStep($run);

        $this->db('tx_nrllm_a2a_profile')->update('tx_nrllm_a2a_profile', ['may_write' => 0], ['uid' => 1]);
        $this->complete($run, 'done');

        $result = $this->taskOf($this->call('GetTask', ['id' => $task['id']]));
        self::assertSame('writes', self::j($result, 'artifacts.1.name'));
        self::assertSame("Writes executed (1), each in the workspace named (0 is live):\n- tt_content:12 in workspace 3 (updated)", self::j($result, 'artifacts.1.parts.0.text'));
    }

    /**
     * A2A-MT6: when the run recorded more write steps than write events were
     * kept, the list says it may be incomplete instead of looking complete.
     */
    #[Test]
    public function aWriteListThatMissesAStepSaysSo(): void
    {
        $this->db('tx_nrllm_a2a_profile')->update('tx_nrllm_a2a_profile', ['may_write' => 1], ['uid' => 1]);
        $this->db('tx_nrllm_a2a_client')->update('tx_nrllm_a2a_client', ['workspace' => 3], ['uid' => 1]);
        $task = $this->taskOf($this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]]));
        $run  = $this->runOf(self::text($task['id'] ?? ''));
        $this->claim($run);
        $this->writeStep($run);
        $this->complete($run, 'done');

        self::assertSame("No write was executed.\nThe run recorded 1 write step(s); this list may be incomplete.", self::j($this->taskOf($this->call('GetTask', ['id' => $task['id']])), 'artifacts.1.parts.0.text'));
    }

    // ------------------------------------------------------------------

    private function mayWrite(int $profile): int
    {
        return (int) $this->db('tx_nrllm_a2a_profile')->select(['may_write'], 'tx_nrllm_a2a_profile', ['uid' => $profile])->fetchOne();
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $data
     */
    private function dataHandler(array $data): void
    {
        $handler = GeneralUtility::makeInstance(DataHandler::class);
        $handler->start($data, []);
        $handler->process_datamap();
    }

    /**
     * @return array<string, mixed>
     */
    private function queuedRequestOf(string $taskId): array
    {
        $stored  = $this->db('tx_nrllm_agentrun')->select(['queued_request'], 'tx_nrllm_agentrun', ['uid' => $this->runOf($taskId)->uid])->fetchOne();
        $decoded = json_decode($this->getService(AgentStateCodec::class)->decode(self::text($stored), AgentStateCodec::PURPOSE_QUEUED_REQUEST), true);

        return self::objectOf($decoded);
    }

    private function runOf(string $taskId): AgentRun
    {
        $uid  = $this->db(A2aTaskStore::TABLE)->select(['run_uid'], A2aTaskStore::TABLE, ['task_id' => $taskId])->fetchOne();
        $uuid = $this->db('tx_nrllm_agentrun')->select(['uuid'], 'tx_nrllm_agentrun', ['uid' => $uid])->fetchOne();
        self::assertIsString($uuid);
        $run = $this->getService(AgentRunPersister::class)->findRun($uuid);
        self::assertInstanceOf(AgentRun::class, $run);

        return $run;
    }

    private function claim(AgentRun $run): void
    {
        self::assertTrue($this->getService(AgentRunPersister::class)->claimQueued($run, 'worker-t', time() + 300));
    }

    private function writeStep(AgentRun $run): void
    {
        $this->db('tx_nrllm_agentrun_event')->insert('tx_nrllm_agentrun_event', ['run' => $run->uid, 'sequence' => 9, 'kind' => 'tool_write', 'payload' => '{}']);
    }

    private function complete(AgentRun $run, string $answer): void
    {
        self::assertTrue($this->getService(AgentRunPersister::class)->settleCompleted(new AgentRunHandle($run->uid, $run->uuid), new ToolLoopResult($answer, [], 1, false, UsageStatistics::fromTokens(1, 1)), 'worker-t'));
    }
}
