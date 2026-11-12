<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a\Server;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aResumeGuard;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSendMessageHandler;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aInputMapping;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskStore;
use Netresearch\NrLlm\Service\Agent\AgentRunExecutor;
use Netresearch\NrLlm\Service\Agent\AgentRuntime;
use Netresearch\NrLlm\Service\Agent\StoredDecision;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolver;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\Builtin\AskChoiceTool;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use ReflectionProperty;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * Multi-turn tasks of the server role (spec 051 A2A-MT1 to A2A-MT4): a run
 * that waits for input is INPUT_REQUIRED with its question, and the client's
 * next message is taken in durably and carried out by the worker.
 *
 * The worker is played through the persister here, as the TCK fixture does:
 * no model is called.
 */
#[CoversClass(A2aSendMessageHandler::class)]
#[CoversClass(A2aTaskStore::class)]
#[CoversClass(A2aResumeGuard::class)]
#[CoversClass(A2aInputMapping::class)]
final class A2aMultiTurnTest extends AbstractFunctionalTestCase
{
    use A2aServerFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServer();
        // The pauses here name ask_choice; the technical user answers it
        // only while the tool is enabled (A2A-MT2: the submitter's right).
        $this->db('tx_nrllm_tool_state')->insert('tx_nrllm_tool_state', ['tool_name' => 'ask_choice', 'enabled' => 1]);
        $this->db('tx_nrllm_a2a_profile')->update('tx_nrllm_a2a_profile', ['tools' => 'ask_choice'], ['uid' => 1]);
    }

    protected function tearDown(): void
    {
        $this->serverSettings(null);
        $this->getService(CacheManager::class)->getCache('ratelimiter')->flush();
        parent::tearDown();
    }

    /**
     * A2A-MT1: a run waiting for input is INPUT_REQUIRED, and the status
     * message carries the question and the answers it allows; a blocking
     * call returns at that interrupted state.
     */
    #[Test]
    public function aRunWaitingForInputIsInputRequiredWithItsQuestion(): void
    {
        $taskId = $this->pausedTask();

        $task = $this->taskOf($this->call('GetTask', ['id' => $taskId]));
        self::assertSame('TASK_STATE_INPUT_REQUIRED', self::j($task, 'status.state'));
        self::assertSame("Which page?\nAnswer with one of: Home | About", self::j($task, 'status.message.parts.0.text'));
        self::assertSame('ROLE_AGENT', self::j($task, 'status.message.role'));
    }

    /**
     * A2A-MT2, A2A-MT3: the client's answer names the task; the dedup
     * record, the message in the history and the stored input commit
     * together, the run is QUEUED with the decision and the task WORKING.
     * Nothing ran. A repeat returns the task and stores nothing again; the
     * same messageId with another text is refused (A2A-SV6).
     */
    #[Test]
    public function theClientsAnswerIsTakenInForTheWorker(): void
    {
        $taskId = $this->pausedTask();
        $answer = ['message' => $this->message('Home', 'm-answer', ['taskId' => $taskId]), 'configuration' => ['returnImmediately' => true]];

        $task = $this->taskOf($this->call('SendMessage', $answer));

        self::assertSame('TASK_STATE_WORKING', self::j($task, 'status.state'));
        self::assertSame(['Summarise this.', 'Home'], [self::j($task, 'history.0.parts.0.text'), self::j($task, 'history.1.parts.0.text')]);
        self::assertSame(self::j($task, 'contextId'), self::j($task, 'history.1.contextId'), 'the context is inferred from the task');
        $run = $this->runOf($taskId);
        self::assertSame(AgentRunStatus::QUEUED, $run->statusEnum());
        self::assertSame(['answer' => 'Home'], $this->decisionOf($run)['data'] ?? null);
        self::assertSame(2, $this->rows('tx_nrllm_a2a_dedup'));

        $again = $this->taskOf($this->call('SendMessage', $answer));
        self::assertSame($taskId, $again['id']);
        self::assertSame(2, $this->rows('tx_nrllm_a2a_dedup'));
        self::assertSame(-32602, self::j($this->errorOf($this->call('SendMessage', ['message' => $this->message('About', 'm-answer', ['taskId' => $taskId])])), 'code'));
    }

    /**
     * A2A-MT2: an answer outside the question's schema is refused before
     * anything is stored, and the task still waits for it.
     */
    #[Test]
    public function anAnswerOutsideTheSchemaIsRefusedAndStoresNothing(): void
    {
        $taskId = $this->pausedTask();

        $reply = $this->call('SendMessage', ['message' => $this->message('Contact', 'm-wrong', ['taskId' => $taskId]), 'configuration' => ['returnImmediately' => true]]);

        self::assertSame(-32602, self::j($this->errorOf($reply), 'code'));
        self::assertSame(1, $this->rows('tx_nrllm_a2a_dedup'));
        self::assertSame(AgentRunStatus::WAITING_FOR_INPUT, $this->runOf($taskId)->statusEnum());
        self::assertCount(1, self::rowsOf(self::j($this->taskOf($this->call('GetTask', ['id' => $taskId])), 'history')));
    }

    /**
     * A continuation is an authorisation surface: another client's task is
     * indistinguishable from an unknown one, a context of another task is
     * refused, a terminal task is not reopened.
     */
    #[Test]
    public function aClientContinuesOnlyItsOwnOpenTaskInItsContext(): void
    {
        $taskId = $this->pausedTask();

        self::assertSame(-32001, self::j($this->errorOf($this->call('SendMessage', ['message' => $this->message('Home', '', ['taskId' => $taskId])], 4)), 'code'));
        self::assertSame(-32001, self::j($this->errorOf($this->call('SendMessage', ['message' => $this->message('Home', '', ['taskId' => 'no-such-task'])], 1)), 'code'));
        self::assertSame(-32602, self::j($this->errorOf($this->call('SendMessage', ['message' => $this->message('Home', '', ['taskId' => $taskId, 'contextId' => '0193a1c6-6c1e-7cc2-9d55-6a1f2b3c4d5e'])])), 'code'));

        $this->getService(AgentRunPersister::class)->cancel($this->runOf($taskId)->uuid);
        $terminal = $this->call('SendMessage', ['message' => $this->message('Home', '', ['taskId' => $taskId])]);
        self::assertSame([-32004, 'UNSUPPORTED_OPERATION'], array_slice(array_values($this->errorOf($terminal)), 0, 2));
        self::assertSame('The task is in a terminal state.', self::j($terminal, 'error.message'), 'a terminal task is refused as terminal (A2A-SV6)');
        self::assertSame(1, $this->rows('tx_nrllm_a2a_dedup'));
    }

    /**
     * A2A-MT4 at intake: a client that may no longer call the task's skill
     * cannot continue it.
     */
    #[Test]
    public function aClientThatLostTheSkillCannotContinue(): void
    {
        $taskId = $this->pausedTask();
        $this->db('tx_nrllm_a2a_client')->update('tx_nrllm_a2a_client', ['profiles' => '2'], ['uid' => 1]);

        $reply = $this->call('SendMessage', ['message' => $this->message('Home', '', ['taskId' => $taskId]), 'configuration' => ['returnImmediately' => true]]);

        self::assertSame([-32602, 'PROFILE_NOT_ALLOWED'], array_slice(array_values($this->errorOf($reply)), 0, 2));
        self::assertSame(AgentRunStatus::WAITING_FOR_INPUT, $this->runOf($taskId)->statusEnum());
    }

    /**
     * The check a new task gets, at intake: a client whose technical user
     * lost access to the run's configuration, or a configuration switched off,
     * since the task paused does not continue it.
     */
    #[Test]
    public function aContinuationIsRefusedWhenTheConfigurationAccessIsGone(): void
    {
        $taskId = $this->pausedTask();
        $this->restrictConfigurationToAnotherGroup();

        $reply = $this->call('SendMessage', ['message' => $this->message('Home', 'm-revoked', ['taskId' => $taskId]), 'configuration' => ['returnImmediately' => true]]);

        self::assertSame([-32004, 'PROFILE_UNAVAILABLE'], array_slice(array_values($this->errorOf($reply)), 0, 2));
        self::assertSame(0, $this->rows('tx_nrllm_a2a_dedup', ['message_id' => 'm-revoked']));
        self::assertSame(AgentRunStatus::WAITING_FOR_INPUT, $this->runOf($taskId)->statusEnum());
    }

    #[Test]
    public function aContinuationIsRefusedWhenTheConfigurationIsSwitchedOff(): void
    {
        $taskId = $this->pausedTask();
        $this->db('tx_nrllm_configuration')->update('tx_nrllm_configuration', ['is_active' => 0], ['uid' => 1]);
        $this->getService(PersistenceManagerInterface::class)->clearState();

        $reply = $this->call('SendMessage', ['message' => $this->message('Home', 'm-off', ['taskId' => $taskId]), 'configuration' => ['returnImmediately' => true]]);

        self::assertSame([-32004, 'PROFILE_UNAVAILABLE'], array_slice(array_values($this->errorOf($reply)), 0, 2));
        self::assertSame(AgentRunStatus::WAITING_FOR_INPUT, $this->runOf($taskId)->statusEnum());
    }

    /**
     * The same check on every resume: access revoked or a configuration
     * switched off since the task started refuses the resume.
     */
    #[Test]
    public function everyResumeChecksTheConfigurationAgain(): void
    {
        $taskId = $this->pausedTask();
        $run    = $this->runOf($taskId);
        $guard  = $this->getService(A2aResumeGuard::class);
        $handle = new AgentRunHandle($run->uid, $run->uuid);
        $owner  = AiActorContext::backendUser(7, false, [], [], 0);
        self::assertNull($guard->refusal($handle, $owner));

        $this->db('tx_nrllm_configuration')->update('tx_nrllm_configuration', ['is_active' => 0], ['uid' => 1]);
        $this->getService(PersistenceManagerInterface::class)->clearState();
        self::assertNotNull($guard->refusal($handle, $owner), 'switched off');

        $this->db('tx_nrllm_configuration')->update('tx_nrllm_configuration', ['is_active' => 1], ['uid' => 1]);
        $this->restrictConfigurationToAnotherGroup();
        self::assertNotNull($guard->refusal($handle, $owner), 'access revoked');
        self::assertNotNull($guard->startRefusal($handle, $owner, ['ask_choice']), 'access revoked, at the claim');
    }

    /**
     * Two answers to one pause: the request that locked the client row
     * re-reads the run, and an answer that reached the run meanwhile (here a
     * trigger on the lock plays it) leaves this one refused without a dedup
     * record or a history entry.
     */
    #[Test]
    public function anAnswerThatLosesTheRaceForThePauseStoresNothing(): void
    {
        $taskId = $this->pausedTask();
        $run    = $this->runOf($taskId);
        $this->onClientLock(sprintf("UPDATE tx_nrllm_agentrun SET status = 'running' WHERE uid = %d", $run->uid));

        $reply = $this->call('SendMessage', ['message' => $this->message('Home', 'm-late', ['taskId' => $taskId]), 'configuration' => ['returnImmediately' => true]]);
        $this->dropClientTrigger();

        self::assertSame([-32004, 'UNSUPPORTED_OPERATION'], array_slice(array_values($this->errorOf($reply)), 0, 2));
        self::assertSame(0, $this->rows('tx_nrllm_a2a_dedup', ['message_id' => 'm-late']));
        $row = $this->db(A2aTaskStore::TABLE)->select(['history'], A2aTaskStore::TABLE, ['task_id' => $taskId])->fetchOne();
        self::assertCount(1, self::rowsOf(self::j($this->taskOf($this->call('GetTask', ['id' => $taskId])), 'history')), 'history unchanged: ' . (is_string($row) ? strlen($row) : 0));
    }

    /**
     * A2A-SV9 for an answer: the limits decide under the client's and the
     * profile's row lock. A task another request committed after this
     * request's first count (a trigger on the profile lock plays it) makes
     * the answer refused, and nothing of it is stored.
     */
    #[Test]
    public function anAnswerCountsAgainstTheProfileQuotaUnderItsLock(): void
    {
        $taskId = $this->pausedTask();
        $this->db('tx_nrllm_a2a_profile')->update('tx_nrllm_a2a_profile', ['max_active_tasks' => 1], ['uid' => 1]);
        $insert     = "INSERT INTO tx_nrllm_a2a_task (task_id, owner_client_uid, profile_uid, run_uid, state) VALUES ('concurrent', 2, 1, 999999, 'TASK_STATE_SUBMITTED')";
        $connection = $this->db('tx_nrllm_a2a_profile');
        $connection->executeStatement($connection->getDatabasePlatform() instanceof SQLitePlatform
            ? sprintf('CREATE TRIGGER a2a_mt_quota AFTER UPDATE ON tx_nrllm_a2a_profile FOR EACH ROW BEGIN %s; END', $insert)
            : sprintf('CREATE TRIGGER a2a_mt_quota AFTER UPDATE ON tx_nrllm_a2a_profile FOR EACH ROW %s', $insert));

        $reply = $this->call('SendMessage', ['message' => $this->message('Home', 'm-quota', ['taskId' => $taskId]), 'configuration' => ['returnImmediately' => true]]);
        $connection->executeStatement($connection->getDatabasePlatform() instanceof SQLitePlatform ? 'DROP TRIGGER a2a_mt_quota' : 'DROP TRIGGER IF EXISTS a2a_mt_quota');

        self::assertSame('PROFILE_CAPACITY_EXHAUSTED', $this->errorOf($reply)['reason']);
        self::assertSame(0, $this->rows('tx_nrllm_a2a_dedup', ['message_id' => 'm-quota']));
        self::assertSame(AgentRunStatus::WAITING_FOR_INPUT, $this->runOf($taskId)->statusEnum());
    }

    /**
     * A2A-MT4 on resume: a resume guard is asked before every resumed
     * segment, and a revoked client, a skill withdrawn from it, a changed
     * technical user or workspace, a skill no longer public, and a writing
     * skill in live each refuse. The container's executor asks this guard.
     */
    #[Test]
    public function everyResumeChecksTheClientAndTheSkillAgain(): void
    {
        $taskId = $this->pausedTask();
        $run    = $this->runOf($taskId);
        $guard  = $this->getService(A2aResumeGuard::class);
        $handle = new AgentRunHandle($run->uid, $run->uuid);
        $owner  = AiActorContext::backendUser(7, false, [], [], 0);

        self::assertNull($guard->refusal($handle, $owner));

        $changes = [
            ['tx_nrllm_a2a_client', ['enabled' => 0]],
            ['tx_nrllm_a2a_client', ['expires' => 1]],
            ['tx_nrllm_a2a_client', ['profiles' => '2']],
            ['tx_nrllm_a2a_client', ['be_user' => 1]],
            ['tx_nrllm_a2a_profile', ['public' => 0]],
            ['tx_nrllm_a2a_profile', ['may_write' => 1]],
            ['be_users', ['disable' => 1]],
        ];
        foreach ($changes as [$table, $values]) {
            $uid    = $table === 'be_users' ? 7 : 1;
            $before = $this->db($table)->select(array_keys($values), $table, ['uid' => $uid])->fetchAssociative();
            self::assertIsArray($before);
            $this->db($table)->update($table, $values, ['uid' => $uid]);
            self::assertNotNull($guard->refusal($handle, $owner), $table . ' ' . json_encode($values));
            $this->db($table)->update($table, $before, ['uid' => $uid]);
        }

        self::assertNotNull($guard->refusal($handle, AiActorContext::backendUser(7, false, [], [], 3)), 'another workspace');

        $guards = (new ReflectionProperty(AgentRunExecutor::class, 'resumeGuards'))->getValue($this->getService(AgentRunExecutor::class));
        self::assertIsIterable($guards);
        self::assertContains(A2aResumeGuard::class, array_map(get_class(...), [...$guards]));
    }

    /**
     * End to end over a scripted model, with the container's persister, task
     * store, tool registry and policy and the real resume guard: the worker
     * runs the task to the question, the client answers, the worker carries
     * the answer out and the task completes with the answer and both client
     * messages in its history.
     */
    #[Test]
    public function aQuestionAnsweredByTheClientCompletesTheTask(): void
    {
        [$runtime, $taskId] = $this->askedTask();

        $this->responses = [new CompletionResponse('You picked Home.', 'fake', UsageStatistics::fromTokens(1, 1))];
        $this->taskOf($this->call('SendMessage', ['message' => $this->message('Home', '', ['taskId' => $taskId]), 'configuration' => ['returnImmediately' => true]]));
        $runtime->runQueued($this->runOf($taskId)->uuid);

        $task = $this->taskOf($this->call('GetTask', ['id' => $taskId]));
        self::assertSame('TASK_STATE_COMPLETED', self::j($task, 'status.state'));
        self::assertSame('You picked Home.', self::j($task, 'artifacts.0.parts.0.text'));
        self::assertSame(['Summarise this.', 'Home'], [self::j($task, 'history.0.parts.0.text'), self::j($task, 'history.1.parts.0.text')]);
        self::assertSame(2, $this->modelCalls);
    }

    /**
     * The control of the test above (A2A-MT4 end to end): the client is
     * disabled after its answer was stored; the resume guard fails the run
     * and the task, and the model is not asked again.
     */
    #[Test]
    public function aStoredAnswerOfARevokedClientFailsTheTask(): void
    {
        [$runtime, $taskId] = $this->askedTask();

        $this->responses = [new CompletionResponse('must not be asked', 'fake', UsageStatistics::fromTokens(1, 1))];
        $this->taskOf($this->call('SendMessage', ['message' => $this->message('Home', '', ['taskId' => $taskId]), 'configuration' => ['returnImmediately' => true]]));
        $this->db('tx_nrllm_a2a_client')->update('tx_nrllm_a2a_client', ['enabled' => 0], ['uid' => 1]);
        $runtime->runQueued($this->runOf($taskId)->uuid);

        self::assertSame(AgentRunStatus::FAILED, $this->runOf($taskId)->statusEnum());
        self::assertSame('TASK_STATE_FAILED', $this->db(A2aTaskStore::TABLE)->select(['state'], A2aTaskStore::TABLE, ['task_id' => $taskId])->fetchOne());
        self::assertSame(1, $this->modelCalls);
    }

    /**
     * A2A-MT4 at the start: a client disabled after the task was accepted and
     * before a worker claimed its run fails the run and the task, and the
     * model is never asked. The start of an allowed task is covered by
     * askedTask(), which runs through the same guard.
     */
    #[Test]
    public function aQueuedTaskOfARevokedClientFailsInsteadOfStarting(): void
    {
        $runtime         = $this->runtime();
        $taskId          = self::text($this->taskOf($this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]]))['id'] ?? '');
        $this->responses = [new CompletionResponse('must not be asked', 'fake', UsageStatistics::fromTokens(1, 1))];
        $this->db('tx_nrllm_a2a_client')->update('tx_nrllm_a2a_client', ['enabled' => 0], ['uid' => 1]);

        $runtime->runQueued($this->runOf($taskId)->uuid);

        self::assertSame(AgentRunStatus::FAILED, $this->runOf($taskId)->statusEnum());
        self::assertSame('TASK_STATE_FAILED', $this->db(A2aTaskStore::TABLE)->select(['state'], A2aTaskStore::TABLE, ['task_id' => $taskId])->fetchOne());
        self::assertSame(0, $this->modelCalls);
    }

    /**
     * The start check compares the tools the queued request grants with the
     * profile as it is now, and the container's executor asks it.
     */
    #[Test]
    public function theStartCheckComparesTheRequestedToolsWithTheSkill(): void
    {
        $taskId = self::text($this->taskOf($this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]]))['id'] ?? '');
        $run    = $this->runOf($taskId);
        $guard  = $this->getService(A2aResumeGuard::class);
        $handle = new AgentRunHandle($run->uid, $run->uuid);
        $actor  = AiActorContext::backendUser(7, false, [], [], 0);

        self::assertNull($guard->startRefusal($handle, $actor, ['ask_choice']));
        self::assertSame('the skill no longer grants a tool the run was given', $guard->startRefusal($handle, $actor, ['ask_choice', 'update_content_element']));
        self::assertSame('the run was granted tools beyond its skill', $guard->startRefusal($handle, $actor, null));

        $this->db('tx_nrllm_a2a_client')->update('tx_nrllm_a2a_client', ['enabled' => 0], ['uid' => 1]);
        self::assertSame('the A2A client is gone, disabled or expired', $guard->startRefusal($handle, $actor, ['ask_choice']));

        $guards = (new ReflectionProperty(AgentRunExecutor::class, 'startGuards'))->getValue($this->getService(AgentRunExecutor::class));
        self::assertIsIterable($guards);
        self::assertContains(A2aResumeGuard::class, array_map(get_class(...), [...$guards]));
    }

    /**
     * A2A-MT4: the run resumes with the tools it was granted at start, so
     * every resume checks them against the profile as it is now. A tool
     * taken off the profile, or a writing tool once "may write" is cleared,
     * refuses the resume.
     */
    #[Test]
    public function aResumeIsRefusedForToolsTheSkillNoLongerGrants(): void
    {
        $taskId = $this->pausedTask();
        $run    = $this->runOf($taskId);
        $guard  = $this->getService(A2aResumeGuard::class);
        $handle = new AgentRunHandle($run->uid, $run->uuid);
        $owner  = AiActorContext::backendUser(7, false, [], [], 0);
        self::assertNull($guard->refusal($handle, $owner));

        $this->db('tx_nrllm_a2a_profile')->update('tx_nrllm_a2a_profile', ['tools' => ''], ['uid' => 1]);
        self::assertSame('the skill no longer grants a tool the run was given', $guard->refusal($handle, $owner));

        $this->db('tx_nrllm_a2a_client')->update('tx_nrllm_a2a_client', ['workspace' => 3], ['uid' => 1]);
        $this->db('tx_nrllm_a2a_profile')->update('tx_nrllm_a2a_profile', ['tools' => 'ask_choice,update_content_element', 'may_write' => 1], ['uid' => 1]);
        $writing = $this->pausedTask(null, ['ask_choice', 'update_content_element'], 3);
        $wrun    = $this->runOf($writing);
        $whandle = new AgentRunHandle($wrun->uid, $wrun->uuid);
        $wowner  = AiActorContext::backendUser(7, false, [], [], 3);
        self::assertNull($guard->refusal($whandle, $wowner));

        $this->db('tx_nrllm_a2a_profile')->update('tx_nrllm_a2a_profile', ['may_write' => 0], ['uid' => 1]);
        self::assertSame('the skill no longer grants a tool the run was given', $guard->refusal($whandle, $wowner));
    }

    /**
     * A repeated answer is answered from its dedup record even after the
     * task ended: a client that lost the response may retry.
     */
    #[Test]
    public function aRepeatedAnswerReturnsTheTaskAfterItEnded(): void
    {
        $taskId = $this->pausedTask();
        $answer = ['message' => $this->message('Home', 'm-retry', ['taskId' => $taskId]), 'configuration' => ['returnImmediately' => true]];
        $this->taskOf($this->call('SendMessage', $answer));
        $this->getService(AgentRunPersister::class)->cancel($this->runOf($taskId)->uuid);

        $again = $this->taskOf($this->call('SendMessage', $answer));

        self::assertSame([$taskId, 'TASK_STATE_CANCELED'], [$again['id'], self::j($again, 'status.state')]);
    }

    /**
     * A context is not crossed between a client's skills (nor clients, as
     * A2A-SV6 already pins).
     */
    #[Test]
    public function aContextIsNotCrossedBetweenSkills(): void
    {
        $context = '0193a1c6-6c1e-7cc2-9d55-6a1f2b3c4d5f';
        $this->taskOf($this->call('SendMessage', ['message' => $this->message('a', '', ['contextId' => $context]), 'metadata' => ['https://nr-llm.netresearch.de/a2a/ext/profile/v1' => 'summary'], 'configuration' => ['returnImmediately' => true]], 2));

        $reply = $this->call('SendMessage', ['message' => $this->message('b', '', ['contextId' => $context]), 'metadata' => ['https://nr-llm.netresearch.de/a2a/ext/profile/v1' => 'check'], 'configuration' => ['returnImmediately' => true]], 2);

        self::assertSame([-32602, 'PROFILE_MISMATCH'], array_slice(array_values($this->errorOf($reply)), 0, 2));
        self::assertSame(1, $this->rows('tx_nrllm_a2a_task'));
    }

    /**
     * The TCK fixture continues through the real intake: an answer with
     * the input-required prefix waits again, any other completes.
     */
    #[Test]
    public function theTckFixtureContinuesThroughTheIntake(): void
    {
        $task = $this->taskOf($this->call('SendMessage', ['message' => $this->message('hi', 'tck-input-required-x')], 3));
        self::assertSame('TASK_STATE_INPUT_REQUIRED', self::j($task, 'status.state'));

        $again = $this->taskOf($this->call('SendMessage', ['message' => $this->message('more', 'tck-input-required-x-1', ['taskId' => $task['id']])], 3));
        self::assertSame('TASK_STATE_INPUT_REQUIRED', self::j($again, 'status.state'));

        $done = $this->taskOf($this->call('SendMessage', ['message' => $this->message('done', 'tck-complete-task-x', ['taskId' => $task['id']]), 'configuration' => ['historyLength' => 0]], 3));
        self::assertSame('TASK_STATE_COMPLETED', self::j($done, 'status.state'));
        self::assertArrayNotHasKey('history', $done);
        self::assertCount(3, self::rowsOf(self::j($this->taskOf($this->call('GetTask', ['id' => $task['id']], 3)), 'history')));
    }

    /**
     * A2A-MT2: a text that cannot be read as the input the pause asks for
     * (here two values) is refused, not guessed into a value.
     */
    #[Test]
    public function aTextThatDoesNotFitTheInputIsRefused(): void
    {
        $taskId = $this->pausedTask(['type' => 'object', 'properties' => ['page' => ['type' => 'string'], 'count' => ['type' => 'integer']], 'required' => ['page', 'count']]);

        $reply = $this->call('SendMessage', ['message' => $this->message('Home, twice', 'm-free', ['taskId' => $taskId]), 'configuration' => ['returnImmediately' => true]]);

        self::assertSame(-32602, self::j($this->errorOf($reply), 'code'));
        self::assertSame(0, $this->rows('tx_nrllm_a2a_dedup', ['message_id' => 'm-free']));
        self::assertSame(AgentRunStatus::WAITING_FOR_INPUT, $this->runOf($taskId)->statusEnum());
    }

    /**
     * The privacy ceiling applies to every stored turn: under a `redacted`
     * answer level the client's later message is kept redacted in the
     * history, as the first one is.
     */
    #[Test]
    public function aLaterMessageIsKeptAtTheAnswerCeiling(): void
    {
        $task = $this->taskOf($this->call('SendMessage', ['message' => $this->message('hi', 'tck-input-required-p')], 3));
        $this->db('tx_nrllm_provider')->update('tx_nrllm_provider', ['privacy_level' => 'redacted'], ['uid' => 1]);

        $this->taskOf($this->call('SendMessage', ['message' => $this->message('Write to jane.doe@example.org', 'tck-input-required-p-1', ['taskId' => $task['id']]), 'configuration' => ['returnImmediately' => true]], 3));

        $history = self::rowsOf(self::j($this->taskOf($this->call('GetTask', ['id' => $task['id']], 3)), 'history'));
        self::assertCount(2, $history);
        self::assertStringNotContainsString('jane.doe@example.org', self::text(self::j($history[1], 'parts.0.text')));
    }

    /** @var list<CompletionResponse> */
    private array $responses = [];

    private int $modelCalls = 0;

    /**
     * A runtime over a scripted model and the container's persister, tool
     * registry, policy and resume guard, and a task of client 1 that it ran
     * to the question.
     *
     * @return array{AgentRuntime, string}
     */
    private function askedTask(): array
    {
        $runtime = $this->runtime();

        $task            = $this->taskOf($this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]]));
        $taskId          = self::text($task['id'] ?? '');
        $this->responses = [new CompletionResponse('', 'fake', UsageStatistics::fromTokens(1, 1), toolCalls: [new ToolCall('q1', 'ask_choice', ['question' => 'Which page?', 'options' => ['Home', 'About']])])];
        $runtime->runQueued($this->runOf($taskId)->uuid);
        self::assertSame('TASK_STATE_INPUT_REQUIRED', self::j($this->taskOf($this->call('GetTask', ['id' => $taskId])), 'status.state'));

        return [$runtime, $taskId];
    }

    /**
     * A runtime over a scripted model and the container's persister, tool
     * registry and policy, with the real A2A guard asked at start and resume.
     */
    private function runtime(): AgentRuntime
    {
        $registry = $this->getService(ToolRegistry::class);
        $policy   = $this->getService(ToolCallPolicyInterface::class);
        $manager  = self::createStub(LlmServiceManagerInterface::class);
        $manager->method('chatWithToolsForConfiguration')->willReturnCallback(function (): CompletionResponse {
            ++$this->modelCalls;
            $next = array_shift($this->responses);
            self::assertInstanceOf(CompletionResponse::class, $next, 'The model was asked more often than scripted.');

            return $next;
        });
        $loop      = new ToolLoopService($manager, $registry, $policy);
        $persister = $this->getService(AgentRunPersister::class);

        return new AgentRuntime(
            $loop,
            $persister,
            $this->getService(LlmConfigurationRepository::class),
            new NullLogger(),
            actingBackendUserResolver: new ActingBackendUserResolver(),
            toolEffectResolver: new ToolEffectResolver($registry),
            runExecutor: new AgentRunExecutor(
                $loop,
                $persister,
                new NullLogger(),
                new ActingBackendUserResolver(),
                new ToolEffectResolver($registry),
                resumeGuards: [$this->getService(A2aResumeGuard::class)],
                startGuards: [$this->getService(A2aResumeGuard::class)],
            ),
            toolPolicy: $policy,
        );
    }

    // ------------------------------------------------------------------

    /**
     * A task of client 1 whose run waits for an ask_choice answer, as the
     * worker leaves it.
     */
    /**
     * @param array<string, mixed>|null $schema the input schema; null asks ask_choice's question
     * @param list<string>              $tools  the tools the run was granted
     */
    private function pausedTask(?array $schema = null, array $tools = ['ask_choice'], int $workspace = 0): string
    {
        $task      = $this->taskOf($this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]]));
        $run       = $this->runOf(self::text($task['id'] ?? ''));
        $persister = $this->getService(AgentRunPersister::class);
        self::assertTrue($persister->claimQueued($run, 'worker-t', time() + 300));
        $arguments = ['question' => 'Which page?', 'options' => ['Home', 'About']];
        self::assertTrue($persister->suspendForInput(new AgentRunHandle($run->uid, $run->uuid), new SuspendedRunState(
            [['role' => 'user', 'content' => 'Summarise this.']],
            [['id' => 'q1', 'type' => 'function', 'function' => ['name' => 'ask_choice', 'arguments' => json_encode($arguments)]]],
            1,
            0,
            0,
            $tools,
            [],
            'ask_choice',
            $schema ?? (new AskChoiceTool())->inputSchemaFor($arguments),
            initiatingActor: AiActorContext::backendUser(7, false, [], [], $workspace),
            initiatingRunUuid: $run->uuid,
        )));

        return self::text($task['id'] ?? '');
    }

    /**
     * The configuration is restricted to a backend group the client's
     * technical user (7) is not in.
     */
    private function restrictConfigurationToAnotherGroup(): void
    {
        $this->db('be_groups')->insert('be_groups', ['uid' => 40, 'pid' => 0, 'title' => 'LLM users']);
        $this->db('tx_nrllm_configuration_begroups_mm')->insert('tx_nrllm_configuration_begroups_mm', ['uid_local' => 1, 'uid_foreign' => 40, 'sorting' => 1]);
        $this->db('tx_nrllm_configuration')->update('tx_nrllm_configuration', ['allowed_groups' => 1], ['uid' => 1]);
        $this->getService(PersistenceManagerInterface::class)->clearState();
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

    /**
     * @return array<string, mixed>
     */
    private function decisionOf(AgentRun $run): array
    {
        $state    = json_decode($run->suspendedState ?? '', true);
        $decision = is_array($state) ? ($state[StoredDecision::STORAGE_KEY] ?? []) : [];
        self::assertIsArray($decision);

        return self::objectOf($decision);
    }

    private function onClientLock(string $statement): void
    {
        $connection = $this->db('tx_nrllm_a2a_client');
        $connection->executeStatement($connection->getDatabasePlatform() instanceof SQLitePlatform
            ? sprintf('CREATE TRIGGER a2a_mt_race AFTER UPDATE ON tx_nrllm_a2a_client FOR EACH ROW BEGIN %s; END', $statement)
            : sprintf('CREATE TRIGGER a2a_mt_race AFTER UPDATE ON tx_nrllm_a2a_client FOR EACH ROW %s', $statement));
    }

    private function dropClientTrigger(): void
    {
        $connection = $this->db('tx_nrllm_a2a_client');
        $connection->executeStatement($connection->getDatabasePlatform() instanceof SQLitePlatform ? 'DROP TRIGGER a2a_mt_race' : 'DROP TRIGGER IF EXISTS a2a_mt_race');
    }
}
