<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Retrieval;

use Netresearch\NrLlm\Service\Retrieval\ReciprocalRankFusion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReciprocalRankFusion::class)]
final class ReciprocalRankFusionTest extends TestCase
{
    #[Test]
    public function singleListPreservesOrder(): void
    {
        self::assertSame(['a', 'b', 'c'], (new ReciprocalRankFusion())->fuse([['a', 'b', 'c']], 60));
    }

    #[Test]
    public function disjointListsInterleaveByRank(): void
    {
        // rank-1 of each arm ties (1/61) and keeps list order (a before x); then rank-2 (b before y).
        self::assertSame(['a', 'x', 'b', 'y'], (new ReciprocalRankFusion())->fuse([['a', 'b'], ['x', 'y']], 60));
    }

    #[Test]
    public function sharedKeySumsBothContributions(): void
    {
        // b: dense rank2 (1/62) + sparse rank1 (1/61) = 0.0325 beats a (1/61) and c (1/62).
        self::assertSame(['b', 'a', 'c'], (new ReciprocalRankFusion())->fuse([['a', 'b'], ['b', 'c']], 60));
    }

    #[Test]
    public function smallerKLetsTopRankDominate(): void
    {
        self::assertSame('b', (new ReciprocalRankFusion())->fuse([['a', 'b'], ['b', 'c']], 1)[0]);
    }

    #[Test]
    public function weightBiasesOneArm(): void
    {
        // Both rank-1, but the dense arm's weight (5) outranks the sparse arm's (1).
        self::assertSame(['a', 'x'], (new ReciprocalRankFusion())->fuse([['a'], ['x']], 60, [5.0, 1.0]));
    }

    #[Test]
    public function missingWeightDefaultsToOne(): void
    {
        // Only the first arm has an explicit weight; the second falls back to 1.0
        // and its rank-1 key ties with an explicit-1.0 arm's rank-1 key.
        self::assertSame(['a', 'x'], (new ReciprocalRankFusion())->fuse([['a'], ['x']], 60, [1.0]));
    }

    #[Test]
    public function extraWeightsAreIgnored(): void
    {
        self::assertSame(['a', 'x'], (new ReciprocalRankFusion())->fuse([['a'], ['x']], 60, [5.0, 1.0, 9.0]));
    }

    #[Test]
    public function deduplicatesWithinList(): void
    {
        self::assertSame(['a', 'b'], (new ReciprocalRankFusion())->fuse([['a', 'a', 'b']], 60));
    }

    #[Test]
    public function duplicateKeepsFirstRankWithinList(): void
    {
        // The duplicate 'b' at position 3 is skipped without consuming a rank,
        // so c is rank 3 and d rank 4 — not demoted to 4 and 5.
        self::assertSame(
            ['a', 'b', 'c', 'd'],
            (new ReciprocalRankFusion())->fuse([['a', 'b', 'b', 'c', 'd']], 60),
        );
    }

    #[Test]
    public function emptyListsYieldEmpty(): void
    {
        self::assertSame([], (new ReciprocalRankFusion())->fuse([[], []]));
    }

    #[Test]
    public function noListsYieldEmpty(): void
    {
        self::assertSame([], (new ReciprocalRankFusion())->fuse([]));
    }

    #[Test]
    public function numericStringKeysComeBackAsInts(): void
    {
        // PHP array-key coercion in the score accumulator: '42' becomes int 42.
        self::assertSame([42], (new ReciprocalRankFusion())->fuse([['42']]));
    }

    #[Test]
    public function nonPositiveKIsClamped(): void
    {
        // k=0 would divide by rank alone; clamped to 1, so this must not error and stays ordered.
        self::assertSame(['a', 'b'], (new ReciprocalRankFusion())->fuse([['a', 'b']], 0));
    }

    #[Test]
    public function negativeKIsClamped(): void
    {
        // k=-1 would make (k + rank) zero at rank 1; clamped to 1 avoids division by zero.
        self::assertSame(['a', 'b'], (new ReciprocalRankFusion())->fuse([['a', 'b']], -1));
    }
}
