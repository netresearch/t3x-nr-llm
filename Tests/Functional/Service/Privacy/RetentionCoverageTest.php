<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Privacy;

use Netresearch\NrLlm\Command\PurgePrivacyDataCommand;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Service\Session\AiSessionRepository;
use Netresearch\NrLlm\Service\Tool\AgentRunRepository;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Proves the central retention promise: no content-bearing table sits outside
 * the privacy purge (ADR-064).
 *
 * Three guarantees are asserted here.
 * 1. Every content-bearing table is emptied by one run of `nrllm:privacy:purge`.
 * 2. A new content-bearing table cannot be added unnoticed — the schema is
 *    enumerated and each table must be declared covered or explicitly exempt.
 * 3. Agent-run retention distinguishes finished runs from runs still awaiting a
 *    human decision: purging by age alone would destroy work in flight.
 */
#[CoversClass(PurgePrivacyDataCommand::class)]
#[CoversClass(AgentRunRepository::class)]
#[CoversClass(AiSessionRepository::class)]
final class RetentionCoverageTest extends AbstractFunctionalTestCase
{
    /**
     * Tables holding per-request content or attributable activity. Each one is
     * deleted by the central purge; the test below proves it.
     */
    private const CONTENT_TABLES = [
        'tx_nrllm_eval_result',
        'tx_nrllm_skill_audit',
        'tx_nrllm_telemetry',
        'tx_nrllm_ai_session',
        'tx_nrllm_ai_session_message',
        'tx_nrllm_agentrun',
        'tx_nrllm_agentrun_event',
        'tx_nrllm_governance_event',
    ];

    /**
     * Tables the retention policy deliberately does not purge, with the reason.
     * Configuration records are operator-managed master data, and usage rows are
     * the billing ledger the budget module reports on.
     */
    private const EXEMPT_TABLES = [
        'tx_nrllm_provider'                    => 'configuration record',
        'tx_nrllm_model'                       => 'configuration record',
        'tx_nrllm_configuration'               => 'configuration record',
        'tx_nrllm_configuration_begroups_mm'   => 'relation of a configuration record',
        'tx_nrllm_configuration_skill_mm'      => 'relation of a configuration record',
        'tx_nrllm_task'                        => 'configuration record',
        'tx_nrllm_task_skill_mm'               => 'relation of a configuration record',
        'tx_nrllm_promptsnippet'               => 'configuration record',
        'tx_nrllm_skill'                       => 'configuration record',
        'tx_nrllm_skill_source'                => 'configuration record',
        'tx_nrllm_tool_state'                  => 'configuration record',
        'tx_nrllm_tool_group_state'            => 'configuration record',
        'tx_nrllm_user_budget'                 => 'configuration record',
        'tx_nrllm_service_usage'               => 'billing ledger the budget module reports on; retention is tracked as a follow-up',
    ];

    #[Test]
    public function everyExtensionTableIsEitherPurgedOrExplicitlyExempt(): void
    {
        $declared = array_merge(self::CONTENT_TABLES, array_keys(self::EXEMPT_TABLES));

        foreach ($this->schemaTables() as $table) {
            self::assertContains(
                $table,
                $declared,
                sprintf(
                    'Table %s is neither covered by the retention purge nor declared exempt. '
                    . 'Add it to PurgePrivacyDataCommand (and CONTENT_TABLES here), or list it in EXEMPT_TABLES with a reason.',
                    $table,
                ),
            );
        }
    }

    #[Test]
    public function oneRunOfThePurgeCommandEmptiesEveryContentTable(): void
    {
        $connectionPool = $this->connectionPool();
        $old            = time() - (10 * 86400);

        $this->seedContentRows($old);

        foreach (self::CONTENT_TABLES as $table) {
            self::assertSame(
                1,
                $connectionPool->getConnectionForTable($table)->count('*', $table, []),
                sprintf('Seeding %s failed; the coverage assertion below would be vacuous.', $table),
            );
        }

        $command = $this->get(PurgePrivacyDataCommand::class);
        self::assertInstanceOf(PurgePrivacyDataCommand::class, $command);

        $tester = new CommandTester($command);
        $exit   = $tester->execute(['--days' => '1']);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());

