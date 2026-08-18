<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Repository;

use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Repository\SkillRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * The uid lookups a forced skill set is rebuilt through (ADR-175).
 *
 * The fixture is deliberately its own: the names sort in the opposite
 * direction to the uids, so an assertion on order distinguishes the caller's
 * order from $defaultOrderings instead of passing under both.
 */
#[CoversClass(SkillRepository::class)]
final class SkillRepositoryUidLookupTest extends AbstractFunctionalTestCase
{
    private SkillRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('SkillsByUid.csv');
        $repository = $this->get(SkillRepository::class);
        self::assertInstanceOf(SkillRepository::class, $repository);
        $this->repository = $repository;
    }

    /**
     * @param list<Skill> $skills
     *
     * @return list<string>
     */
    private function namesOf(array $skills): array
    {
        return array_values(array_map(static fn(Skill $skill): string => $skill->getName(), $skills));
    }

    // findByUids() — the lookup for new composition

    #[Test]
    public function findByUidsPreservesTheCallersOrderAndNotTheNameOrder(): void
    {
        // 21 is "Zulu", 22 is "Alpha": name ASC would invert this.
        self::assertSame(['Zulu', 'Alpha'], $this->namesOf($this->repository->findByUids([21, 22])));
        self::assertSame(['Alpha', 'Zulu'], $this->namesOf($this->repository->findByUids([22, 21])));
    }

    #[Test]
    public function findByUidsSkipsDisabledSkills(): void
    {
        self::assertSame(['Alpha'], $this->namesOf($this->repository->findByUids([23, 22])));
    }

    #[Test]
    public function findByUidsSilentlySkipsUnknownUids(): void
    {
        self::assertSame(['Alpha'], $this->namesOf($this->repository->findByUids([999, 22, 12345])));
    }

    #[Test]
    public function findByUidsIgnoresTheHiddenFlag(): void
    {
        // setIgnoreEnableFields(true): `hidden` is FormEngine visibility, not a
        // runtime guard. `enabled` is the extension's own field and is the one
        // this lookup filters on.
        self::assertSame(['Echo'], $this->namesOf($this->repository->findByUids([25])));
    }

    #[Test]
    public function findByUidsSkipsDeletedSkills(): void
    {
        self::assertSame(['Alpha'], $this->namesOf($this->repository->findByUids([24, 22])));
    }

    #[Test]
    public function findByUidsReturnsEmptyListForEmptyInput(): void
    {
        self::assertSame([], $this->repository->findByUids([]));
    }

    // findExistingByUids() — the lookup for text already in a transcript

    #[Test]
    public function findExistingByUidsResolvesDisabledSkills(): void
    {
        // The ADR-175 difference to findByUids(): uid 23 is disabled and still
        // resolves, because a resumed run is re-gating text it already sent.
        self::assertSame(['Bravo', 'Alpha'], $this->namesOf($this->repository->findExistingByUids([23, 22])));
    }

    #[Test]
    public function findExistingByUidsPreservesTheCallersOrder(): void
    {
        self::assertSame(['Zulu', 'Alpha'], $this->namesOf($this->repository->findExistingByUids([21, 22])));
    }

    #[Test]
    public function findExistingByUidsStillSkipsDeletedSkills(): void
    {
        self::assertSame(['Alpha'], $this->namesOf($this->repository->findExistingByUids([24, 22])));
    }

    #[Test]
    public function findExistingByUidsReturnsEmptyListForEmptyInput(): void
    {
        self::assertSame([], $this->repository->findExistingByUids([]));
    }
}
