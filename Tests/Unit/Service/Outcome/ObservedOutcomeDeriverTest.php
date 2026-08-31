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

/**
 * The classification ADR-185 decides, at the boundary that matters: what the
 * deriver answers when it cannot tell.
 */
#[CoversClass(ObservedOutcomeDeriver::class)]
final class ObservedOutcomeDeriverTest extends TestCase
{
    private const WRITTEN_AT = 1_700_000_000;

    #[Test]
    public function aRecordNothingTouchedIsAcceptedUnchanged(): void
    {
        self::assertSame(
            ['accepted_unchanged' => 1],
            $this->derive(exists: true, later: [], hasOwnRow: true),
        );
    }

    #[Test]
    public function aRecordSomebodyModifiedIsEdited(): void
    {
        self::assertSame(
            ['edited' => 1],
            $this->derive(exists: true, later: [2], hasOwnRow: true),
        );
    }

    #[Test]
    public function aRecordThatIsGoneIsDiscarded(): void
    {
        self::assertSame(
            ['discarded' => 1],
            $this->derive(exists: false, later: [], hasOwnRow: true),
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
            $this->derive(exists: true, later: [4], hasOwnRow: true),
        );
    }

    /**
     * The case this signal exists to get right. A record whose history has been
     * purged looks EXACTLY like a record nobody touched, and answering
     * "accepted" there would infer approval of the model from an absence of
     * evidence — the one thing ADR-185 forbids by name.
     */
    #[Test]
    public function aPurgedHistoryIsUnknownAndNeverAccepted(): void
    {
        $counts = $this->derive(exists: true, later: [], hasOwnRow: false);

        self::assertSame(['unknown' => 1], $counts);
        self::assertArrayNotHasKey('accepted_unchanged', $counts);
    }

    /**
     * The trap in proving that our write's history row survived: an OLDER row
     * that outlived the trim answers "yes, there is a row at or before the
     * write" for a write whose own row is long gone. The oldest RETAINED row is
     * what proves it, and only that.
     */
    #[Test]
    public function anOlderSurvivingRowDoesNotProveOurWriteIsStillInTheHistory(): void
    {
        $writes = new class implements WrittenRecordRepositoryInterface {
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
                // Everything up to a day AFTER the write was trimmed away; what
                // remains starts later than our write.
                return ['later' => [], 'oldestRetained' => $writtenAt + 86400];
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

        self::assertSame(['unknown' => 1], (new ObservedOutcomeDeriver($writes, $this->outcomes()))->derive(7, self::WRITTEN_AT + 86400 * 30));
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
                return ['later' => [], 'oldestRetained' => 0];
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
                return ['later' => [], 'oldestRetained' => $writtenAt - 1];
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
                return ['later' => [], 'oldestRetained' => $writtenAt - 1];
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

        self::assertSame([CallOutcome::ACCEPTED_UNCHANGED], $outcomes->recorded);
    }

    /**
     * @param list<int> $later
     *
     * @return array<string, int>
     */
    private function derive(bool $exists, array $later, bool $hasOwnRow): array
    {
        $deriver = new ObservedOutcomeDeriver($this->writes($exists, $later, $hasOwnRow), $this->outcomes());

        return $deriver->derive(7, self::WRITTEN_AT + 86400 * 30);
    }

    /**
     * @param list<int> $later
     */
    private function writes(bool $exists, array $later, bool $hasOwnRow): WrittenRecordRepositoryInterface
    {
        return new class ($exists, $later, $hasOwnRow) implements WrittenRecordRepositoryInterface {
            /**
             * @param list<int> $later
             */
            public function __construct(
                private readonly bool $exists,
                private readonly array $later,
                private readonly bool $hasOwnRow,
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
                return ['later' => $this->later, 'oldestRetained' => $this->hasOwnRow ? ObservedOutcomeDeriverTest::writtenAt() - 1 : ObservedOutcomeDeriverTest::writtenAt() + 1];
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
     * @param list<CallOutcome> $existing
     */
    private function outcomes(array $existing = []): RecordingCallOutcomeRepository
    {
        return new RecordingCallOutcomeRepository($existing);
    }
}
