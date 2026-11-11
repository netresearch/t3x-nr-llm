<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aListCursor;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aRpcServer;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aTaskView;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskStore;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\CacheManager;

/**
 * GetTask, ListTasks and CancelTask of the server role (spec 051 A2A-SV6,
 * A2A-SEC2, A2A-D7), with the owner in every query.
 */
#[CoversClass(A2aRpcServer::class)]
#[CoversClass(A2aTaskView::class)]
#[CoversClass(A2aListCursor::class)]
final class A2aTaskMethodsTest extends AbstractFunctionalTestCase
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
     * A2A-SEC2: another client's task and a task that does not exist give
     * byte-identical answers on GetTask and CancelTask.
     */
    #[Test]
    public function anotherClientsTaskIsIndistinguishableFromAnUnknownOne(): void
    {
        $theirs = $this->taskOf($this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]], 4));

        foreach (['GetTask', 'CancelTask'] as $method) {
            $foreign = $this->call($method, ['id' => $theirs['id']], 1);
            $unknown = $this->call($method, ['id' => '00000000-0000-4000-8000-000000000000'], 1);
            self::assertSame(-32001, self::j($this->errorOf($foreign), 'code'), $method);
            self::assertSame(json_encode($foreign), json_encode($unknown), $method);
        }

        self::assertSame('TASK_STATE_SUBMITTED', self::j($this->taskOf($this->call('GetTask', ['id' => $theirs['id']], 4)), 'status.state'), 'the owner still sees it, uncancelled');
    }

    /**
     * A2A-SV6: historyLength unset returns the whole history, 0 omits it, n
     * returns the n most recent.
     */
    #[Test]
    public function getTaskAppliesTheHistoryLength(): void
    {
        $task = $this->taskOf($this->call('SendMessage', ['message' => $this->message('first'), 'configuration' => ['returnImmediately' => true]]));
        $this->appendHistory($task['id'], ['messageId' => 'm-2', 'role' => 'ROLE_AGENT', 'parts' => [['text' => 'second']]]);

        self::assertSame(['first', 'second'], $this->texts($this->taskOf($this->call('GetTask', ['id' => $task['id']]))));
        self::assertArrayNotHasKey('history', $this->taskOf($this->call('GetTask', ['id' => $task['id'], 'historyLength' => 0])));
        self::assertSame(['second'], $this->texts($this->taskOf($this->call('GetTask', ['id' => $task['id'], 'historyLength' => 1]))));
        self::assertSame(-32602, self::j($this->errorOf($this->call('GetTask', ['id' => $task['id'], 'historyLength' => -1])), 'code'));
        self::assertArrayNotHasKey('history', $this->taskOf($this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true, 'historyLength' => 0]])));
    }

    /**
     * A2A-SV6: CancelTask on a non-terminal task cancels its run and answers
     * CANCELED; on a terminal one -32002.
     */
    #[Test]
    public function cancelTaskCancelsANonTerminalTaskOnly(): void
    {
        $task = $this->taskOf($this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]]));

        $cancelled = $this->taskOf($this->call('CancelTask', ['id' => $task['id']]));

        self::assertSame('TASK_STATE_CANCELED', self::j($cancelled, 'status.state'));
        self::assertSame('cancelled', $this->db('tx_nrllm_agentrun')->select(['status'], 'tx_nrllm_agentrun', ['uid' => self::j($this->taskRow($task['id']), 'run_uid')])->fetchOne());
        self::assertSame(-32002, self::j($this->errorOf($this->call('CancelTask', ['id' => $task['id']])), 'code'));
    }

    /**
     * The TCK's CORE-CANCEL-001 shape: a task waiting for input is
     * cancellable.
     */
    #[Test]
    public function aTaskWaitingForInputIsCancellable(): void
    {
        $task = $this->taskOf($this->call('SendMessage', ['message' => $this->message('hi', 'tck-input-required-x')], 3));

        self::assertSame('TASK_STATE_CANCELED', self::j($this->taskOf($this->call('CancelTask', ['id' => $task['id']], 3)), 'status.state'));
    }

    /**
     * A2A-SV6, A2A-D7: only the caller's tasks, newest status first, ties by
     * task id; totalSize counts the caller's matching tasks; a cursor pages
     * through, and nextPageToken is "" on the last page.
     */
    #[Test]
    public function listTasksPagesThroughTheCallersTasksOnly(): void
    {
        $mine = [];
        for ($i = 0; $i < 3; ++$i) {
            $mine[] = self::j($this->taskOf($this->call('SendMessage', ['message' => $this->message('t' . $i), 'configuration' => ['returnImmediately' => true]])), 'id');
        }

        $this->taskOf($this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]], 4));
        foreach ($mine as $index => $taskId) {
            $this->db(A2aTaskStore::TABLE)->update(A2aTaskStore::TABLE, ['status_timestamp' => 1000 + $index], ['task_id' => $taskId]);
        }

        $first = self::j($this->call('ListTasks', ['pageSize' => 2]), 'result') ?? [];
        self::assertSame([$mine[2], $mine[1]], array_column(self::rowsOf(self::j($first, 'tasks')), 'id'));
        self::assertSame([2, 3], [self::j($first, 'pageSize'), self::j($first, 'totalSize')]);
        self::assertNotSame('', self::j($first, 'nextPageToken'));

        $second = self::j($this->call('ListTasks', ['pageSize' => 2, 'pageToken' => self::j($first, 'nextPageToken')]), 'result') ?? [];
        self::assertSame([$mine[0]], array_column(self::rowsOf(self::j($second, 'tasks')), 'id'));
        self::assertSame('', self::j($second, 'nextPageToken'));
        self::assertSame(3, self::j($second, 'totalSize'));
    }

    /**
     * A2A-SV6 [B11]: a cursor is bound to the principal, the profile set and
     * the filter.
     */
    #[Test]
    public function aCursorUsedWithAnotherFilterOrPrincipalIsRefused(): void
    {
        for ($i = 0; $i < 2; ++$i) {
            $this->taskOf($this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]]));
        }

        $token = self::j($this->call('ListTasks', ['pageSize' => 1]), 'result.nextPageToken') ?? '';
        self::assertNotSame('', $token);

        self::assertSame(-32602, self::j($this->errorOf($this->call('ListTasks', ['pageSize' => 1, 'pageToken' => $token, 'status' => 'TASK_STATE_SUBMITTED'])), 'code'));
        self::assertSame(-32602, self::j($this->errorOf($this->call('ListTasks', ['pageSize' => 1, 'pageToken' => $token], 4)), 'code'));
        self::assertSame(-32602, self::j($this->errorOf($this->call('ListTasks', ['pageSize' => 1, 'pageToken' => self::text($token) . 'x'])), 'code'));
        $this->db('tx_nrllm_a2a_client')->update('tx_nrllm_a2a_client', ['profiles' => '1,2'], ['uid' => 1]);
        self::assertSame(-32602, self::j($this->errorOf($this->call('ListTasks', ['pageSize' => 1, 'pageToken' => $token])), 'code'), 'the profile set changed');
    }

    /**
     * A2A-SV6: the filters, the page size range, and no `artifacts` member
     * unless includeArtifacts is true.
     */
    #[Test]
    public function listTasksFiltersAndOmitsArtifactsByDefault(): void
    {
        $completed = $this->taskOf($this->call('SendMessage', ['message' => $this->message('hi', 'tck-complete-task-list')], 3));
        $this->taskOf($this->call('SendMessage', ['message' => $this->message('hi', 'tck-input-required-list')], 3));

        $list = self::j($this->call('ListTasks', ['status' => 'TASK_STATE_COMPLETED'], 3), 'result') ?? [];
        self::assertSame([$completed['id']], array_column(self::rowsOf(self::j($list, 'tasks')), 'id'));
        self::assertArrayNotHasKey('artifacts', self::j($list, 'tasks.0'));

        $withArtifacts = self::j($this->call('ListTasks', ['status' => 'TASK_STATE_COMPLETED', 'includeArtifacts' => true], 3), 'result') ?? [];
        self::assertSame('Hello from TCK', self::j($withArtifacts, 'tasks.0.artifacts.0.parts.0.text'));

        self::assertSame(1, self::j($this->call('ListTasks', ['contextId' => $completed['contextId']], 3), 'result.totalSize'));
        self::assertSame(0, self::j($this->call('ListTasks', ['statusTimestampAfter' => '2999-01-01T00:00:00Z'], 3), 'result.totalSize'));
        self::assertSame(2, self::j($this->call('ListTasks', ['statusTimestampAfter' => '2000-01-01T00:00:00Z'], 3), 'result.totalSize'));

        foreach ([0, 101] as $size) {
            self::assertSame(-32602, self::j($this->errorOf($this->call('ListTasks', ['pageSize' => $size], 3)), 'code'));
        }

        self::assertSame(-32602, self::j($this->errorOf($this->call('ListTasks', ['status' => 'DONE'], 3)), 'code'));
        self::assertSame(50, self::j($this->call('ListTasks', [], 3), 'result.pageSize'));
    }

    /**
     * @return array<string, mixed>
     */
    private function taskRow(string $taskId): array
    {
        $row = $this->db(A2aTaskStore::TABLE)->select(['*'], A2aTaskStore::TABLE, ['task_id' => $taskId])->fetchAssociative();
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function appendHistory(string $taskId, array $message): void
    {
        $codec   = $this->getService(AgentStateCodec::class);
        $row     = $this->taskRow($taskId);
        $history = json_decode($codec->decode(self::text($row['history']), AgentStateCodec::PURPOSE_A2A_TASK_CONTENT), true);
        self::assertIsArray($history);
        $history[] = $message;
        $this->db(A2aTaskStore::TABLE)->update(A2aTaskStore::TABLE, ['history' => $codec->encode(json_encode($history, JSON_THROW_ON_ERROR), AgentStateCodec::PURPOSE_A2A_TASK_CONTENT)], ['task_id' => $taskId]);
    }

    /**
     * @param array<string, mixed> $task
     *
     * @return list<string>
     */
    private function texts(array $task): array
    {
        return array_map(static fn (mixed $message): string => self::text(self::j($message, 'parts.0.text')), self::rowsOf($task['history'] ?? []));
    }
}
