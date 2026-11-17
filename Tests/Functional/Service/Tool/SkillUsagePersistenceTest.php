<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\AgentEventKind;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Enum\SkillApprovalState;
use Netresearch\NrLlm\Domain\Enum\SkillInclusion;
use Netresearch\NrLlm\Domain\Enum\SkillRole;
use Netresearch\NrLlm\Domain\Enum\SkillUsageState;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunEvent;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\SkillUsageEntry;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\AgentRunRepository;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Tests\Fixture\AgentRunRepositoryFactory;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * A run's skill usage record (ADR-321) against the real schema: it is stored
 * as it is at every privacy level, outlives the end of the run, which clears
 * the resumable state and with it the pins, and goes with the run's other
 * events when retention purges the run.
 */
#[CoversClass(AgentRunPersister::class)]
#[CoversClass(AgentRunRepository::class)]
final class SkillUsagePersistenceTest extends AbstractFunctionalTestCase
{
    private AgentRunRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->repository = AgentRunRepositoryFactory::create($connectionPool, $this->get(AgentStateCodec::class));
    }

    /**
     * @return iterable<string, array{PrivacyLevel}>
     */
    public static function levels(): iterable
    {
        foreach (PrivacyLevel::cases() as $level) {
            yield $level->value => [$level];
        }
    }

    #[Test]
    #[DataProvider('levels')]
    public function theRecordOutlivesTheRunUnchangedAtEveryPrivacyLevel(PrivacyLevel $level): void
    {
        $persister = new AgentRunPersister($this->repository, FixedPrivacyPolicy::filterAt($level), new NullLogger());
        $handle    = $persister->begin(null, 1);
        self::assertNotNull($handle);

        $entries = [
            new SkillUsageEntry(3, 1, '1:' . str_repeat('a', 64), 'house-rules', SkillRole::INSTRUCTION, SkillInclusion::COMPOSED, SkillUsageState::PASSED, SkillApprovalState::APPROVED, 12),
            new SkillUsageEntry(7, 1, '1:' . str_repeat('b', 64), 'style', SkillRole::INSTRUCTION, SkillInclusion::LOADED, SkillUsageState::LOADED, SkillApprovalState::APPROVED, 13),
        ];
        self::assertTrue($persister->recordStep($handle, new RunStep(kind: RunStep::KIND_SKILLS, round: 2, durationMs: 0.0, skillUsage: $entries)));
        self::assertTrue($persister->settleCompleted($handle, new ToolLoopResult('done', [], 2, false, UsageStatistics::fromTokens(1, 1))));

        $events = array_values(array_filter(
            $this->repository->findEvents($handle->runUid),
            static fn (AgentRunEvent $e): bool => $e->kind === AgentEventKind::SKILL_USAGE->value,
        ));
        self::assertCount(1, $events);
        self::assertSame(AgentEventKind::SKILL_USAGE, $events[0]->kindEnum());
        self::assertSame(2, $events[0]->round);
        self::assertSame(
            array_map(static fn (SkillUsageEntry $e): array => $e->toArray(), $entries),
            $events[0]->payload['skillUsage'] ?? null,
        );
        self::assertEquals($entries, SkillUsageEntry::listFrom($events[0]->payload['skillUsage'] ?? null));
    }

    #[Test]
    public function retentionPurgesTheRecordWithTheRun(): void
    {
        $persister = new AgentRunPersister($this->repository, FixedPrivacyPolicy::filterAt(PrivacyLevel::METADATA), new NullLogger());
        $handle    = $persister->begin(null, 1);
        self::assertNotNull($handle);
        self::assertTrue($persister->recordStep($handle, new RunStep(kind: RunStep::KIND_SKILLS, round: 1, durationMs: 0.0, skillUsage: [
            new SkillUsageEntry(3, 1, '1:' . str_repeat('a', 64), 'house-rules', SkillRole::INSTRUCTION, SkillInclusion::COMPOSED, SkillUsageState::PASSED, SkillApprovalState::APPROVED, 12),
        ])));
        $persister->settleCompleted($handle, new ToolLoopResult('done', [], 1, false, UsageStatistics::fromTokens(1, 1)));
        self::assertCount(1, $this->repository->findEvents($handle->runUid), 'the record exists before the purge');

        $this->repository->purgeOlderThan(time() + 1);

        self::assertSame([], $this->repository->findEvents($handle->runUid));
    }
}
