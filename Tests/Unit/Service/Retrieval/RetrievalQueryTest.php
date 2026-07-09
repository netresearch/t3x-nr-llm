<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Retrieval;

use InvalidArgumentException;
use Netresearch\NrLlm\Service\Retrieval\RetrievalQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RetrievalQuery::class)]
final class RetrievalQueryTest extends TestCase
{
    #[Test]
    public function createTrimsAndKeepsValues(): void
    {
        $query = RetrievalQuery::create('  typo3 migration  ', 5, 'main', 1);

        self::assertSame('typo3 migration', $query->query);
        self::assertSame(5, $query->maxSources);
        self::assertSame('main', $query->siteIdentifier);
        self::assertSame(1, $query->languageId);
    }

    #[Test]
    public function defaultsApply(): void
    {
        $query = RetrievalQuery::create('typo3');

        self::assertSame(8, $query->maxSources);
        self::assertNull($query->siteIdentifier);
        self::assertSame(0, $query->languageId);
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    public static function invalidInputProvider(): array
    {
        return [
            'too short' => ['x', 8, 0],
            'only whitespace' => ['   ', 8, 0],
            'too long' => [str_repeat('a', 201), 8, 0],
            'zero sources' => ['typo3', 0, 0],
            'too many sources' => ['typo3', 21, 0],
            'negative language' => ['typo3', 8, -1],
        ];
    }

    #[Test]
    #[DataProvider('invalidInputProvider')]
    public function outOfRangeInputThrows(string $query, int $maxSources, int $languageId): void
    {
        $this->expectException(InvalidArgumentException::class);

        RetrievalQuery::create($query, $maxSources, null, $languageId);
    }
}
