<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a\Server;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSendMessageHandler;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskStore;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\AgentRunRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Throwable;
use TYPO3\CMS\Core\Cache\CacheManager;

/**
 * SendMessage of the server role (spec 051 A2A-SV4 to A2A-SV10): profile
 * selection, transactional acceptance, dedup, contexts, capacity and the
 * blocking wait.
 */
#[CoversClass(A2aSendMessageHandler::class)]
final class A2aSendMessageTest extends AbstractFunctionalTestCase
{
    use A2aServerFixture;

    private const EXTENSION = 'https://nr-llm.netresearch.de/a2a/ext/profile/v1';

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
     * A2A-SV5, A2A-SV7: the dedup record, the task, the run and the binding
     * exist after a return-immediately call; the run is QUEUED as the
     * client's technical backend user and nothing executed it.
     */
    #[Test]
    public function anAcceptedTaskIsQueuedAsTheTechnicalUser(): void
    {
        $task = $this->taskOf($this->call('SendMessage', ['message' => $this->message('Summarise this.', 'm-1'), 'configuration' => ['returnImmediately' => true]]));

        self::assertSame('TASK_STATE_SUBMITTED', self::j($task, 'status.state'));
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', self::j($task, 'status.timestamp'));
        self::assertNotSame('', $task['contextId']);
        self::assertSame('Summarise this.', self::j($task, 'history.0.parts.0.text'));
        self::assertSame($task['id'], self::j($task, 'history.0.taskId'));

        $row = $this->taskRow($task['id']);
        self::assertSame([1, 1], [(int) $row['owner_client_uid'], (int) $row['profile_uid']]);
        self::assertSame(1, $this->rows('tx_nrllm_a2a_dedup', ['owner_client_uid' => 1, 'message_id' => 'm-1', 'task_uid' => $row['uid']]));
        $run = $this->runOfTask($row);
        self::assertSame([AgentRunStatus::QUEUED, 7], [$run->statusEnum(), $run->beUser]);
        self::assertStringNotContainsString('Summarise this.', self::text($row['history']), 'the history is sealed');
    }

    /**
     * A2A-SV5: a failure inside the acceptance transaction leaves no dedup
     * record, no task and no run, and the client gets -32603.
     */
    #[Test]
    public function aFailedAcceptanceLeavesNothing(): void
    {
        $this->failInDatabase('a2a_force_dedup', 'tx_nrllm_a2a_dedup', 'INSERT', '1 = 1');

        $reply = $this->call('SendMessage', ['message' => $this->message('Summarise this.', 'm-fail'), 'configuration' => ['returnImmediately' => true]]);
        $this->dropTrigger('a2a_force_dedup', 'tx_nrllm_a2a_dedup');

        self::assertSame(-32603, self::j($this->errorOf($reply), 'code'));
        self::assertSame([0, 0, 0], [$this->rows('tx_nrllm_a2a_dedup'), $this->rows('tx_nrllm_a2a_task'), $this->rows('tx_nrllm_agentrun')]);
    }

    /**
     * A2A-SV6: messageIds that differ only in case are two ids, also under a
     * case-insensitive collation (MariaDB, MySQL).
     */
    #[Test]
    public function messageIdsThatDifferInCaseAreTwoMessages(): void
    {
        $first  = $this->taskOf($this->call('SendMessage', ['message' => $this->message('First text.', 'Message-A'), 'configuration' => ['returnImmediately' => true]]));
        $second = $this->taskOf($this->call('SendMessage', ['message' => $this->message('Second text.', 'message-a'), 'configuration' => ['returnImmediately' => true]]));

        self::assertNotSame($first['id'], $second['id']);
        self::assertSame(2, $this->rows('tx_nrllm_a2a_dedup'));
    }