        foreach (self::CONTENT_TABLES as $table) {
            self::assertSame(
                0,
                $connectionPool->getConnectionForTable($table)->count('*', $table, []),
                sprintf('%s still holds rows past the retention window.', $table),
            );
        }
    }

    #[Test]
    public function finishedRunsArePurgedWhileRunsAwaitingADecisionSurvive(): void
    {
        $connectionPool = $this->connectionPool();
        $connection     = $connectionPool->getConnectionForTable('tx_nrllm_agentrun');
        $old            = time() - (10 * 86400);

        $connection->insert('tx_nrllm_agentrun', $this->runRow('completed-run', AgentRunStatus::COMPLETED, $old));
        $connection->insert('tx_nrllm_agentrun', $this->runRow('failed-run', AgentRunStatus::FAILED, $old));
        $connection->insert('tx_nrllm_agentrun', $this->runRow('waiting-run', AgentRunStatus::WAITING_FOR_APPROVAL, $old));
        $connection->insert('tx_nrllm_agentrun', $this->runRow('running-run', AgentRunStatus::RUNNING, $old));

        $repository = new AgentRunRepository($connectionPool, $this->get(AgentStateCodec::class));
        $deleted    = $repository->purgeOlderThan(time() - (5 * 86400));

        self::assertSame(2, $deleted, 'Only the two terminal runs are eligible.');
        self::assertSame(0, $connection->count('*', 'tx_nrllm_agentrun', ['uuid' => 'completed-run']));
        self::assertSame(0, $connection->count('*', 'tx_nrllm_agentrun', ['uuid' => 'failed-run']));
        self::assertSame(1, $connection->count('*', 'tx_nrllm_agentrun', ['uuid' => 'waiting-run']), 'An approval in flight must survive.');
        self::assertSame(1, $connection->count('*', 'tx_nrllm_agentrun', ['uuid' => 'running-run']));
    }

    #[Test]
    public function unfinishedRunsArePurgedOnTheirOwnWindowTogetherWithTheirEvents(): void
    {
        $connectionPool = $this->connectionPool();
        $connection     = $connectionPool->getConnectionForTable('tx_nrllm_agentrun');
        $eventConnection = $connectionPool->getConnectionForTable('tx_nrllm_agentrun_event');
        $old            = time() - (200 * 86400);

        $connection->insert('tx_nrllm_agentrun', $this->runRow('abandoned-run', AgentRunStatus::WAITING_FOR_APPROVAL, $old));
        $runUid = (int)$connection->lastInsertId();
        $eventConnection->insert('tx_nrllm_agentrun_event', $this->eventRow($runUid, $old));

        $repository = new AgentRunRepository($connectionPool, $this->get(AgentStateCodec::class));
        $deleted    = $repository->purgeUnfinishedOlderThan(time() - (180 * 86400));

        self::assertSame(1, $deleted);
        self::assertSame(0, $connection->count('*', 'tx_nrllm_agentrun', ['uuid' => 'abandoned-run']));
        self::assertSame(0, $eventConnection->count('*', 'tx_nrllm_agentrun_event', ['run' => $runUid]), 'Events must not outlive their run.');
    }

    /**
     * One row per content-bearing table, all aged past any sane window.
     */
    private function seedContentRows(int $timestamp): void
    {
        $connectionPool = $this->connectionPool();

        $connectionPool->getConnectionForTable('tx_nrllm_eval_result')->insert('tx_nrllm_eval_result', [
            'pid'            => 0,
            'set_identifier' => 'old.set',
            'model_id'       => 'm',
            'grader'         => 'deterministic',
            'prompt_count'   => 1,
            'passed_count'   => 1,
            'pass_rate'      => 1,
            'mean_score'     => 1,
            'details'        => '[]',
            'run_date'       => $timestamp,
            'tstamp'         => $timestamp,
            'crdate'         => $timestamp,
        ]);

        $connectionPool->getConnectionForTable('tx_nrllm_skill_audit')->insert('tx_nrllm_skill_audit', [
            'pid'              => 0,
            'crdate'           => $timestamp,
            'event'            => 'ingest_created',
            'source_uid'       => 1,
            'skill_identifier' => 'old',
            'source_sha'       => '',
            'body_checksum'    => '',
            'trust_level'      => 'verified',
            'scan_result'      => '',
            'actor_uid'        => 0,
            'detail'           => '',
        ]);

        $connectionPool->getConnectionForTable('tx_nrllm_telemetry')->insert('tx_nrllm_telemetry', [
            'pid'            => 0,
            'correlation_id' => 'c-1',
            'operation'      => 'chat',
            'provider'       => 'openai',
            'model'          => 'gpt',
            'success'        => 1,
            'latency_ms'     => 10,
            'crdate'         => $timestamp,
        ]);

        $sessionConnection = $connectionPool->getConnectionForTable('tx_nrllm_ai_session');
        $sessionConnection->insert('tx_nrllm_ai_session', [
            'pid'                      => 0,
            'uuid'                     => 'session-1',
            'be_user'                  => 1,
            'configuration_identifier' => 'default',
            'title'                    => 'old session',
            'message_count'            => 1,
            'last_activity'            => $timestamp,
            'tstamp'                   => $timestamp,
            'crdate'                   => $timestamp,
        ]);
        $sessionUid = (int)$sessionConnection->lastInsertId();

        $connectionPool->getConnectionForTable('tx_nrllm_ai_session_message')->insert('tx_nrllm_ai_session_message', [
            'pid'      => 0,
            'session'  => $sessionUid,
            'sequence' => 0,
            'role'     => 'user',
            'content'  => 'an old prompt',
            'model'    => 'gpt',
            'crdate'   => $timestamp,
        ]);

        $runConnection = $connectionPool->getConnectionForTable('tx_nrllm_agentrun');
        $runConnection->insert('tx_nrllm_agentrun', $this->runRow('run-1', AgentRunStatus::COMPLETED, $timestamp));
        $runUid = (int)$runConnection->lastInsertId();

        $connectionPool->getConnectionForTable('tx_nrllm_agentrun_event')
            ->insert('tx_nrllm_agentrun_event', $this->eventRow($runUid, $timestamp));

        $connectionPool->getConnectionForTable('tx_nrllm_governance_event')->insert('tx_nrllm_governance_event', [
            'pid'                      => 0,
            'crdate'                   => $timestamp,
            'correlation_id'           => 'c-1',
            'decision'                 => 'tool_denied',
            'reason'                   => 'trustZone',
            'provider'                 => 'openai',
            'model'                    => 'gpt',
            'configuration_identifier' => 'default',
            'be_user'                  => 1,
            'tool_name'                => 'fetch_logs',
            'agentrun_uid'             => 0,
            'guardrail'                => '',
            'detail'                   => 'zone=externalGlobal;ceiling=editorContent;observedOnly=0',
        ]);
    }

    /**
     * @return array<string, float|int|string>
     */
    private function runRow(string $uuid, AgentRunStatus $status, int $timestamp): array
    {
        return [
            'pid'                      => 0,
            'uuid'                     => $uuid,
            'status'                   => $status->value,
            'configuration_uid'        => 0,
            'configuration_identifier' => 'default',
            'be_user'                  => 1,
            'iterations'               => 1,
            'truncated'                => 0,
            'total_prompt_tokens'      => 0,
            'total_completion_tokens'  => 0,
            'total_tokens'             => 0,
            'estimated_cost'           => 0.0,
            'error_class'              => '',
            'started_at'               => $timestamp,
            'finished_at'              => $status->isTerminal() ? $timestamp : 0,
            'tstamp'                   => $timestamp,
            'crdate'                   => $timestamp,
        ];
    }

    /**
     * @return array<string, float|int|string>
     */
    private function eventRow(int $runUid, int $timestamp): array
    {
        return [
            'pid'         => 0,
            'run'         => $runUid,
            'sequence'    => 0,
            'kind'        => 'llm',
            'round'       => 1,
            'duration_ms' => 1.0,
            'payload'     => '{"kind":"llm"}',
            'crdate'      => $timestamp,
        ];
    }

    /**
     * Every `tx_nrllm_*` table declared in ext_tables.sql.
     *
     * @return list<string>
     */
    private function schemaTables(): array
    {
        $schema = file_get_contents(__DIR__ . '/../../../../ext_tables.sql');
        self::assertIsString($schema);

        preg_match_all('/CREATE TABLE (tx_nrllm_[a-z0-9_]+)/', $schema, $matches);

        return array_values(array_unique($matches[1]));
    }

    private function connectionPool(): ConnectionPool
    {
        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);

        return $connectionPool;
    }
}
