<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordReference::class)]
final class RecordReferenceTest extends TestCase
{
    #[Test]
    public function carriesTheTableAndTheUid(): void
    {
        $reference = new RecordReference('pages', 42);

        self::assertSame('pages', $reference->table);
        self::assertSame(42, $reference->uid);
        self::assertSame('pages:42', (string)$reference);
        self::assertSame(['table' => 'pages', 'uid' => 42], $reference->toArray());
    }

    /**
     * A reference is an identity. One that names no table, or a uid no record
     * can have, identifies nothing — and would be persisted as an audit row
     * pointing at nowhere, which reads as a recorded write rather than as the
     * defect it is.
     *
     * The table is also the one string the tool loop does NOT bound on its way
     * out, so everything the loop would otherwise have coerced — whitespace,
     * invalid UTF-8, unbounded length — is refused here instead.
     */
    #[Test]
    #[DataProvider('unusableIdentities')]
    public function refusesAnIdentityThatNamesNoRecord(string $table, int $uid): void
    {
        $this->expectException(InvalidArgumentException::class);

        $reference = new RecordReference($table, $uid);

        self::fail('A reference was built as ' . $reference . ', which names no record.');
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function unusableIdentities(): iterable
    {
        yield 'empty table'        => ['', 1];
        yield 'whitespace table'   => ['   ', 1];
        yield 'padded table'       => [' pages ', 1];
        yield 'quoted table'       => ['`pages`', 1];
        yield 'qualified table'    => ['db.pages', 1];
        yield 'sql fragment'       => ['pages WHERE 1=1', 1];
        yield 'invalid utf-8'      => ["pages\xFF", 1];
        yield 'newline'            => ["pages\n", 1];
        yield 'longer than 64'     => [str_repeat('a', 65), 1];
        yield 'zero uid'           => ['pages', 0];
        yield 'negative uid'       => ['pages', -1];
    }
}
