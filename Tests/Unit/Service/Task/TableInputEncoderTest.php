<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Task;

use Netresearch\NrLlm\Domain\Enum\TableInputFormat;
use Netresearch\NrLlm\Service\Task\TableInputEncoder;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * The TOON encoding of table task rows (ADR-286), checked against the encode
 * fixtures of the TOON specification 4.4.0 (Fixtures/Toon/README.md).
 *
 * The fixtures wrap each array in an object key (`items[2]{…}:`); a table task
 * sends a root array, so the key is taken off the header line. Which fixtures
 * must come out as TOON follows from the specification's expected output: a
 * flat tabular header means TOON, list form or a nested field group means
 * the rows are not one flat table and stay JSON.
 */
#[CoversClass(TableInputEncoder::class)]
final class TableInputEncoderTest extends AbstractUnitTestCase
{
    private const FIXTURES = __DIR__ . '/Fixtures/Toon/';

    private const FLAT_TABULAR_HEADER = '/^(?:"(?:[^"\\\\]|\\\\.)*"|[A-Za-z_][A-Za-z0-9_.]*)?\[\d+\]\{[^{}]*\}:$/';

    private const HEADER_KEY = '/^(?:"(?:[^"\\\\]|\\\\.)*"|[A-Za-z_][A-Za-z0-9_.]*)(?=\[)/';

