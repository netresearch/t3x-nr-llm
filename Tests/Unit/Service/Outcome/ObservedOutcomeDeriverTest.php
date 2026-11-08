<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Outcome;

use Netresearch\NrLlm\Domain\Enum\CallOutcome;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Service\Outcome\ObservedOutcomeDeriver;
use Netresearch\NrLlm\Service\Outcome\ObservedWrite;
use Netresearch\NrLlm\Service\Outcome\WrittenRecordRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The classification ADR-185 decides, at the boundary that matters: what the
 * deriver answers when it cannot tell.
 */
#[CoversClass(ObservedOutcomeDeriver::class)]
final class ObservedOutcomeDeriverTest extends TestCase
{
    private const WRITTEN_AT = 1_700_000_000;

    /**
     * No later history is not proof that nobody touched the record: history
     * can be purged, and retained rows never certify complete coverage
     * (ADR-273). Answering "accepted" here would infer approval of the model
     * from an absence of evidence.
     */
    #[Test]
    public function aRecordWithoutLaterEvidenceRemainsUnknownAndIsNeverAccepted(): void
    {
        $counts = $this->derive(exists: true, later: []);

        self::assertSame(['unknown' => 1], $counts);
        self::assertArrayNotHasKey('accepted_unchanged', $counts);
    }

    #[Test]
    public function aRecordSomebodyModifiedIsEdited(): void
    {
        self::assertSame(
            ['edited' => 1],
            $this->derive(exists: true, later: [2]),
        );
    }

    #[Test]
    public function aRecordThatIsGoneIsDiscarded(): void
    {
        self::assertSame(
            ['discarded' => 1],
            $this->derive(exists: false, later: []),
        );
    }

    /**
     * A delete row while the record is still there — a soft delete the
     * restriction-free existence check still finds. The history is what says
     * the editor threw it away.
     */
    #[Test]
    public function aDeletionInTheHistoryIsDiscardedEvenWhenTheRowSurvives(): void
    {
        self::assertSame(
            ['discarded' => 1],
            $this->derive(exists: true, later: [4]),
        );
    }

    /**
     * The window is the only thing standing between "judged" and "judged too
     * early", so a zero or negative setting is raised rather than honoured — a
     * window of zero classifies a record the same second it was written and
     * reports every write as untouched.
     */
    #[Test]
    public function aWindowBelowTheFloorIsRaisedToIt(): void
    {
        $writes = new class implements WrittenRecordRepositoryInterface {
            public int $settledAt = 0;

            public function findUnansweredCorrelations(int $timestamp, int $limit): array
            {
                $this->settledAt = $timestamp;

                return [];
            }

            public function findWritesForCorrelation(string $correlationId): array
            {
                return [];
            }

            public function historyAfter(RecordReference $record, int $writtenAt): array
            {
                return ['later' => []];
            }

            public function recordExists(RecordReference $record): bool
            {
                return true;
            }

            public function isDeletion(int $actionType): bool
            {
                return $actionType === 4;
            }
        };

        $deriver = new ObservedOutcomeDeriver($writes, $this->outcomes());
        $deriver->derive(0, 1_000_000);

        self::assertSame(1_000_000 - 86400, $writes->settledAt);
    }

    /**
     * The batch has to keep moving. A page of runs that were already answered
     * used to be re-selected and skipped, so the command reported no work
     * while later writes piled up behind it — the exclusion belongs in the
     * query, and this asserts the deriver relies on it rather than filtering
     * afterwards.
     */
    #[Test]
    public function onlyTheRunsTheQueryOffersAreJudged(): void
    {
        $outcomes = $this->outcomes(['answered-run' => [CallOutcome::EDITED]]);
        $writes   = new class implements WrittenRecordRepositoryInterface {
            public function findUnansweredCorrelations(int $timestamp, int $limit): array
            {
                // The query is what excludes 'answered-run'; the deriver never
                // sees it.
                return ['fresh-run'];
            }

            public function findWritesForCorrelation(string $correlationId): array
            {
                return [new ObservedWrite($correlationId, new RecordReference('pages', 42), ObservedOutcomeDeriverTest::writtenAt())];
            }

            public function historyAfter(RecordReference $record, int $writtenAt): array
            {
                return ['later' => []];
            }

            public function recordExists(RecordReference $record): bool
            {
                return true;
            }

            public function isDeletion(int $actionType): bool
            {
                return $actionType === 4;
            }
        };

        (new ObservedOutcomeDeriver($writes, $outcomes))->derive(7, self::WRITTEN_AT + 86400 * 30);

        self::assertSame(['fresh-run' => [CallOutcome::UNKNOWN]], $outcomes->recordedBy);
    }

