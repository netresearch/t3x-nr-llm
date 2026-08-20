<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent;

use Netresearch\NrLlm\Domain\Enum\DroppedSourceReason;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Domain\Repository\SkillRepository;
use Netresearch\NrLlm\Domain\ValueObject\DroppedSource;
use Netresearch\NrLlm\Service\Agent\AgentRunRequestCodec;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

/**
 * Which forced sources a dequeue reports as dropped, and why (ADR-179).
 *
 * Against real rows, because the whole decision rests on resolving the same
 * uid through two lookups and comparing the answers. A double cannot show that
 * — it would return whatever the test told it to.
 */
#[CoversClass(AgentRunRequestCodec::class)]
final class DroppedForcedSourceDetectionTest extends AbstractFunctionalTestCase
{
    private AgentRunRequestCodec $codec;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('SkillsByUid.csv');
        $this->codec = new AgentRunRequestCodec(
            $this->get(LlmConfigurationRepository::class),
            $this->get(SkillRepository::class),
            $this->get(PromptSnippetRepository::class),
        );
    }

    /**
     * @param list<int> $skillUids
     *
     * @return list<DroppedSource>
     */
    private function droppedFor(array $skillUids): array
    {
        // Resolved the way the rehydration resolves them, then handed over:
        // the production path passes the records it already has rather than
        // re-querying. A test that re-queried would exercise a comparison
        // production does not make — and the duplicate query is what
        // ToolLoopServiceAssemblyOrderTest caught in the first version.
        $arrived = $skillUids === [] ? [] : $this->get(SkillRepository::class)->findByUids($skillUids);

        $method = new ReflectionMethod(AgentRunRequestCodec::class, 'droppedSources');

        /** @var list<DroppedSource> $dropped */
        $dropped = $method->invoke($this->codec, $skillUids, $arrived, [], []);

        return $dropped;
    }

    #[Test]
    public function aDeactivatedSourceIsReportedAsDeactivated(): void
    {
        // uid 23 exists and is disabled in the fixture.
        $dropped = $this->droppedFor([23]);

        self::assertCount(1, $dropped);
        self::assertSame(23, $dropped[0]->uid);
        self::assertSame('skill', $dropped[0]->kind);
        self::assertSame(DroppedSourceReason::DEACTIVATED, $dropped[0]->reason);
    }

    #[Test]
    public function aVanishedSourceIsReportedAsGone(): void
    {
        // Nothing with this uid exists. From the run's side a deleted record
        // and a uid that never existed are one event: it asked for something
        // it did not get, and nothing remains to say which.
        $dropped = $this->droppedFor([999999]);

        self::assertCount(1, $dropped);
        self::assertSame(DroppedSourceReason::GONE, $dropped[0]->reason);
    }

    #[Test]
    public function aDeletedSourceIsGoneAndNotDeactivated(): void
    {
        // uid 24 is deleted=1. Both lookups keep the deleted restriction, so
        // it resolves through neither — which is the only reason the two
        // reasons stay distinguishable at all.
        $dropped = $this->droppedFor([24]);

        self::assertSame(DroppedSourceReason::GONE, $dropped[0]->reason);
    }

    #[Test]
    public function anEnabledSourceIsNotReported(): void
    {
        // uid 22 is enabled: it arrives, so there is nothing to say about it.
        self::assertSame([], $this->droppedFor([22]));
    }

    #[Test]
    public function aHiddenButEnabledSourceArrivesAndIsNotReported(): void
    {
        // uid 25 carries hidden=1. The repositories ignore enable fields, so
        // `hidden` is FormEngine visibility and not a runtime gate — reporting
        // it as dropped would tell an operator to fix something that is not
        // broken.
        self::assertSame([], $this->droppedFor([25]));
    }

    #[Test]
    public function theOrderFollowsTheRequestAndMixedCasesStaySeparate(): void
    {
        $dropped = $this->droppedFor([23, 22, 999999]);

        self::assertCount(2, $dropped, 'the enabled uid 22 must not appear');
        self::assertSame([23, 999999], array_map(static fn(DroppedSource $d): int => $d->uid, $dropped));
        self::assertSame(DroppedSourceReason::DEACTIVATED, $dropped[0]->reason);
        self::assertSame(DroppedSourceReason::GONE, $dropped[1]->reason);
    }

    #[Test]
    public function anEmptyRequestCostsNoQuery(): void
    {
        self::assertSame([], $this->droppedFor([]));
    }
}
