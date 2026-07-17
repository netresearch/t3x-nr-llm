<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Rerank;

use Netresearch\NrLlm\Service\Rerank\NullReranker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NullReranker::class)]
final class NullRerankerTest extends TestCase
{
    #[Test]
    public function returnsOneUniformZeroScoreEntryPerCandidateInInputOrder(): void
    {
        $result = (new NullReranker())->rerank('any query', [
            ['id' => 'b', 'text' => 'second by cosine'],
            ['id' => 'a', 'text' => 'first by cosine'],
        ]);

        self::assertSame([
            ['id' => 'b', 'score' => 0.0],
            ['id' => 'a', 'score' => 0.0],
        ], $result);
    }

    #[Test]
    public function emptyCandidatesReturnEmptyList(): void
    {
        self::assertSame([], (new NullReranker())->rerank('query', []));
    }
}
