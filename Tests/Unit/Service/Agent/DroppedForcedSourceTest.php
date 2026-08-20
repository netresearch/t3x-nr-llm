<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent;

use Netresearch\NrLlm\Domain\Enum\DroppedSourceReason;
use Netresearch\NrLlm\Domain\ValueObject\DroppedSource;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Service\Tool\RunTrace;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * The run-visible record of a forced source that did not arrive (ADR-179).
 */
#[CoversClass(RunTrace::class)]
final class DroppedForcedSourceTest extends AbstractUnitTestCase
{
    #[Test]
    public function aDroppedSourceBecomesItsOwnStepOnTheRun(): void
    {
        $trace = new RunTrace();
        $trace->recordDroppedSources([
            new DroppedSource('snippet', 41, DroppedSourceReason::DEACTIVATED),
        ]);

        $steps = $trace->getSteps();
        self::assertCount(1, $steps);
        self::assertSame(RunStep::KIND_DROPPED, $steps[0]->kind);
        self::assertNotNull($steps[0]->droppedSources);
        self::assertSame(41, $steps[0]->droppedSources[0]->uid);
    }

    #[Test]
    public function aRunThatDroppedNothingRecordsNoStep(): void
    {
        // The step's PRESENCE is the signal. An empty step on every run would
        // make the readout noise, and a reader would stop looking at it —
        // which is the state ADR-179 exists to end.
        $trace = new RunTrace();
        $trace->recordDroppedSources([]);

        self::assertSame([], $trace->getSteps());
    }

    #[Test]
    public function theTwoReasonsStayDistinguishable(): void
    {
        // ADR-179 decides this: a deactivated record can be switched back on,
        // a removed one cannot. Folding both into "dropped" would send the
        // reader looking for which it was.
        $trace = new RunTrace();
        $trace->recordDroppedSources([
            new DroppedSource('snippet', 41, DroppedSourceReason::DEACTIVATED),
            new DroppedSource('skill', 77, DroppedSourceReason::GONE),
        ]);

        $dropped = $trace->getSteps()[0]->droppedSources;
        self::assertNotNull($dropped);
        self::assertSame(DroppedSourceReason::DEACTIVATED, $dropped[0]->reason);
        self::assertSame(DroppedSourceReason::GONE, $dropped[1]->reason);
        self::assertNotSame($dropped[0]->reason, $dropped[1]->reason);
    }

    #[Test]
    public function bothKindsAreCarriedAndTellApart(): void
    {
        $trace = new RunTrace();
        $trace->recordDroppedSources([
            new DroppedSource('snippet', 41, DroppedSourceReason::GONE),
            new DroppedSource('skill', 41, DroppedSourceReason::GONE),
        ]);

        $dropped = $trace->getSteps()[0]->droppedSources;
        self::assertNotNull($dropped);
        // Same uid, different kind: the uid alone does not identify a source,
        // so a readout keyed on it would merge two unrelated records.
        self::assertSame('snippet', $dropped[0]->kind);
        self::assertSame('skill', $dropped[1]->kind);
    }
}
