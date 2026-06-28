<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Repository;

use Netresearch\NrLlm\Domain\Enum\SupportStatus;
use Netresearch\NrLlm\Domain\Repository\SkillRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(SkillRepository::class)]
final class SkillRepositoryTest extends AbstractFunctionalTestCase
{
    private SkillRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('Skills.csv');
        $repository = $this->get(SkillRepository::class);
        self::assertInstanceOf(SkillRepository::class, $repository);
        $this->repository = $repository;
    }

    #[Test]
    public function findBySourceAndIdentifierReturnsMatch(): void
    {
        $skill = $this->repository->findBySourceAndIdentifier(1, '1:SKILL.md');
        self::assertNotNull($skill);
        self::assertSame('Example', $skill->getName());
        self::assertSame(SupportStatus::FULL, $skill->getSupportStatusEnum());
    }

    #[Test]
    public function findBySourceReturnsAllForSource(): void
    {
        self::assertCount(2, $this->repository->findBySource(1));
        self::assertCount(0, $this->repository->findBySource(999));
    }
}
