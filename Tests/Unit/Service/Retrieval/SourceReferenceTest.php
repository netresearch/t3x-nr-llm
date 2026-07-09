<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Retrieval;

use Netresearch\NrLlm\Service\Retrieval\SourceReference;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SourceReference::class)]
final class SourceReferenceTest extends TestCase
{
    #[Test]
    public function parsesBackendAndPartsAndRoundTrips(): void
    {
        $reference = SourceReference::parse('database:123:0');

        self::assertNotNull($reference);
        self::assertSame('database', $reference->backend);
        self::assertSame(['123', '0'], $reference->parts);
        self::assertSame('database:123:0', $reference->toString());
    }

    #[Test]
    public function parsesIndexedSearchPhashPart(): void
    {
        $reference = SourceReference::parse('indexed_search:0a1b2c3d4e5f60718293a4b5c6d7e8f9');

        self::assertNotNull($reference);
        self::assertSame('indexed_search', $reference->backend);
        self::assertSame(['0a1b2c3d4e5f60718293a4b5c6d7e8f9'], $reference->parts);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedInputProvider(): array
    {
        return [
            'empty' => [''],
            'no parts' => ['database'],
            'leading digit backend' => ['1db:1'],
            'uppercase backend' => ['Database:1'],
            'sql-ish payload' => ["database:1' OR '1"],
            'path traversal' => ['database:../etc/passwd'],
            'whitespace' => ['database: 1'],
            'too many parts' => ['a:b:c:d:e:f'],
            'overlong' => ['database:' . str_repeat('a', 200)],
            'empty part' => ['database::1'],
        ];
    }

    #[Test]
    #[DataProvider('rejectedInputProvider')]
    public function malformedSourceIdsAreRejected(string $sourceId): void
    {
        self::assertNull(SourceReference::parse($sourceId));
    }
}
