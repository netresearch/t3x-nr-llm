<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Repository;

use Netresearch\NrLlm\Domain\Enum\CallOutcome;
use Netresearch\NrLlm\Domain\Enum\CallOutcomeSource;
use Netresearch\NrLlm\Service\Outcome\CallOutcomeRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The per-call outcome row (ADR-176), against a real schema.
 */
#[CoversClass(CallOutcomeRepository::class)]
final class CallOutcomeRepositoryTest extends AbstractFunctionalTestCase
{
    private const CALL = '11111111-2222-4333-8444-555555555555';

    private CallOutcomeRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $repository = $this->get(CallOutcomeRepository::class);
        self::assertInstanceOf(CallOutcomeRepository::class, $repository);
        $this->repository = $repository;
    }

    #[Test]
    public function anOutcomeIsWrittenAndReadBack(): void
    {
        $this->repository->record(self::CALL, CallOutcome::HELPFUL);

        self::assertSame([CallOutcome::HELPFUL], $this->repository->findByCorrelation(self::CALL));
    }

    #[Test]
    public function aSecondRatingReplacesTheFirstRatherThanAddingToIt(): void
    {
        // The rater changed their mind. Two rows would leave the readout to
        // guess which one counts, which is the whole reason the repository
        // clears the same source first.
        $this->repository->record(self::CALL, CallOutcome::HELPFUL);
        $this->repository->record(self::CALL, CallOutcome::NOT_HELPFUL);

        self::assertSame([CallOutcome::NOT_HELPFUL], $this->repository->findByCorrelation(self::CALL));
        self::assertSame(1, $this->rowCount());
    }

    #[Test]
    public function theTwoSourcesDoNotDisplaceEachOther(): void
    {
        // ADR-176 keeps them apart, so an observed outcome must not clear an
        // explicit one. Asserted now rather than when the observed writer
        // arrives, because that writer would otherwise be the thing that
        // discovers it.
        $this->repository->record(self::CALL, CallOutcome::HELPFUL);
        $this->repository->record(self::CALL, CallOutcome::EDITED);

        self::assertSame(
            [CallOutcome::HELPFUL, CallOutcome::EDITED],
            $this->repository->findByCorrelation(self::CALL),
        );
    }

    #[Test]
    public function anEmptyCorrelationIdWritesNothing(): void
    {
        // Every send has a correlation id, but a caller that lost it must not
        // create a row keyed on the empty string — every such row would look
        // like the same call.
        $this->repository->record('', CallOutcome::HELPFUL);

        self::assertSame(0, $this->rowCount());
        self::assertSame([], $this->repository->findByCorrelation(''));
    }

    #[Test]
    public function anUnknownStoredValueIsSkippedRatherThanFatal(): void
    {
        $this->connection()->insert('tx_nrllm_call_outcome', [
            'pid'            => 0,
            'correlation_id' => self::CALL,
            'outcome'        => 'from_a_newer_version',
            'crdate'         => 1_700_000_000,
            'tstamp'         => 1_700_000_000,
        ]);
        $this->repository->record(self::CALL, CallOutcome::HELPFUL);

        self::assertSame([CallOutcome::HELPFUL], $this->repository->findByCorrelation(self::CALL));
    }

    #[Test]
    public function everyCaseAnswersExactlyOneSource(): void
    {
        // The source is derived, not stored. If a case ever answered neither,
        // record() would clear nothing and the replace-in-place rule above
        // would silently stop holding.
        foreach (CallOutcome::cases() as $case) {
            self::assertContains($case->source(), CallOutcomeSource::cases());
        }

        $explicit = array_filter(CallOutcome::cases(), static fn(CallOutcome $c): bool => $c->source() === CallOutcomeSource::EXPLICIT);
        $observed = array_filter(CallOutcome::cases(), static fn(CallOutcome $c): bool => $c->source() === CallOutcomeSource::OBSERVED);

        self::assertNotSame([], $explicit);
        self::assertNotSame([], $observed);
        self::assertCount(count(CallOutcome::cases()), [...$explicit, ...$observed]);
    }

    private function connection(): Connection
    {
        return $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrllm_call_outcome');
    }

    private function rowCount(): int
    {
        return $this->connection()->count('uid', 'tx_nrllm_call_outcome', []);
    }
}
