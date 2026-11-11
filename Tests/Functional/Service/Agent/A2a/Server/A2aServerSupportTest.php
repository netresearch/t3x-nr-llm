<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a\Server;

use Closure;
use Netresearch\NrLlm\Command\WorkAgentQueueCommand;
use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aDomainHandlerItems;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aInboundClientRepository;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aProfileToolList;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aRpcError;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSendMessageHandler;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aServerRecordSaveHook;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aServerSettings;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSkillProfile;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSkillProfileRepository;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aTaskView;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aTaskWaiter;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aWaitingSlots;
use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aDomainHandlerRegistry;
use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aTckFixtureHandler;
use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aTextDomainHandler;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskStore;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Agent\DurableDecisionIntakeInterface;
use Netresearch\NrLlm\Service\Agent\Operations\WorkerOperationsRepository;
use Netresearch\NrLlm\Service\Agent\Queue\AgentRunQueuedMessage;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Privacy\RunPrivacyLevelResolver;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\MiddlewareStackResolver;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The parts of the server role around the endpoint (spec 051 A2A-SV8,
 * A2A-SV9, A2A-SV11, A2A-SEC10, ADR-280, ADR-283).
 */
#[CoversClass(A2aTaskWaiter::class)]
#[CoversClass(A2aWaitingSlots::class)]
#[CoversClass(A2aTckFixtureHandler::class)]
#[CoversClass(A2aServerRecordSaveHook::class)]
final class A2aServerSupportTest extends AbstractFunctionalTestCase
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
     * A2A-SV8: the wait reads the committed view until a terminal or an
     * interrupted state; WORKING on the way is not an answer.
     */
    #[Test]
    public function theWaitDoesNotAnswerWithAWorkingTask(): void
    {
        $task      = $this->taskOf($this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]]));
        $persister = $this->getService(AgentRunPersister::class);
        $run       = $this->runOf($task['id']);
        $seen      = [];
        $sleeps    = 0;
        $waiter    = new A2aTaskWaiter($this->getService(A2aTaskView::class), function () use (&$sleeps, &$seen, $persister, $run, $task): void {
            ++$sleeps;
            $seen[] = self::j($this->getService(A2aTaskView::class)->find(1, $task['id']), 'state');
            if ($sleeps === 1) {
                self::assertTrue($persister->claimQueued($run, 'worker-t', time() + 300));
            }

            if ($sleeps === 2) {
                self::assertTrue($persister->settleCompleted(new AgentRunHandle($run->uid, $run->uuid), new ToolLoopResult('done', [], 1, false, UsageStatistics::fromTokens(1, 1)), 'worker-t'));
            }
        });

        $row = $waiter->wait(1, $task['id'], microtime(true) + 30);

        self::assertSame('TASK_STATE_COMPLETED', $row['state'] ?? null);
        self::assertSame(['TASK_STATE_SUBMITTED', 'TASK_STATE_WORKING'], $seen);
        self::assertSame(2, $sleeps);
    }

    /**
     * Another client's task is never waited for: the wait is owner-scoped.
     */
    #[Test]
    public function theWaitReadsTheOwnersTaskOnly(): void
    {
        $task  = $this->taskOf($this->call('SendMessage', ['message' => $this->message('hi', 'tck-complete-task-w')], 3));
        $clock = 0.0;

        self::assertNull((new A2aTaskWaiter($this->getService(A2aTaskView::class), static function () use (&$clock): void {
            $clock += 1.0;
        }, static function () use (&$clock): float {
            return $clock;
        }))->wait(1, $task['id'], 2.0));
    }

    /**
     * A2A-SV9: the slots are shared through the database; a full set refuses
     * one more, a released or expired slot is free again.
     */
    #[Test]
    public function waitingSlotsAreBoundedAndFreedByReleaseOrExpiry(): void
    {
        $slots = $this->getService(A2aWaitingSlots::class);
        $now   = 1000;

        $first  = $slots->take(2, $now, $now + 30);
        $second = $slots->take(2, $now, $now + 30);
        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertNull($slots->take(2, $now, $now + 30));

        $slots->release($first);
        self::assertNotNull($slots->take(2, $now, $now + 30));
        self::assertNull($slots->take(2, $now + 10, $now + 40));
        self::assertNotNull($slots->take(2, $now + 31, $now + 60), 'a hold past its expiry is free');
    }

    /**
     * A2A-SV11, A2A-SEC10: the TCK fixture exists in the Testing context
     * only. Outside it no profile can select it and the registry does not
     * return it; the address classifier's half is A2aUrlGuardFactoryTest.
     */
    #[Test]
    public function theTckFixtureIsAvailableInTheTestingContextOnly(): void
    {
        $persister = $this->getService(AgentRunPersister::class);

        self::assertTrue((new A2aTckFixtureHandler($persister, new ApplicationContext('Testing')))->isAvailable());
        foreach (['Production', 'Development', 'Production/Staging'] as $context) {
            self::assertFalse((new A2aTckFixtureHandler($persister, new ApplicationContext($context)))->isAvailable(), $context);
        }

        $production = new A2aDomainHandlerRegistry([new A2aTextDomainHandler(), new A2aTckFixtureHandler($persister, new ApplicationContext('Production'))]);
        self::assertNull($production->get(A2aTckFixtureHandler::IDENTIFIER));
        self::assertSame([A2aTextDomainHandler::IDENTIFIER], $production->identifiers());
        $items = ['items' => []];
        (new A2aDomainHandlerItems($production))->addItems($items);
        self::assertSame([A2aTextDomainHandler::IDENTIFIER], array_column($items['items'], 'value'));
    }

    /**
     * ADR-283: a sweep tick of nrllm:agent:work is a heartbeat the server
     * counts.
     */
    #[Test]
    public function aSweepTickRecordsAHeartbeat(): void
    {
        $this->db('tx_nrllm_worker_heartbeat')->truncate('tx_nrllm_worker_heartbeat');
        $repository = $this->getService(WorkerOperationsRepository::class);
        self::assertSame(0, $repository->liveProcesses(time()));

        (new CommandTester($this->getService(WorkAgentQueueCommand::class)))->execute([]);

        self::assertSame(1, $repository->liveProcesses(time()));
        self::assertSame(1, $this->rows('tx_nrllm_worker_heartbeat', ['transport' => 'sweep']));
    }

    /**
     * ADR-282: the answer level of a configuration is NONE when it cannot be
     * read (no configuration row, a deleted one, no model or provider behind
     * it); an empty provider level inherits the default.
     */
    #[Test]
    public function aConfigurationWithoutAProviderKeepsNoResult(): void
    {
        $levels = $this->getService(RunPrivacyLevelResolver::class);
        $this->db('tx_nrllm_provider')->update('tx_nrllm_provider', ['privacy_level' => ''], []);
        $this->db('tx_nrllm_configuration')->insert('tx_nrllm_configuration', ['uid' => 50, 'identifier' => 'orphan', 'name' => 'Orphan', 'model_uid' => 9999]);

        self::assertTrue($levels->forConfigurationAnswer(1)->persistsContent(), 'an empty provider level inherits the default');
        self::assertSame(PrivacyLevel::NONE, $levels->forConfigurationAnswer(50), 'no model behind the configuration');
        self::assertSame(PrivacyLevel::NONE, $levels->forConfigurationAnswer(9999), 'no configuration');
        self::assertSame(PrivacyLevel::NONE, $levels->forConfigurationAnswer(0), 'no configuration named');

        $this->db('tx_nrllm_configuration')->update('tx_nrllm_configuration', ['deleted' => 1], ['uid' => 1]);
        self::assertSame(PrivacyLevel::NONE, $levels->forConfigurationAnswer(1), 'a deleted configuration');
    }

    /**
     * A2A-SV5: the acceptance takes the run, the task and the dedup record
     * back together only on one connection; tables on another one are
     * refused before anything is written.
     */
    #[Test]
    public function acceptanceTablesOnAnotherConnectionAreRefusedBeforeAnythingIsWritten(): void
    {
        $real  = $this->getService(ConnectionPool::class);
        $other = self::createStub(Connection::class);
        $pool  = self::createStub(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturnCallback(
            static fn (string $table): Connection => $table === 'tx_nrllm_a2a_dedup' ? $other : $real->getConnectionForTable($table),
        );
        $client = $this->getService(A2aInboundClientRepository::class)->findByUid(1);
        self::assertNotNull($client);

        try {
            $this->sendHandler($pool)->handle($client, ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]], time());
            self::fail('the acceptance went ahead');
        } catch (A2aRpcError $error) {
            self::assertSame(A2aRpcError::REASON_INTERNAL, $error->reason);
        }

        self::assertSame([0, 0], [$this->rows('tx_nrllm_agentrun'), $this->rows('tx_nrllm_a2a_task')]);
    }

    /**
     * A consumer outside this database is woken after the acceptance commits,
     * so it never looks for a run that is not there yet.
     */
    #[Test]
    public function anAcceptedTaskWakesAConsumerAfterTheCommit(): void
    {
        $routing = $this->routing([AgentRunQueuedMessage::class => 'doctrine']);

        $connection = $this->getService(ConnectionPool::class)->getConnectionForTable('tx_nrllm_agentrun');
        $outside    = [];
        $bus        = self::createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use ($connection, &$outside): Envelope {
            if ($message instanceof AgentRunQueuedMessage && !$connection->isTransactionActive()) {
                $outside[] = $message->runUuid;
            }

            return new Envelope($message);
        });
        $client = $this->getService(A2aInboundClientRepository::class)->findByUid(1);
        self::assertNotNull($client);

        try {
            $this->sendHandler(null, $bus)->handle($client, ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]], time());
        } finally {
            $this->routing($routing);
        }

        $run = $connection->select(['uuid'], 'tx_nrllm_agentrun', [])->fetchOne();
        self::assertSame([$run], $outside);
    }

    /**
     * ADR-283: a run longer than the heartbeat window keeps the sweep shown as
     * running; the command writes the heartbeat at the run's steps.
     */
    #[Test]
    public function aLongRunRefreshesTheSweepHeartbeatAtItsSteps(): void
    {
        $this->taskOf($this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]]));
        $this->db('tx_nrllm_worker_heartbeat')->truncate('tx_nrllm_worker_heartbeat');

        $seen    = [];
        $runtime = self::createStub(AgentRuntimeInterface::class);
        $runtime->method('runQueued')->willReturnCallback(function (string $uuid, ?Closure $onStep) use (&$seen): ?AgentRunResult {
            // The run has been going for longer than the heartbeat window.
            $this->db('tx_nrllm_worker_heartbeat')->update('tx_nrllm_worker_heartbeat', ['last_seen' => 1], ['transport' => WorkAgentQueueCommand::SWEEP_TRANSPORT]);
            $onStep?->__invoke(new RunStep(RunStep::KIND_LLM, 1, 0.0));
            $seen[] = $this->db('tx_nrllm_worker_heartbeat')->select(['last_seen'], 'tx_nrllm_worker_heartbeat', ['transport' => WorkAgentQueueCommand::SWEEP_TRANSPORT])->fetchOne();

            return null;
        });
        $budget = self::createStub(BudgetServiceInterface::class);
        $budget->method('check')->willReturn(BudgetCheckResult::allowed());

        $command = new WorkAgentQueueCommand(
            $this->getService(AgentRunPersister::class),
            $runtime,
            $budget,
            $this->getService(LlmConfigurationRepository::class),
            $this->getService(WorkerOperationsRepository::class),
            0,
        );
        (new CommandTester($command))->execute([]);

        self::assertCount(1, $seen, 'the queued run was handed to the runtime');
        self::assertGreaterThan(time() - 60, (int) $seen[0], 'the step wrote a fresh heartbeat');
    }

    /**
     * ADR-280, A2A-SV4: a DataHandler write cannot set a client's token, nor
     * name an administrator as its technical user, nor make a profile public
     * whose configuration keeps no result.
     */
    #[Test]
    public function theSaveHookGuardsTheRecords(): void
    {
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->createFromUserPreferences($this->setUpBackendUser(1));
        $before          = $this->db('tx_nrllm_a2a_client')->select(['token_hash', 'token_prefix', 'be_user'], 'tx_nrllm_a2a_client', ['uid' => 1])->fetchAssociative();

        $this->dataHandler(['tx_nrllm_a2a_client' => [1 => ['token_hash' => str_repeat('0', 64), 'token_prefix' => 'aaaa', 'be_user' => 1, 'enabled' => 1]]]);

        $after = $this->db('tx_nrllm_a2a_client')->select(['token_hash', 'token_prefix', 'be_user', 'enabled'], 'tx_nrllm_a2a_client', ['uid' => 1])->fetchAssociative();
        self::assertSame([self::j($before, 'token_hash'), self::j($before, 'token_prefix'), (int) self::j($before, 'be_user'), 0], [self::j($after, 'token_hash'), self::j($after, 'token_prefix'), (int) self::j($after, 'be_user'), (int) self::j($after, 'enabled')]);

        $this->db('tx_nrllm_a2a_profile')->update('tx_nrllm_a2a_profile', ['public' => 0], ['uid' => 2]);
        $this->db('tx_nrllm_provider')->update('tx_nrllm_provider', ['privacy_level' => 'none'], ['uid' => 1]);
        $this->dataHandler(['tx_nrllm_a2a_profile' => [2 => ['public' => 1, 'may_write' => 1]]]);
        self::assertSame([0, 0], array_map(intval(...), array_values($this->db('tx_nrllm_a2a_profile')->select(['public', 'may_write'], 'tx_nrllm_a2a_profile', ['uid' => 2])->fetchAssociative() ?: [])));

        $this->db('tx_nrllm_provider')->update('tx_nrllm_provider', ['privacy_level' => ''], ['uid' => 1]);
        $this->dataHandler(['tx_nrllm_a2a_profile' => [2 => ['public' => 1]]]);
        self::assertSame(1, (int) $this->db('tx_nrllm_a2a_profile')->select(['public'], 'tx_nrllm_a2a_profile', ['uid' => 2])->fetchOne());

        // A profile that may not write offers only tools that declare that
        // they only read: an unknown or undeclared one refuses the list.
        $this->dataHandler(['tx_nrllm_a2a_profile' => [2 => ['tools' => 'fetch_logs']]]);
        self::assertSame('fetch_logs', $this->db('tx_nrllm_a2a_profile')->select(['tools'], 'tx_nrllm_a2a_profile', ['uid' => 2])->fetchOne());
        $this->dataHandler(['tx_nrllm_a2a_profile' => [2 => ['tools' => 'fetch_logs,no_such_tool']]]);
        self::assertSame('fetch_logs', $this->db('tx_nrllm_a2a_profile')->select(['tools'], 'tx_nrllm_a2a_profile', ['uid' => 2])->fetchOne());
    }

    /**
     * A2A-SV4: a run of a profile that may not write gets only the tools that
     * declare that they only read. A registered tool that declares no effect
     * is left out, although the effect resolver reads it as a read for its
     * retry guards.
     */
    #[Test]
    public function aToolThatDeclaresNoEffectIsNotGrantedToAProfileThatMayNotWrite(): void
    {
        $silent = self::createStub(ToolInterface::class);
        $silent->method('getSpec')->willReturn(ToolSpec::function('silent_tool', 'declares nothing', ['type' => 'object', 'properties' => []]));
        $resolver = new ToolEffectResolver(new ToolRegistry([$silent, new FakeTool('reads'), new FakeTool('writes', effect: ToolEffect::IDEMPOTENT_WRITE)]));
        self::assertSame(ToolEffect::READ_ONLY, $resolver->effectFor('silent_tool'));

        $tools   = new A2aProfileToolList($resolver);
        $granted = $tools->allowedFor(A2aSkillProfile::fromRow(['tools' => 'silent_tool,reads,writes,no_such_tool']));
        self::assertSame(['silent_tool', 'reads', 'writes', 'no_such_tool'], $tools->allowedFor(A2aSkillProfile::fromRow(['tools' => 'silent_tool,reads,writes,no_such_tool', 'may_write' => 1])));

        self::assertSame(['reads'], $granted);
    }

    /**
     * A2A-SV1: the middleware is part of the frontend stack, after the
     * normalised params and before the site is resolved; the stack has no
     * ordering cycle.
     */
    #[Test]
    public function theMiddlewareRunsBeforeTheSiteIsResolved(): void
    {
        // The resolver lists the stack innermost first; reversed, it is the
        // order a request passes through. resolve() returns an array on 13.4
        // and an ArrayObject on 14.3; iterator_to_array() takes both.
        $stack = array_reverse(array_keys(iterator_to_array($this->getService(MiddlewareStackResolver::class)->resolve('frontend'))));

        $a2a = array_search('netresearch/nr-llm/a2a-server', $stack, true);
        self::assertIsInt($a2a);
        self::assertLessThan($a2a, array_search('typo3/cms-core/normalized-params-attribute', $stack, true));
        self::assertGreaterThan($a2a, array_search('typo3/cms-frontend/site', $stack, true));
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

    private function runOf(string $taskId): AgentRun
    {
        $runUid = $this->db(A2aTaskStore::TABLE)->select(['run_uid'], A2aTaskStore::TABLE, ['task_id' => $taskId])->fetchOne();
        $uuid   = $this->db('tx_nrllm_agentrun')->select(['uuid'], 'tx_nrllm_agentrun', ['uid' => $runUid])->fetchOne();
        self::assertIsString($uuid);
        $run = $this->getService(AgentRunPersister::class)->findRun($uuid);
        self::assertInstanceOf(AgentRun::class, $run);

        return $run;
    }

    /**
     * Set the messenger routing; returns the one before.
     */
    private function routing(mixed $routing): mixed
    {
        // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
        $previous = $GLOBALS['TYPO3_CONF_VARS']['SYS']['messenger']['routing'] ?? null;
        // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['messenger']['routing'] = $routing;

        return $previous;
    }

    private function sendHandler(?ConnectionPool $pool = null, ?MessageBusInterface $bus = null): A2aSendMessageHandler
    {
        return new A2aSendMessageHandler(
            $this->getService(A2aTaskView::class),
            $this->getService(A2aSkillProfileRepository::class),
            $this->getService(A2aDomainHandlerRegistry::class),
            $this->getService(A2aTaskStore::class),
            $this->getService(AgentRuntimeInterface::class),
            $this->getService(AgentRunPersister::class),
            $this->getService(LlmConfigurationRepository::class),
            $this->getService(RunPrivacyLevelResolver::class),
            $this->getService(A2aProfileToolList::class),
            $this->getService(WorkerOperationsRepository::class),
            $this->getService(A2aWaitingSlots::class),
            $this->getService(A2aServerSettings::class),
            $this->getService(A2aTaskWaiter::class),
            $pool ?? $this->getService(ConnectionPool::class),
            $this->getService(DurableDecisionIntakeInterface::class),
            $this->getService(ConfigurationResolver::class),
            null,
            $bus,
        );
    }
}
