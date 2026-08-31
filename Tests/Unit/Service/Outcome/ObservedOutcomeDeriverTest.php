<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Outcome;

use Netresearch\NrLlm\Domain\Enum\CallOutcome;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Service\Outcome\CallOutcomeRepositoryInterface;
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

            public function findWritesSettledBefore(int $timestamp, int $limit): array
            {
                $this->settledAt = $timestamp;

                return [];
            }

            public function historyAfter(RecordReference $record, int $writtenAt): array
            {
                return ['later' => [], 'hasOwnRow' => true];
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
     * The answer is final once the window has closed, so a second pass spends
     * two reads to reach the value it already has.
     */
    #[Test]
    public function aWriteThatAlreadyCarriesAnObservedOutcomeIsSkipped(): void
    {
        $outcomes = $this->outcomes([CallOutcome::EDITED]);

        $deriver = new ObservedOutcomeDeriver($this->writes(true, [], true), $outcomes);

        self::assertSame([], $deriver->derive(7, self::WRITTEN_AT + 86400 * 30));
    }

    /**
     * An explicit rating is a different source and must not stop the observed
     * one from being derived — ADR-176 keeps the two apart precisely so one
     * cannot stand in for the other.
     */
    #[Test]
    public function anExplicitRatingDoesNotSuppressTheObservedOne(): void
    {
        $outcomes = $this->outcomes([CallOutcome::HELPFUL]);

        $deriver = new ObservedOutcomeDeriver($this->writes(true, [], true), $outcomes);
        $deriver->derive(7, self::WRITTEN_AT + 86400 * 30);

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

            public function findWritesSettledBefore(int $timestamp, int $limit): array
            {
                return [new ObservedWrite('run-uuid', new RecordReference('pages', 42), ObservedOutcomeDeriverTest::writtenAt())];
            }

            public function historyAfter(RecordReference $record, int $writtenAt): array
            {
                return ['later' => $this->later, 'hasOwnRow' => $this->hasOwnRow];
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
    private function outcomes(array $existing = []): CallOutcomeRepositoryInterface
    {
        return new class ($existing) implements CallOutcomeRepositoryInterface {
            /** @var list<CallOutcome> */
            public array $recorded = [];

            /**
             * @param list<CallOutcome> $existing
             */
            public function __construct(private readonly array $existing) {}

            public function record(string $correlationId, CallOutcome $outcome): void
            {
                $this->recorded[] = $outcome;
            }

            public function findByCorrelation(string $correlationId): array
            {
                return $this->existing;
            }

            public function purgeOlderThan(int $timestamp): int
            {
                return 0;
            }
        };
    }
}