    /**
     * A run can call more than one write tool, and they share a correlation id
     * because that id is the run's uuid. Judging only the first would let a run
     * whose SECOND write was thrown away be recorded as accepted.
     */
    #[Test]
    public function aRunWhoseSecondWriteWasDiscardedIsNotAccepted(): void
    {
        $writes = new class implements WrittenRecordRepositoryInterface {
            public function findUnansweredCorrelations(int $timestamp, int $limit): array
            {
                return ['run-uuid'];
            }

            public function findWritesForCorrelation(string $correlationId): array
            {
                return [
                    new ObservedWrite('run-uuid', new RecordReference('pages', 42), ObservedOutcomeDeriverTest::writtenAt()),
                    new ObservedWrite('run-uuid', new RecordReference('tt_content', 7), ObservedOutcomeDeriverTest::writtenAt()),
                ];
            }

            public function historyAfter(RecordReference $record, int $writtenAt): array
            {
                return ['later' => []];
            }

            public function recordExists(RecordReference $record): bool
            {
                // The second write's record is gone.
                return $record->table === 'pages';
            }

            public function isDeletion(int $actionType): bool
            {
                return $actionType === 4;
            }
        };

        $deriver = new ObservedOutcomeDeriver($writes, $this->outcomes());

        self::assertSame(['discarded' => 1], $deriver->derive(7, self::WRITTEN_AT + 86400 * 30));
    }

    /**
     * One row per RUN, not per write: the correlation id is the run's uuid, so
     * two writes cannot be told apart in the outcome table.
     */
    #[Test]
    public function aRunWithSeveralWritesIsRecordedOnce(): void
    {
        $outcomes = $this->outcomes();
        $writes   = new class implements WrittenRecordRepositoryInterface {
            public function findUnansweredCorrelations(int $timestamp, int $limit): array
            {
                return ['run-uuid'];
            }

            public function findWritesForCorrelation(string $correlationId): array
            {
                return [
                    new ObservedWrite('run-uuid', new RecordReference('pages', 42), ObservedOutcomeDeriverTest::writtenAt()),
                    new ObservedWrite('run-uuid', new RecordReference('pages', 43), ObservedOutcomeDeriverTest::writtenAt()),
                ];
            }

            public function historyAfter(RecordReference $record, int $writtenAt): array
            {
                return ['later' => []];
            }

            public function recordExists(RecordReference $record): bool
            {
                return true;
            }

            public function isDeletion(int $actionType): bool
            {
                return $actionType === 4;
            }
        };

        (new ObservedOutcomeDeriver($writes, $outcomes))->derive(7, self::WRITTEN_AT + 86400 * 30);

        self::assertSame([CallOutcome::UNKNOWN], $outcomes->recorded);
    }

    /**
     * @param list<int> $later
     *
     * @return array<string, int>
     */
    private function derive(bool $exists, array $later): array
    {
        $deriver = new ObservedOutcomeDeriver($this->writes($exists, $later), $this->outcomes());

        return $deriver->derive(7, self::WRITTEN_AT + 86400 * 30);
    }