    /**
     * A2A-SV6: the same messageId returns the first task and creates
     * nothing; with another message it is -32602.
     */
    #[Test]
    public function aRepeatedMessageIdReturnsTheFirstTask(): void
    {
        $params = ['message' => $this->message('Summarise this.', 'm-dup'), 'configuration' => ['returnImmediately' => true]];
        $first  = $this->taskOf($this->call('SendMessage', $params));

        $again = $this->taskOf($this->call('SendMessage', $params));

        self::assertSame(self::j($first, 'id'), $again['id']);
        self::assertSame([1, 1], [$this->rows('tx_nrllm_a2a_task'), $this->rows('tx_nrllm_agentrun')]);
        self::assertSame(-32602, self::j($this->errorOf($this->call('SendMessage', ['message' => $this->message('Something else.', 'm-dup'), 'configuration' => ['returnImmediately' => true]])), 'code'));
        // Another client may use the same messageId for a task of its own.
        $theirs = $this->taskOf($this->call('SendMessage', $params, 4));
        self::assertNotSame(self::j($first, 'id'), $theirs['id']);
    }

    /**
     * The dedup record goes with its task when the run purge removes it
     * (A2A-SV6: "within a task's retention").
     */
    #[Test]
    public function theDedupRecordIsPurgedWithItsTask(): void
    {
        $this->taskOf($this->call('SendMessage', ['message' => $this->message('Summarise this.', 'm-purge'), 'configuration' => ['returnImmediately' => true]]));
        $this->db('tx_nrllm_agentrun')->update('tx_nrllm_agentrun', ['crdate' => 1], []);

        // The run is still queued: the purge of unfinished runs removes it.
        $this->getService(AgentRunRepository::class)->purgeUnfinishedOlderThan(1000);

        self::assertSame([0, 0], [$this->rows('tx_nrllm_a2a_task'), $this->rows('tx_nrllm_a2a_dedup')]);
    }

    /**
     * A2A-SV6: a purge that fails at the dedup record leaves the task, so the
     * next purge reaches the record again; no record is left behind reserving
     * the messageId for a task that is gone.
     */
    #[Test]
    public function aFailedDedupPurgeLeavesTheTaskForTheNextPurge(): void
    {
        $this->taskOf($this->call('SendMessage', ['message' => $this->message('Summarise this.', 'm-purge-retry'), 'configuration' => ['returnImmediately' => true]]));
        $this->db('tx_nrllm_agentrun')->update('tx_nrllm_agentrun', ['crdate' => 1], []);

        $this->failInDatabase('a2a_force_dedup_delete', 'tx_nrllm_a2a_dedup', 'DELETE', '1 = 1');
        $failed = false;
        try {
            $this->getService(AgentRunRepository::class)->purgeUnfinishedOlderThan(1000);
        } catch (Throwable) {
            $failed = true;
        } finally {
            $this->dropTrigger('a2a_force_dedup_delete', 'tx_nrllm_a2a_dedup');
        }

        self::assertTrue($failed, 'the forced failure stopped the purge');
        self::assertSame([1, 1], [$this->rows('tx_nrllm_a2a_task'), $this->rows('tx_nrllm_a2a_dedup')], 'the task waits for the next purge');

        $this->getService(AgentRunRepository::class)->purgeUnfinishedOlderThan(1000);
        self::assertSame([0, 0], [$this->rows('tx_nrllm_a2a_task'), $this->rows('tx_nrllm_a2a_dedup')]);
    }