    private TableInputEncoder $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new TableInputEncoder();
    }

    /**
     * @return iterable<string, array{list<array<string, mixed>>, string}>
     */
    public static function tabularFixtures(): iterable
    {
        foreach (self::fixtureCases('arrays-tabular.json') as $case) {
            if (isset($case['options']) || !is_array($case['input']) || count($case['input']) !== 1) {
                continue;
            }

            $rows = array_values($case['input'])[0];
            if (!is_array($rows) || !array_is_list($rows) || !is_string($case['expected'])) {
                continue;
            }

            /** @var list<array<string, mixed>> $rows */
            $lines = explode("\n", $case['expected']);
            if (preg_match(self::FLAT_TABULAR_HEADER, $lines[0]) === 1) {
                $lines[0] = (string) preg_replace(self::HEADER_KEY, '', $lines[0]);
                $expected = self::wrapped(implode("\n", $lines));
            } else {
                $expected = json_encode($rows, JSON_PRETTY_PRINT) ?: '[]';
            }

            yield (string) $case['name'] => [$rows, $expected];
        }
    }

    /**
     * Primitive fixtures as the one cell of a one-row table. The rule for a
     * root string that starts with U+FEFF applies to a root primitive only
     * (§7.2), so that case is left out; arrays and objects are not cells.
     *
     * @return iterable<string, array{list<array<string, mixed>>, string}>
     */
    public static function primitiveFixtures(): iterable
    {
        foreach (self::fixtureCases('primitives.json') as $case) {
            if (is_array($case['input']) || !is_string($case['expected']) || str_contains((string) $case['name'], 'U+FEFF')) {
                continue;
            }

            yield (string) $case['name'] => [
                [['value' => $case['input']]],
                self::wrapped("[1]{value}:\n  " . $case['expected']),
            ];
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    #[Test]
    #[DataProvider('tabularFixtures')]
    #[DataProvider('primitiveFixtures')]
    public function rowsAreEncodedAsTheSpecificationExpects(array $rows, string $expected): void
    {
        self::assertSame($expected, $this->subject->encode($rows, TableInputFormat::TOON));
    }

    #[Test]
    public function theFixturesCoverBothOutcomes(): void
    {
        $toon = 0;
        $json = 0;
        foreach (self::tabularFixtures() as [, $expected]) {
            str_starts_with($expected, TableInputEncoder::TOON_NOTE) ? ++$toon : ++$json;
        }

        // At specification 4.4.0: 7 flat tables, and 8 that are not (4 with
        // a nested field group, 4 in list form). The 16th case sets a pipe
        // delimiter, which a table task does not use.
        self::assertSame([7, 8], [$toon, $json]);
        // 40 primitive cases: 34 scalar inputs, less the root-only U+FEFF one.
        self::assertCount(33, iterator_to_array(self::primitiveFixtures()));
    }

    #[Test]
    public function aHashLeadingFirstCellIsQuotedSoItCannotReadAsAComment(): void
    {
        // Specification 4.x quotes a "#"-leading string (§5.1, §7.2); the
        // 3.3 profile the library uses by default does not, and a decoder of
        // 4.x would drop the row as a comment line.
        $output = $this->subject->encode([['tag' => '#a'], ['tag' => 'b']], TableInputFormat::TOON);

        self::assertStringContainsString("\n  \"#a\"\n  b\n", $output);
    }

    #[Test]
    public function jsonIsTheEncodingWhenTheTaskDoesNotAskForToon(): void
    {
        $rows = [['uid' => 1, 'title' => 'Home'], ['uid' => 2, 'title' => 'About']];

        self::assertSame(json_encode($rows, JSON_PRETTY_PRINT), $this->subject->encode($rows, TableInputFormat::JSON));
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function rowsThatAreNotOneTable(): iterable
    {
        yield 'no rows' => [[]];
        yield 'different keys' => [[['uid' => 1, 'title' => 'Home'], ['uid' => 2, 'header' => 'About']]];
        yield 'a key missing' => [[['uid' => 1, 'title' => 'Home'], ['uid' => 2]]];
        yield 'an empty row' => [[['uid' => 1], []]];
        yield 'rows that are lists' => [[[1, 'Home'], [2, 'About']]];
        yield 'a nested value' => [[['uid' => 1, 'tags' => ['a']], ['uid' => 2, 'tags' => ['b']]]];
        yield 'not a list of rows' => [['a' => ['uid' => 1], 'b' => ['uid' => 2]]];
        yield 'a row that is not an array' => [[['uid' => 1], 'uid']];
    }

    /**
     * @param array<mixed> $rows
     */
    #[Test]
    #[DataProvider('rowsThatAreNotOneTable')]
    public function rowsThatAreNotOneTableStayJson(array $rows): void
    {
        /** @var list<array<string, mixed>> $rows */
        self::assertSame(json_encode($rows, JSON_PRETTY_PRINT), $this->subject->encode($rows, TableInputFormat::TOON));
    }

    #[Test]
    public function rowsWithTheSameKeysInAnotherOrderAreOneTable(): void
    {
        $output = $this->subject->encode([['uid' => 1, 'title' => 'Home'], ['title' => 'About', 'uid' => 2]], TableInputFormat::TOON);

        self::assertSame(self::wrapped("[2]{uid,title}:\n  1,Home\n  2,About"), $output);
    }

    #[Test]
    public function aStringThatIsNotUtf8LeavesTheRowsToJson(): void
    {
        $rows = [['uid' => 1, 'data' => "\xFF\xFE"], ['uid' => 2, 'data' => 'ok']];

        // JSON cannot encode it either; the input is what it was before TOON.
        self::assertSame('[]', $this->subject->encode($rows, TableInputFormat::TOON));
    }

    #[Test]
    public function theFenceIsLongerThanAnyRunOfBackticksInTheRows(): void
    {
        $output = $this->subject->encode([['code' => 'a ```` b'], ['code' => 'c']], TableInputFormat::TOON);

        self::assertStringContainsString("\n`````toon\n", $output);
        self::assertStringEndsWith("\n`````", $output);
    }

    /**
     * @return iterable<string, array{mixed, TableInputFormat}>
     */
    public static function formatOptions(): iterable
    {
        yield 'toon' => ['toon', TableInputFormat::TOON];
        yield 'json' => ['json', TableInputFormat::JSON];
        yield 'missing' => [null, TableInputFormat::JSON];
        yield 'other case' => ['TOON', TableInputFormat::JSON];
        yield 'unknown' => ['csv', TableInputFormat::JSON];
        yield 'not a string' => [true, TableInputFormat::JSON];
    }

    #[Test]
    #[DataProvider('formatOptions')]
    public function theFormatOptionSelectsToonOnlyWhenItSaysSo(mixed $option, TableInputFormat $expected): void
    {
        self::assertSame($expected, TableInputFormat::fromOption($option));
    }

    private static function wrapped(string $toon): string
    {
        return TableInputEncoder::TOON_NOTE . "\n```toon\n" . $toon . "\n```";
    }

    /**
     * @return list<array{name: string, input: mixed, expected: mixed, options?: mixed}>
     */
    private static function fixtureCases(string $file): array
    {
        $content = file_get_contents(self::FIXTURES . $file);
        if ($content === false) {
            throw new RuntimeException('Missing TOON fixture ' . $file, 1794000001);
        }

        /** @var array{tests: list<array{name: string, input: mixed, expected: mixed, options?: mixed}>} $fixture */
        $fixture = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return $fixture['tests'];
    }
}