    /**
     * @param list<int> $later
     */
    private function writes(bool $exists, array $later): WrittenRecordRepositoryInterface
    {
        return new class ($exists, $later) implements WrittenRecordRepositoryInterface {
            /**
             * @param list<int> $later
             */
            public function __construct(
                private readonly bool $exists,
                private readonly array $later,
            ) {}

            public function findUnansweredCorrelations(int $timestamp, int $limit): array
            {
                return ['run-uuid'];
            }

            public function findWritesForCorrelation(string $correlationId): array
            {
                return [new ObservedWrite('run-uuid', new RecordReference('pages', 42), ObservedOutcomeDeriverTest::writtenAt())];
            }

            public function historyAfter(RecordReference $record, int $writtenAt): array
            {
                return ['later' => $this->later];
            }

            public function recordExists(RecordReference $record): bool
            {
                return $this->exists;
            }

            public function isDeletion(int $actionType): bool
            {
                return $actionType === 4;
            }
        };
    }

    public static function writtenAt(): int
    {
        return self::WRITTEN_AT;
    }

    /**
     * @param array<string, list<CallOutcome>> $existing what a previous derivation already wrote, per correlation
     */
    private function outcomes(array $existing = []): RecordingCallOutcomeRepository
    {
        return new RecordingCallOutcomeRepository($existing);
    }

    /**
     * A run that writes the same record twice leaves a history row for its own
     * second write. That row is the run, not activity after the run.
     */
    #[Test]
    public function aRunsOwnLaterWriteToTheSameRecordIsNotAnEdit(): void
    {
        $writes = new class implements WrittenRecordRepositoryInterface {
            public function findUnansweredCorrelations(int $timestamp, int $limit): array
            {
                return ['run-uuid'];
            }

            public function findWritesForCorrelation(string $correlationId): array
            {
                return [
                    new ObservedWrite('run-uuid', new RecordReference('pages', 42), ObservedOutcomeDeriverTest::writtenAt()),
                    new ObservedWrite('run-uuid', new RecordReference('pages', 42), ObservedOutcomeDeriverTest::writtenAt() + 5),
                ];
            }

            public function historyAfter(RecordReference $record, int $writtenAt): array
            {
                // The only later row is the run's own second write.
                return ['later' => $writtenAt < ObservedOutcomeDeriverTest::writtenAt() + 5 ? [2] : []];
            }

            public function recordExists(RecordReference $record): bool
            {
                return true;
            }

            public function isDeletion(int $actionType): bool
            {
                return $actionType === 4;
            }
        };

        $deriver = new ObservedOutcomeDeriver($writes, $this->outcomes());

        self::assertSame(['unknown' => 1], $deriver->derive(7, self::WRITTEN_AT + 86400 * 30));
    }

    /**
     * The batch is ordered by uuid. A run whose record cannot be read must not
     * block the runs after it on every pass; the error still fails the pass.
     */
    #[Test]
    public function aStorageErrorOnOneRunDoesNotBlockTheOthers(): void
    {
        $outcomes = $this->outcomes();
        $writes   = new class implements WrittenRecordRepositoryInterface {
            public function findUnansweredCorrelations(int $timestamp, int $limit): array
            {
                return ['broken-run', 'healthy-run'];
            }

            public function findWritesForCorrelation(string $correlationId): array
            {
                $table = $correlationId === 'broken-run' ? 'tx_unreadable' : 'pages';

                return [new ObservedWrite($correlationId, new RecordReference($table, 42), ObservedOutcomeDeriverTest::writtenAt())];
            }

            public function historyAfter(RecordReference $record, int $writtenAt): array
            {
                return ['later' => [2]];
            }

            public function recordExists(RecordReference $record): bool
            {
                if ($record->table === 'tx_unreadable') {
                    throw new RuntimeException('connection lost', 1762600001);
                }

                return true;
            }

            public function isDeletion(int $actionType): bool
            {
                return $actionType === 4;
            }
        };

        try {
            (new ObservedOutcomeDeriver($writes, $outcomes))->derive(7, self::WRITTEN_AT + 86400 * 30);
            self::fail('The storage error must still fail the pass.');
        } catch (RuntimeException $exception) {
            self::assertSame(1762600001, $exception->getCode());
        }

        self::assertSame(['healthy-run' => [CallOutcome::EDITED]], $outcomes->recordedBy);
    }
}
