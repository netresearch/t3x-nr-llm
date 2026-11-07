<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Privacy;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\TransactionIsolationLevel;
use JsonException;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Exception\RuntimeException;
use Netresearch\NrLlm\Service\Privacy\RunPrivacyLevelResolver;
use Netresearch\NrLlm\Service\Privacy\RunProviderPrivacyInterface;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\AgentRunRepository;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Service\Tool\AgentStateEnvelopeRotator;
use Netresearch\NrLlm\Service\Tool\RunContentPrivacyGuard;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrVault\Crypto\EnvelopeCodecInterface;
use Netresearch\NrVault\Crypto\EnvelopeRotationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[CoversClass(RunContentPrivacyGuard::class)]
#[CoversClass(RunPrivacyLevelResolver::class)]
#[CoversClass(AgentRunRepository::class)]
final class RunContentPrivacyGuardTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function actualCriteriaProviderScrubsEarlyRequestsAndStaleHandleWrites(): void
    {
        $this->seed();
        [$guard, $repository] = $this->services();
        $persister            = new AgentRunPersister(
            $repository,
            FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL),
        );
        $stale               = new AgentRunHandle(1, 'privacy-run');
        $stale->privacyLevel = PrivacyLevel::FULL;
        self::assertTrue(
            $persister->recordStep(
                $stale,
                new RunStep(
                    'request',
                    1,
                    0.0,
                    messagesSent: [['content' => 'secret prompt']],
                ),
            ),
        );
        self::assertStringContainsString('secret prompt', $this->payload(0));
        $guard->beforeProvider(1, 'external');
        self::assertStringNotContainsString('secret prompt', $this->payload(0));
        self::assertTrue(
            $persister->recordStep(
                $stale,
                new RunStep('llm', 1, 1.0, content: 'secret answer'),
            ),
        );
        self::assertStringNotContainsString('secret answer', $this->payload(1));
        self::assertSame('none', $this->storedRun()['privacy_level']);
        self::assertSame('["external"]', $this->storedRun()['privacy_providers']);
    }

    #[Test]
    public function failedAttemptCeilingSurvivesALooserFallbackAndAnotherProcess(): void
    {
        $this->seed();
        [$guard] = $this->services();
        $guard->beforeProvider(1, 'external');
        // The call may throw after this boundary.
        [$resumed, $repository] = $this->services();
        $resumed->beforeProvider(1, 'local');
        $repository->recordAnswer(1, 'must not return to storage');
        $repository->recordEvent(
            1,
            0,
            'llm',
            2,
            1.0,
            '{"content":"must not return to storage"}',
        );
        self::assertSame('', $this->storedRun()['answer']);
        self::assertStringNotContainsString(
            'must not return',
            $this->payload(0),
        );
        self::assertSame(
            '["external","local"]',
            $this->storedRun()['privacy_providers'],
        );
    }

    #[Test]
    public function explicitMetadataBlocksAnswerEvenWhenGlobalDefaultIsMetadata(): void
    {
        $this->seed('metadata');
        [$guard, $repository] = $this->services(PrivacyLevel::METADATA);
        // Only the global metadata default permits the functional answer.
        $repository->recordAnswer(1, 'before provider');
        self::assertNotSame('', $this->storedRun()['answer']);
        $guard->beforeProvider(1, 'external');
        self::assertSame('', $this->storedRun()['answer']);
        $repository->recordAnswer(1, 'after provider');
        self::assertSame('', $this->storedRun()['answer']);
        self::assertSame('metadata', $this->storedRun()['answer_privacy_level']);
    }

    #[Test]
    public function explicitTaskMetadataAlsoBlocksTheGlobalMetadataAnswerException(): void
    {
        $this->seed();
        $this
            ->pool()
            ->getConnectionForTable('tx_nrllm_task')
            ->insert(
                'tx_nrllm_task',
                [
                    'uid'           => 8,
                    'identifier'    => 'private',
                    'name'          => 'Private',
                    'privacy_level' => 'metadata',
                ],
            );
        $this
            ->connection()
            ->update('tx_nrllm_agentrun', ['origin' => 'task:private'], ['uid' => 1]);
        [, $repository] = $this->services(PrivacyLevel::METADATA);
        $repository->recordAnswer(1, 'task answer');
        self::assertSame('', $this->storedRun()['answer']);
    }

    #[Test]
    public function redactedProviderRedactsPreviousAndSubsequentContent(): void
    {
        $this->seed('redacted');
        [$guard, $repository] = $this->services();
        $secret               = 'sk-' . str_repeat('x', 48);
        $repository->recordEvent(
            1,
            0,
            'llm',
            1,
            0.0,
            json_encode(['content' => $secret], JSON_THROW_ON_ERROR),
        );
        $repository->recordAnswer(1, $secret);

        $guard->beforeProvider(1, 'external');
        self::assertStringNotContainsString($secret, $this->payload(0));
        $row = $this->storedRun();
        self::assertIsString($row['answer']);
        self::assertStringNotContainsString(
            $secret,
            $this
                ->codec()
                ->decode($row['answer'], AgentStateCodec::PURPOSE_RUN_ANSWER),
        );
        self::assertSame('redacted', $row['privacy_level']);
    }

    #[Test]
    public function scrubFailureRollsBackEarlierScrubsAndRefusesDispatch(): void
    {
        $this->seed();
        $connection = $this->connection();
        $connection->insert(
            'tx_nrllm_agentrun_event',
            [
                'run'      => 1,
                'sequence' => 0,
                'kind'     => 'llm',
                'payload'  => '{"content":"before"}',
            ],
        );
        $connection->insert(
            'tx_nrllm_agentrun_event',
            [
                'run'      => 1,
                'sequence' => 1,
                'kind'     => 'llm',
                'payload'  => '{invalid',
            ],
        );
        [$guard]    = $this->services();
        $dispatched = false;
        try {
            $guard->beforeProvider(1, 'external');
            $dispatched = true;
            self::fail('Malformed historical content must prevent dispatch.');
        } catch (JsonException) {
            self::assertFalse($dispatched);
        }

        self::assertSame('{"content":"before"}', $this->payload(0));
        self::assertSame('', $this->storedRun()['privacy_level']);
        self::assertEmpty($this->storedRun()['privacy_providers']);
    }

    #[Test]
    public function currentPolicyTightensLegacyRowsWithoutInventingHistoricalProviders(): void
    {
        $this->seed();
        $this
            ->connection()
            ->insert(
                'tx_nrllm_agentrun_event',
                [
                    'run'      => 1,
                    'sequence' => 0,
                    'kind'     => 'llm',
                    'payload'  => '{"content":"legacy"}',
                ],
            );
        [, $repository] = $this->services(PrivacyLevel::METADATA);
        $repository->recordEvent(1, 1, 'llm', 1, 0.0, '{"content":"new"}');
        self::assertStringNotContainsString('legacy', $this->payload(0));
        self::assertStringNotContainsString('new', $this->payload(1));
        self::assertEmpty($this->storedRun()['privacy_providers']);
    }

    #[Test]
    public function productionContainerWiresTheGuardAndEnforcesItsRealPolicy(): void
    {
        $this->seed();
        $guard = $this->get(RunProviderPrivacyInterface::class);
        self::assertInstanceOf(RunContentPrivacyGuard::class, $guard);
        $repository = $this->get(AgentRunRepository::class);
        self::assertInstanceOf(AgentRunRepository::class, $repository);
        $guard->beforeProvider(1, 'external');
        $repository->recordEvent(
            1,
            0,
            'llm',
            1,
            0.0,
            '{"content":"real container"}',
        );
        self::assertStringNotContainsString('real container', $this->payload(0));
        self::assertSame('none', $this->storedRun()['privacy_level']);
    }

    #[Test]
    public function independentSqliteConnectionCannotWriteWhileRunPolicyIsLocked(): void
    {
        $this->seed();
        $connection = $this->connection();
        if (($connection->getParams()['driver'] ?? '') !== 'pdo_sqlite') {
            self::markTestSkipped(
                'SQLite lock assertion; other platforms use the same transactional row UPDATE.',
            );
        }

        $second = DriverManager::getConnection($connection->getParams());
        self::assertInstanceOf(Connection::class, $second);
        $second->executeStatement('PRAGMA busy_timeout = 1');
        $pool = self::createStub(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($second);
        $policy = new FixedPrivacyPolicy(PrivacyLevel::FULL);
        $guard  = new RunContentPrivacyGuard(
            $pool,
            new RunPrivacyLevelResolver($policy, $this->pool()),
            FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL),
            $policy,
            $this->codec(),
        );
        $connection->beginTransaction();
        $connection->executeStatement(
            'UPDATE tx_nrllm_agentrun SET privacy_level = ? WHERE uid = 1',
            ['none'],
        );
        $written = false;
        try {
            $guard->writeEvent(
                1,
                'llm',
                '{"content":"stale"}',
                static function () use (&$written): void {
                    $written = true;
                },
            );
            self::fail('Another connection must not pass the held run lock.');
        } catch (Exception $exception) {
            self::assertStringContainsString('locked', $exception->getMessage());
            self::assertFalse($written);
        } finally {
            $connection->commit();
        }

        // Retrying on that independent connection reads the committed ceiling.
        $payload = '';
        $guard->writeEvent(
            1,
            'llm',
            '{"content":"stale"}',
            static function (string $filtered) use (&$payload): void {
                $payload = $filtered;
            },
        );
        self::assertStringNotContainsString('stale', $payload);
        $second->close();
    }

    private function seed(string $externalLevel = 'none'): void
    {
        $connection = $this->connection();
        $connection->insert(
            'tx_nrllm_provider',
            [
                'uid'           => 5,
                'identifier'    => 'external',
                'name'          => 'External',
                'adapter_type'  => 'openai',
                'privacy_level' => $externalLevel,
            ],
        );
        $connection->insert(
            'tx_nrllm_provider',
            [
                'uid'          => 6,
                'identifier'   => 'local',
                'name'         => 'Local',
                'adapter_type' => 'ollama',
            ],
        );
        $connection->insert(
            'tx_nrllm_configuration',
            [
                'uid'                  => 7,
                'identifier'           => 'criteria',
                'name'                 => 'Criteria',
                'model_selection_mode' => 'criteria',
            ],
        );
        $connection->insert(
            'tx_nrllm_agentrun',
            [
                'uid'               => 1,
                'uuid'              => 'privacy-run',
                'configuration_uid' => 7,
                'status'            => 'running',
            ],
        );
    }

    /**
     * @return array{RunContentPrivacyGuard, AgentRunRepository}
     */
    private function services(PrivacyLevel $level = PrivacyLevel::FULL): array
    {
        $policy = new FixedPrivacyPolicy($level);
        $guard  = new RunContentPrivacyGuard(
            $this->pool(),
            new RunPrivacyLevelResolver($policy, $this->pool()),
            FixedPrivacyPolicy::filterAt($level),
            $policy,
            $this->codec(),
        );

        return [$guard, new AgentRunRepository($this->pool(), $this->codec(), $guard)];
    }

    private function pool(): ConnectionPool
    {
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);

        return $pool;
    }

    private function connection(): Connection
    {
        return $this->pool()->getConnectionForTable('tx_nrllm_agentrun');
    }

    private function codec(): AgentStateCodec
    {
        $codec = $this->get(AgentStateCodec::class);
        self::assertInstanceOf(AgentStateCodec::class, $codec);

        return $codec;
    }

    /**
     * @return array<string, mixed>
     */
    private function storedRun(): array
    {
        $row = $this
            ->connection()
            ->select(['*'], 'tx_nrllm_agentrun', ['uid' => 1])
            ->fetchAssociative();
        self::assertIsArray($row);

        return $row;
    }

    private function payload(int $sequence): string
    {
        $payload = $this
            ->connection()
            ->select(
                ['payload'],
                'tx_nrllm_agentrun_event',
                ['run' => 1, 'sequence' => $sequence],
            )
            ->fetchOne();
        self::assertIsString($payload);

        return $payload;
    }

    #[Test]
    public function unknownProviderIdentityStoresNoContent(): void
    {
        $this->seed();
        [$guard, $repository] = $this->services();
        $guard->beforeProvider(1, 'missing-provider');
        $repository->recordEvent(1, 0, 'llm', 1, 0.0, '{"content":"secret"}');
        self::assertSame('none', $this->storedRun()['privacy_level']);
        self::assertStringNotContainsString('secret', $this->payload(0));
    }

    #[Test]
    public function outerTransactionCannotDeferThePrivacyCommitUntilAfterDispatch(): void
    {
        $this->seed();
        [$guard]    = $this->services();
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $guard->beforeProvider(1, 'external');
            self::fail('Dispatch must not precede the durable privacy commit.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'outer transaction',
                $exception->getMessage(),
            );
        } finally {
            $connection->rollBack();
        }

        self::assertSame('', $this->storedRun()['privacy_level']);
    }

    #[Test]
    public function missingSchemaPreventsProviderDispatch(): void
    {
        $this->seed();
        [$guard]    = $this->services();
        $connection = $this->connection();
        $connection->executeStatement(
            'ALTER TABLE tx_nrllm_agentrun RENAME COLUMN privacy_level TO missing_privacy_level',
        );
        try {
            $guard->beforeProvider(1, 'external');
            self::fail(
                'A missing schema update must prevent provider dispatch.',
            );
        } catch (Exception $exception) {
            self::assertStringContainsString(
                'privacy_level',
                $exception->getMessage(),
            );
        } finally {
            $connection->executeStatement(
                'ALTER TABLE tx_nrllm_agentrun RENAME COLUMN missing_privacy_level TO privacy_level',
            );
        }

        self::assertSame('', $this->storedRun()['privacy_level']);
        self::assertEmpty($this->storedRun()['privacy_providers']);
    }

    #[Test]
    public function keyRotationCannotRestoreAnAnswerAfterAPrivacyScrub(): void
    {
        $this->seed();
        [$guard, $repository] = $this->services();
        $repository->recordAnswer(1, 'before rotation');
        $codec = self::createStub(
            EnvelopeCodecInterface::class,
        );
        $codec->method('isSealed')->willReturn(true);
        $codec
            ->method('rewrap')
            ->willReturnCallback(
                static function (string $sealed) use ($guard): string {
                    // Interleave a committed tightening after the batch read, before UPDATE.
                    $guard->beforeProvider(1, 'external');

                    return $sealed . '-rotated';
                },
            );
        $rotator = new AgentStateEnvelopeRotator(
            $this->pool(),
        );
        try {
            $rotator->rewrapAll(
                new EnvelopeRotationContext(
                    $codec,
                    'old',
                    'new',
                ),
            );
            self::fail(
                'A stale rotation must abort instead of restoring removed content.',
            );
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'changed during key rotation',
                $exception->getMessage(),
            );
        }

        self::assertSame('', $this->storedRun()['answer']);
        self::assertSame('none', $this->storedRun()['answer_privacy_level']);
    }

    #[Test]
    public function repeatableReadWriterUsesTheCommittedCeilingInsteadOfItsOldSnapshot(): void
    {
        $this->seed();
        $connection = $this->connection();
        if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped(
                'Exercises the MySQL/MariaDB repeatable-read snapshot.',
            );
        }

        // MariaDB 11.8+ otherwise rejects this stale writer outright. Disable
        // that session-only protection to exercise MySQL-compatible lock reads.
        $snapshotIsolation = null;
        if ($connection->getDatabasePlatform() instanceof MariaDBPlatform) {
            $snapshotIsolation = $connection->fetchOne('SELECT @@SESSION.innodb_snapshot_isolation');
            $connection->executeStatement(
                'SET SESSION innodb_snapshot_isolation = OFF',
            );
        }

        $isolation = $connection->getTransactionIsolation();
        $connection->setTransactionIsolation(
            TransactionIsolationLevel::REPEATABLE_READ,
        );
        $second = DriverManager::getConnection($connection->getParams());
        self::assertInstanceOf(Connection::class, $second);
        $pool = self::createStub(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($second);
        $policy = new FixedPrivacyPolicy(PrivacyLevel::FULL);
        $guard  = new RunContentPrivacyGuard(
            $pool,
            new RunPrivacyLevelResolver($policy, $this->pool()),
            FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL),
            $policy,
            $this->codec(),
        );
        $connection->beginTransaction();
        try {
            self::assertSame('', $this->storedRun()['privacy_level']);
            $guard->beforeProvider(1, 'external');
            // An ordinary SELECT still sees the earlier snapshot.
            self::assertSame('', $this->storedRun()['privacy_level']);
            [, $repository] = $this->services();
            $repository->recordEvent(
                1,
                0,
                'llm',
                1,
                0.0,
                '{"content":"stale snapshot"}',
            );
            $connection->commit();
            self::assertSame('none', $this->storedRun()['privacy_level']);
            self::assertStringNotContainsString(
                'stale snapshot',
                $this->payload(0),
            );
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            $connection->setTransactionIsolation($isolation);
            if ($snapshotIsolation !== null) {
                $connection->executeStatement(
                    'SET SESSION innodb_snapshot_isolation = ' . ($snapshotIsolation === 1 || $snapshotIsolation === '1' ? 'ON' : 'OFF'),
                );
            }

            $second->close();
        }
    }
}
