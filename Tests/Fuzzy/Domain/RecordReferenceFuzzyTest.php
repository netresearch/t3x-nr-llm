<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fuzzy\Domain;

use Eris\Generator;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Tests\Fuzzy\AbstractFuzzyTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * The assertion twin of {@see RecordReference}'s two validation rules (ADR-182).
 *
 * A reference is an IDENTITY that ends up in the audit stream and, later, in the
 * join behind the observed outcome. A row pointing at nowhere reads as a
 * recorded write, so the boundary matters more than the happy path: everything
 * at or below zero is refused, everything above it is carried through unchanged.
 */
#[CoversNothing] // Domain/ValueObject excluded from coverage in phpunit.xml
class RecordReferenceFuzzyTest extends AbstractFuzzyTestCase
{
    #[Test]
    public function anyPositiveUidWithANamedTableIsCarriedThrough(): void
    {
        $this
            ->forAll(
                Generator\elements(['pages', 'tt_content', 'sys_file_metadata', 'sys_file_reference']), // @phpstan-ignore function.notFound
                Generator\choose(1, 2147483647), // @phpstan-ignore function.notFound
            )
            ->then(function (string $table, int $uid): void {
                $reference = new RecordReference($table, $uid);

                $this->assertSame($table, $reference->table);
                $this->assertSame($uid, $reference->uid);
                $this->assertSame($table . ':' . $uid, (string)$reference);
                $this->assertSame(['table' => $table, 'uid' => $uid], $reference->toArray());
            });
    }

    #[Test]
    public function noUidAtOrBelowZeroIsAccepted(): void
    {
        $this
            ->forAll(
                Generator\choose(-2147483648, 0), // @phpstan-ignore function.notFound
            )
            ->then(function (int $uid): void {
                try {
                    $reference = new RecordReference('pages', $uid);
                    $this->fail(sprintf('A reference was built as %s for uid %d, which names no record.', $reference, $uid));
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            });
    }

    /**
     * The table is the one string the tool loop does not bound on the way out,
     * so the refusal has to hold for everything the loop would have coerced:
     * whitespace, quoting, qualification, SQL, invalid UTF-8, excess length.
     */
    #[Test]
    public function noTableThatIsNotADatabaseIdentifierIsAccepted(): void
    {
        $this
            ->forAll(
                Generator\elements([ // @phpstan-ignore function.notFound
                    '', ' ', '  ', "\t", "\n", " \t\n ",
                    ' pages', 'pages ', '`pages`', '"pages"', 'db.pages', 'pages-1',
                    'pages;DROP TABLE pages', 'pages WHERE 1=1', 'pages*', "pages\0",
                    "pages\n", "pages\r\n", "\npages",
                    "pages\xFF", 'pä ges', str_repeat('a', 65), str_repeat('a', 4096),
                ]),
            )
            ->then(function (string $table): void {
                try {
                    $reference = new RecordReference($table, 1);
                    $this->fail('A reference was built as ' . $reference . ' for a table name that is not an identifier.');
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            });
    }
}
