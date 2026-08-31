<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Outcome;

use Netresearch\NrLlm\Domain\Enum\AgentEventKind;
use Netresearch\NrLlm\Service\Outcome\WrittenRecordRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The selection ADR-185 depends on, asserted against the database rather than a
 * double — it is SQL, and a double would only restate what the SQL was meant to
 * say.
 */
#[CoversClass(WrittenRecordRepository::class)]
final class WrittenRecordRepositoryTest extends AbstractFunctionalTestCase
{
    private const NOW = 1_800_000_000;

    private const WINDOW = 7 * 86400;

    private WrittenRecordRepository $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $subject = $this->get(WrittenRecordRepository::class);
        self::assertInstanceOf(WrittenRecordRepository::class, $subject);
        $this->subject = $subject;
    }

    #[Test]
    public function aRunWhoseWritesHaveAllSettledIsOffered(): void
    {
        $this->givenRun('settled-run', [self::NOW - self::WINDOW - 100, self::NOW - self::WINDOW - 50]);

        self::assertSame(['settled-run'], $this->subject->findUnansweredCorrelations(self::NOW - self::WINDOW, 10));
    }

    /**
     * The finding this test exists for: a run selected because its FIRST write
     * settled would be judged while a later write was still inside its window —
     * and because the answer is final and excludes the run from being looked at
     * again, that judgement could never be corrected.
     */
    #[Test]
    public function aRunWithOneWriteStillInsideItsWindowIsNotOffered(): void
    {
        $this->givenRun('staggered-run', [self::NOW - self::WINDOW - 86400, self::NOW - 3600]);

        self::assertSame([], $this->subject->findUnansweredCorrelations(self::NOW - self::WINDOW, 10));
    }

    #[Test]
    public function aRunThatAlreadyCarriesAnObservedOutcomeIsNotOffered(): void
    {
        $this->givenRun('answered-run', [self::NOW - self::WINDOW - 100]);
        $this->givenOutcome('answered-run', 'edited');

        self::assertSame([], $this->subject->findUnansweredCorrelations(self::NOW - self::WINDOW, 10));
    }

    /**
     * An explicit rating is a different source and must not hide a run from the
     * observed derivation — ADR-176 keeps the two apart precisely so one cannot
     * stand in for the other.
     */
    #[Test]
    public function anExplicitRatingDoesNotHideARun(): void
    {
        $this->givenRun('rated-run', [self::NOW - self::WINDOW - 100]);
        $this->givenOutcome('rated-run', 'helpful');

        self::assertSame(['rated-run'], $this->subject->findUnansweredCorrelations(self::NOW - self::WINDOW, 10));
    }

    #[Test]
    public function everyWriteOfARunIsReturnedOldestFirst(): void
    {
        $this->givenRun('multi-run', [self::NOW - 300, self::NOW - 200], [42, 43]);

        $writes = $this->subject->findWritesForCorrelation('multi-run');

        self::assertCount(2, $writes);
        self::assertSame(42, $writes[0]->record->uid);
        self::assertSame(43, $writes[1]->record->uid);
    }

    /**
     * @param list<int> $writtenAt
     * @param list<int> $uids
     */
    private function givenRun(string $uuid, array $writtenAt, array $uids = [42]): void
    {
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);

        $runs = $pool->getConnectionForTable('tx_nrllm_agentrun');
        $runs->insert('tx_nrllm_agentrun', ['pid' => 0, 'uuid' => $uuid, 'status' => 'completed', 'crdate' => self::NOW]);

        $runUid = (int)$runs->lastInsertId();

        $events = $pool->getConnectionForTable('tx_nrllm_agentrun_event');
        foreach ($writtenAt as $index => $crdate) {
            $events->insert('tx_nrllm_agentrun_event', [
                'pid'      => 0,
                'run'      => $runUid,
                'sequence' => $index,
                'kind'     => AgentEventKind::TOOL_WRITE->value,
                'round'    => 1,
                'payload'  => json_encode([
                    'writeTargetTable' => 'pages',
                    'writeTargetUid'   => $uids[$index] ?? 42,
                ], JSON_THROW_ON_ERROR),
                'crdate'   => $crdate,
            ]);
        }
    }

    private function givenOutcome(string $correlationId, string $outcome): void
    {
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);

        $pool->getConnectionForTable('tx_nrllm_call_outcome')->insert('tx_nrllm_call_outcome', [
            'pid'            => 0,
            'correlation_id' => $correlationId,
            'outcome'        => $outcome,
            'crdate'         => self::NOW,
            'tstamp'         => self::NOW,
        ]);
    }
}