    /**
     * A2A-SV4a: a named skill must be one the client may call; without a
     * name, one allowed profile is chosen, several are PROFILE_REQUIRED.
     */
    #[Test]
    public function theProfileIsSelectedByTheClientRecordOnly(): void
    {
        $named = $this->taskOf($this->call('SendMessage', ['message' => $this->message(), 'metadata' => [self::EXTENSION => 'check'], 'configuration' => ['returnImmediately' => true]], 2));
        self::assertSame(2, (int) self::j($this->taskRow($named['id']), 'profile_uid'));

        $notAllowed = $this->call('SendMessage', ['message' => $this->message(), 'metadata' => [self::EXTENSION => 'check'], 'configuration' => ['returnImmediately' => true]], 1);
        self::assertSame([-32602, 'PROFILE_NOT_ALLOWED'], array_slice(array_values($this->errorOf($notAllowed)), 0, 2));

        $unknown = $this->call('SendMessage', ['message' => $this->message(), 'metadata' => [self::EXTENSION => 'nope'], 'configuration' => ['returnImmediately' => true]], 1);
        self::assertSame('PROFILE_NOT_ALLOWED', self::j($this->errorOf($unknown), 'reason'));

        $required = $this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]], 2);
        self::assertSame([-32602, 'PROFILE_REQUIRED'], array_slice(array_values($this->errorOf($required)), 0, 2));
        self::assertSame('metadata["' . self::EXTENSION . '"]', self::j($required, 'error.data.1.fieldViolations.0.field'));

        $single = $this->taskOf($this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]], 1));
        self::assertSame(1, (int) self::j($this->taskRow($single['id']), 'profile_uid'));
        self::assertSame(2, $this->rows('tx_nrllm_a2a_task'));
    }

    /**
     * A2A-SV4a: a profile that is not public cannot be called, even when the
     * client record lists it.
     */
    #[Test]
    public function aProfileThatIsNotPublicCannotBeCalled(): void
    {
        $reply = $this->call('SendMessage', ['message' => $this->message(), 'metadata' => [self::EXTENSION => 'internal'], 'configuration' => ['returnImmediately' => true]], 4);

        self::assertSame('PROFILE_NOT_ALLOWED', self::j($this->errorOf($reply), 'reason'));
    }

    /**
     * A2A-SV4a, A2A-SV6: a message naming a task is checked like a
     * continuation and then refused in stage 3.
     */
    #[Test]
    public function aMessageNamingATaskIsCheckedAndRefused(): void
    {
        $task = $this->taskOf($this->call('SendMessage', ['message' => $this->message(), 'metadata' => [self::EXTENSION => 'summary'], 'configuration' => ['returnImmediately' => true]], 2));

        $mismatch = $this->call('SendMessage', ['message' => $this->message('More.', '', ['taskId' => $task['id']]), 'metadata' => [self::EXTENSION => 'check']], 2);
        self::assertSame([-32602, 'PROFILE_MISMATCH'], array_slice(array_values($this->errorOf($mismatch)), 0, 2));

        $otherContext = $this->call('SendMessage', ['message' => $this->message('More.', '', ['taskId' => $task['id'], 'contextId' => 'another'])], 2);
        self::assertSame(-32602, self::j($this->errorOf($otherContext), 'code'));

        $theirs = $this->call('SendMessage', ['message' => $this->message('More.', '', ['taskId' => $task['id']])], 1);
        self::assertSame(-32001, self::j($this->errorOf($theirs), 'code'));

        $open = $this->call('SendMessage', ['message' => $this->message('More.', '', ['taskId' => $task['id']])], 2);
        self::assertSame([-32004, 'CONTINUATION_NOT_SUPPORTED'], array_slice(array_values($this->errorOf($open)), 0, 2));

        $this->getService(AgentRunPersister::class)->cancel($this->runOfTask($this->taskRow($task['id']))->uuid);
        $terminal = $this->call('SendMessage', ['message' => $this->message('More.', '', ['taskId' => $task['id']])], 2);
        self::assertSame([-32004, 'UNSUPPORTED_OPERATION'], array_slice(array_values($this->errorOf($terminal)), 0, 2));
        self::assertSame(1, $this->rows('tx_nrllm_a2a_task'));
    }

    /**
     * A2A-SV6: a client-supplied context is accepted when the client owns it
     * or it is a new UUID.
     */
    #[Test]
    public function aClientSuppliedContextMustBeOwnedOrNew(): void
    {
        $new   = '0193a1c6-6c1e-7cc2-9d55-6a1f2b3c4d5e';
        $first = $this->taskOf($this->call('SendMessage', ['message' => $this->message('a', '', ['contextId' => $new]), 'configuration' => ['returnImmediately' => true]], 1));
        self::assertSame($new, self::j($first, 'contextId'));

        $again = $this->taskOf($this->call('SendMessage', ['message' => $this->message('b', '', ['contextId' => $new]), 'configuration' => ['returnImmediately' => true]], 1));
        self::assertSame($new, $again['contextId']);

        self::assertSame(-32602, self::j($this->errorOf($this->call('SendMessage', ['message' => $this->message('c', '', ['contextId' => $new]), 'configuration' => ['returnImmediately' => true]], 2)), 'code'), "another client's context");
        self::assertSame(-32602, self::j($this->errorOf($this->call('SendMessage', ['message' => $this->message('d', '', ['contextId' => 'not-a-uuid']), 'configuration' => ['returnImmediately' => true]], 1)), 'code'));
    }

    /**
     * ADR-282, A2A-SV4: a profile whose configuration keeps no result is
     * refused before a task exists, blocking calls included.
     */
    #[Test]
    public function aProfileThatCannotKeepAResultIsRefusedBeforeATaskExists(): void
    {
        $this->db('tx_nrllm_provider')->update('tx_nrllm_provider', ['privacy_level' => 'metadata'], ['uid' => 1]);

        foreach ([true, false] as $returnImmediately) {
            $reply = $this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => $returnImmediately]]);
            self::assertSame([-32004, 'RESULT_NOT_RETAINABLE'], array_slice(array_values($this->errorOf($reply)), 0, 2));
            self::assertSame('nr-llm.netresearch.de', self::j($reply, 'error.data.0.domain'));
        }

        self::assertSame(0, $this->rows('tx_nrllm_a2a_task'));
    }

    /**
     * A configuration that is switched off, or restricted to backend groups
     * the client's technical user is not in, is not run for the client: the
     * call is refused before a task exists.
     */
    #[Test]
    public function aConfigurationTheTechnicalUserMayNotUseIsRefused(): void
    {
        $this->db('be_groups')->insert('be_groups', ['uid' => 40, 'pid' => 0, 'title' => 'LLM users']);
        $this->db('tx_nrllm_configuration_begroups_mm')->insert('tx_nrllm_configuration_begroups_mm', ['uid_local' => 1, 'uid_foreign' => 40, 'sorting' => 1]);
        $this->db('tx_nrllm_configuration')->update('tx_nrllm_configuration', ['allowed_groups' => 1], ['uid' => 1]);

        $reply = $this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]]);
        self::assertSame([-32004, 'PROFILE_UNAVAILABLE'], array_slice(array_values($this->errorOf($reply)), 0, 2));
        self::assertSame([0, 0], [$this->rows('tx_nrllm_a2a_task'), $this->rows('tx_nrllm_agentrun')]);
    }

    #[Test]
    public function aSwitchedOffConfigurationIsRefused(): void
    {
        $this->db('tx_nrllm_configuration')->update('tx_nrllm_configuration', ['is_active' => 0], ['uid' => 1]);

        $reply = $this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]]);
        self::assertSame([-32004, 'PROFILE_UNAVAILABLE'], array_slice(array_values($this->errorOf($reply)), 0, 2));
        self::assertSame([0, 0], [$this->rows('tx_nrllm_a2a_task'), $this->rows('tx_nrllm_agentrun')]);
    }

    /**
     * The dedup record keeps the messageId in a column of 255 characters; a
     * longer id is refused before anything is stored.
     */
    #[Test]
    public function aMessageIdLongerThanTheDedupColumnIsRefused(): void
    {
        $reply = $this->call('SendMessage', ['message' => $this->message('Summarise this.', str_repeat('m', 256)), 'configuration' => ['returnImmediately' => true]]);

        self::assertSame(-32602, self::j($this->errorOf($reply), 'code'));
        self::assertStringContainsString('message.messageId', (string) json_encode($reply));
        self::assertSame(0, $this->rows('tx_nrllm_a2a_task'));
        self::assertTrue(isset($this->taskOf($this->call('SendMessage', ['message' => $this->message('Summarise this.', str_repeat('m', 255)), 'configuration' => ['returnImmediately' => true]]))['id']), 'an id of 255 characters is accepted');
    }

    /**
     * A2A-SV10: a message the handler refuses before the run starts is a
     * REJECTED task, its run failed by policy.
     */
    #[Test]
    public function aRefusedInputIsARejectedTask(): void
    {
        $task = $this->taskOf($this->call('SendMessage', ['message' => $this->message('   '), 'configuration' => ['returnImmediately' => true]]));

        self::assertSame('TASK_STATE_REJECTED', self::j($task, 'status.state'));
        self::assertSame(AgentRunStatus::FAILED, $this->runOfTask($this->taskRow($task['id']))->statusEnum());
    }

    /**
     * A2A-SV9: without a worker shown to run nothing is stored, HTTP 503
     * with Retry-After and a RetryInfo.
     */
    #[Test]
    public function withoutALiveWorkerTheCallIsRefused(): void
    {
        $this->db('tx_nrllm_worker_heartbeat')->update('tx_nrllm_worker_heartbeat', ['last_seen' => time() - 121], ['worker_id' => 'w-1']);

        $reply = $this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]]);

        self::assertSame(['code' => -32000, 'reason' => 'WORKER_UNAVAILABLE', 'http' => 503], $this->errorOf($reply));
        self::assertNotSame('', self::j($reply, '_headers.Retry-After') ?? '');
        self::assertSame('type.googleapis.com/google.rpc.RetryInfo', self::j($reply, 'error.data.1.@type'));
        self::assertSame(0, $this->rows('tx_nrllm_a2a_task'));

        // A sweep tick counts as a worker (ADR-283).
        $this->db('tx_nrllm_worker_heartbeat')->insert('tx_nrllm_worker_heartbeat', ['worker_id' => 'sweep@x', 'transport' => 'sweep', 'last_seen' => time()]);
        self::assertSame('TASK_STATE_SUBMITTED', self::j($this->taskOf($this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]])), 'status.state'));
    }

    /**
     * A2A-SV9: the client's queued and running tasks reach its limit.
     */
    #[Test]
    public function theClientsTaskLimitRefusesOneMore(): void
    {
        $this->db('tx_nrllm_a2a_client')->update('tx_nrllm_a2a_client', ['task_limit' => 2], ['uid' => 1]);
        for ($i = 0; $i < 2; ++$i) {
            $this->taskOf($this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]]));
        }

        $reply = $this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]]);

        self::assertSame(['code' => -32000, 'reason' => 'CLIENT_CAPACITY_EXHAUSTED', 'http' => 503], $this->errorOf($reply));
        self::assertSame(2, $this->rows('tx_nrllm_a2a_task'));
        // Another client is not limited by it.
        $this->taskOf($this->call('SendMessage', ['message' => $this->message(), 'metadata' => [self::EXTENSION => 'summary'], 'configuration' => ['returnImmediately' => true]], 2));
    }

    #[Test]
    public function theProfilesQuotaRefusesOneMore(): void
    {
        $this->db('tx_nrllm_a2a_profile')->update('tx_nrllm_a2a_profile', ['max_active_tasks' => 1], ['uid' => 1]);
        $this->taskOf($this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]]));

        $reply = $this->call('SendMessage', ['message' => $this->message(), 'metadata' => [self::EXTENSION => 'summary'], 'configuration' => ['returnImmediately' => true]], 2);

        self::assertSame('PROFILE_CAPACITY_EXHAUSTED', self::j($this->errorOf($reply), 'reason'));
    }

    /**
     * A2A-SV9: the limits decide inside the acceptance transaction, under the
     * client's and the profile's row lock. A task another request committed
     * after this request's first count is simulated by a trigger on the
     * lock: it adds a SUBMITTED task the moment the row is locked. The
     * refusal rolls everything back, that task included.
     */
    #[Test]
    public function aTaskAcceptedConcurrentlyCountsAgainstTheLimits(): void
    {
        $this->db('tx_nrllm_a2a_client')->update('tx_nrllm_a2a_client', ['task_limit' => 1], ['uid' => 1]);
        $this->concurrentTaskOn('tx_nrllm_a2a_client', 'a2a_race_client');
        $reply = $this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]]);
        $this->dropTrigger('a2a_race_client', 'tx_nrllm_a2a_client');

        self::assertSame(['code' => -32000, 'reason' => 'CLIENT_CAPACITY_EXHAUSTED', 'http' => 503], $this->errorOf($reply));
        self::assertSame([0, 0, 0], [$this->rows('tx_nrllm_a2a_task'), $this->rows('tx_nrllm_agentrun'), $this->rows('tx_nrllm_a2a_dedup')]);

        $this->db('tx_nrllm_a2a_profile')->update('tx_nrllm_a2a_profile', ['max_active_tasks' => 1], ['uid' => 1]);
        $this->concurrentTaskOn('tx_nrllm_a2a_profile', 'a2a_race_profile');
        $reply = $this->call('SendMessage', ['message' => $this->message(), 'metadata' => [self::EXTENSION => 'summary'], 'configuration' => ['returnImmediately' => true]], 2);
        $this->dropTrigger('a2a_race_profile', 'tx_nrllm_a2a_profile');

        self::assertSame('PROFILE_CAPACITY_EXHAUSTED', $this->errorOf($reply)['reason']);
        self::assertSame(0, $this->rows('tx_nrllm_a2a_task'));
    }

    private function concurrentTaskOn(string $table, string $trigger): void
    {
        $insert     = "INSERT INTO tx_nrllm_a2a_task (task_id, owner_client_uid, profile_uid, run_uid, state) VALUES ('concurrent', 1, 1, 999999, 'TASK_STATE_SUBMITTED')";
        $connection = $this->db($table);
        $connection->executeStatement($connection->getDatabasePlatform() instanceof SQLitePlatform
            ? sprintf('CREATE TRIGGER %s AFTER UPDATE ON %s FOR EACH ROW BEGIN %s; END', $trigger, $table, $insert)
            : sprintf('CREATE TRIGGER %s AFTER UPDATE ON %s FOR EACH ROW %s', $trigger, $table, $insert));
    }

    /**
     * A2A-SV9: the waiting limit refuses a blocking call only; a
     * return-immediately call takes no slot.
     */
    #[Test]
    public function theWaitingLimitRefusesBlockingCallsOnly(): void
    {
        $this->serverSettings(['maxWaitingRequests' => '0'] + self::SERVER_SETTINGS);

        $blocking = $this->call('SendMessage', ['message' => $this->message()]);
        self::assertSame(['code' => -32000, 'reason' => 'WAITING_CAPACITY_EXHAUSTED', 'http' => 503], $this->errorOf($blocking));
        self::assertSame(0, $this->rows('tx_nrllm_a2a_task'));

        self::assertSame('TASK_STATE_SUBMITTED', self::j($this->taskOf($this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]])), 'status.state'));
    }

    /**
     * A2A-SV8: a blocking call never answers early with SUBMITTED or WORKING;
     * at its deadline it answers 504 with the task id, and the task stays
     * retrievable. Its waiting slot is free again afterwards.
     */
    #[Test]
    public function aBlockingCallPastItsDeadlineAnswers504WithTheTaskId(): void
    {
        $started = microtime(true);
        $reply   = $this->call('SendMessage', ['message' => $this->message()]);

        self::assertGreaterThanOrEqual(1.0, microtime(true) - $started);
        self::assertSame(['code' => -32603, 'reason' => 'BLOCKING_DEADLINE_EXCEEDED', 'http' => 504], $this->errorOf($reply));
        $taskId = self::j($reply, 'error.data.0.metadata.taskId') ?? '';
        self::assertIsString($taskId);
        self::assertSame('TASK_STATE_SUBMITTED', self::j($this->taskOf($this->call('GetTask', ['id' => $taskId])), 'status.state'));
        self::assertSame(1, $this->rows('tx_nrllm_a2a_wait_slot'));
        self::assertSame(1, $this->rows('tx_nrllm_a2a_wait_slot', ['holder' => '']), 'the slot was released');
    }

    /**
     * The TCK fixture (A2A-SV11) settles its run once the acceptance
     * committed: a blocking call returns the COMPLETED task, a message
     * response creates no task, INPUT_REQUIRED interrupts the wait.
     */
    #[Test]
    public function theTckFixtureAnswersByMessageIdPrefix(): void
    {
        $completed = $this->taskOf($this->call('SendMessage', ['message' => $this->message('hi', 'tck-complete-task-1')], 3));
        self::assertSame('TASK_STATE_COMPLETED', self::j($completed, 'status.state'));
        self::assertSame('Hello from TCK', self::j($completed, 'artifacts.0.parts.0.text'));

        $data = $this->taskOf($this->call('SendMessage', ['message' => $this->message('hi', 'tck-artifact-data-1')], 3));
        self::assertSame(['key' => 'value', 'count' => 42], self::j($data, 'artifacts.0.parts.0.data'));

        $input = $this->taskOf($this->call('SendMessage', ['message' => $this->message('hi', 'tck-input-required-1')], 3));
        self::assertSame('TASK_STATE_INPUT_REQUIRED', self::j($input, 'status.state'));

        $rejected = $this->taskOf($this->call('SendMessage', ['message' => $this->message('hi', 'tck-reject-task-1')], 3));
        self::assertSame('TASK_STATE_REJECTED', self::j($rejected, 'status.state'));

        $tasks   = $this->rows('tx_nrllm_a2a_task');
        $message = $this->call('SendMessage', ['message' => $this->message('hi', 'tck-message-response-1')], 3);
        self::assertSame('Direct message response', self::j($message, 'result.message.parts.0.text'));
        self::assertSame($tasks, $this->rows('tx_nrllm_a2a_task'));
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
     * @param array<string, mixed> $taskRow
     */
    private function runOfTask(array $taskRow): AgentRun
    {
        $uuid = $this->db('tx_nrllm_agentrun')->select(['uuid'], 'tx_nrllm_agentrun', ['uid' => $taskRow['run_uid']])->fetchOne();
        self::assertIsString($uuid);
        $run = $this->getService(AgentRunPersister::class)->findRun($uuid);
        self::assertInstanceOf(AgentRun::class, $run);

        return $run;
    }

    private function failInDatabase(string $name, string $table, string $event, string $condition): void
    {
        $connection = $this->db($table);
        if ($connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $connection->executeStatement(sprintf("CREATE TRIGGER %s BEFORE %s ON %s FOR EACH ROW WHEN %s BEGIN SELECT RAISE(ABORT, 'forced'); END", $name, $event, $table, $condition));

            return;
        }

        $connection->executeStatement(sprintf("CREATE TRIGGER %s BEFORE %s ON %s FOR EACH ROW BEGIN IF %s THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced'; END IF; END", $name, $event, $table, $condition));
    }

    private function dropTrigger(string $name, string $table): void
    {
        $connection = $this->db($table);
        $connection->executeStatement($connection->getDatabasePlatform() instanceof SQLitePlatform ? 'DROP TRIGGER ' . $name : 'DROP TRIGGER IF EXISTS ' . $name);
    }
}
