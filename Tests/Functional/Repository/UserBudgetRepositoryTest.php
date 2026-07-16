<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Repository;

use Netresearch\NrLlm\Domain\Model\UserBudget;
use Netresearch\NrLlm\Domain\Repository\UserBudgetRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Functional tests for UserBudgetRepository.
 *
 * Fixture layout (UserBudgets.csv): uid 1 and uid 3 both belong to be_user 1
 * (the duplicate exercises first-wins), uid 2 belongs to be_user 2, and uid 4
 * (be_user 3) is hidden — enable fields must keep it invisible even though
 * storage-page filtering is disabled for these global records.
 */
#[CoversClass(UserBudgetRepository::class)]
final class UserBudgetRepositoryTest extends AbstractFunctionalTestCase
{
    private UserBudgetRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('UserBudgets.csv');
        $this->repository = $this->getService(UserBudgetRepository::class);
    }

    #[Test]
    public function findOneByBeUserReturnsTheUsersBudget(): void
    {
        $budget = $this->repository->findOneByBeUser(2);

        self::assertInstanceOf(UserBudget::class, $budget);
        self::assertSame(2, $budget->getBeUser());
        self::assertSame(10, $budget->getMaxRequestsPerDay());
        self::assertSame(2.0, $budget->getMaxCostPerMonth());
    }

    #[Test]
    public function findOneByBeUserRejectsNonPositiveUids(): void
    {
        self::assertNull($this->repository->findOneByBeUser(0));
        self::assertNull($this->repository->findOneByBeUser(-5));
    }

    #[Test]
    public function findOneByBeUserReturnsNullForUserWithoutBudget(): void
    {
        self::assertNull($this->repository->findOneByBeUser(42));
    }

    #[Test]
    public function findOneByBeUserIgnoresHiddenBudgets(): void
    {
        self::assertNull($this->repository->findOneByBeUser(3));
    }

    #[Test]
    public function findByBeUsersBatchLoadsKeyedByUserAndFiltersInvalidUids(): void
    {
        $map = $this->repository->findByBeUsers([1, 2, 2, 0, -7, 42]);

        self::assertSame([1, 2], array_keys($map));
        self::assertSame(1, $map[1]->getBeUser());
        self::assertSame(2, $map[2]->getBeUser());
    }

    #[Test]
    public function findByBeUsersEmptyInputYieldsEmptyMap(): void
    {
        self::assertSame([], $this->repository->findByBeUsers([]));
        self::assertSame([], $this->repository->findByBeUsers([0, -1]));
    }

    #[Test]
    public function findByBeUsersFirstBudgetWinsOnDuplicates(): void
    {
        $map = $this->repository->findByBeUsers([1]);

        // be_user 1 has budgets uid 1 and uid 3 — the first one wins,
        // mirroring findOneByBeUser()'s getFirst().
        $single = $this->repository->findOneByBeUser(1);
        self::assertInstanceOf(UserBudget::class, $single);
        self::assertSame($single->getUid(), $map[1]->getUid());
    }
}
