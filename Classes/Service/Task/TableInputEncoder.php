<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Task;

use HelgeSverre\Toon\EncodeOptions;
use HelgeSverre\Toon\SpecVersion;
use HelgeSverre\Toon\Toon;
use Netresearch\NrLlm\Domain\Enum\TableInputFormat;
use Throwable;

/**
 * Turns the rows of a table task into the text the model reads (ADR-286).
 *
 * JSON is the encoding table tasks have always used, and stays byte for byte
 * what it was. TOON (Token-Oriented Object Notation, specification 4.4) is
 * used only when the task asks for it AND the rows form one table: a
 * non-empty list of objects that share the same set of keys and hold only
 * primitive values. Anything else falls back to JSON, as does a row the
 * library refuses (a string that is not valid UTF-8).
 *
 * The TOON text is preceded by one line that says how to read it and is put
 * in a `toon` code fence, as the TOON README recommends for model input.
 */
final readonly class TableInputEncoder
{
    /**
     * The line in front of the TOON text. Public so tests can name exactly
     * what is sent.
     */
    public const TOON_NOTE = 'The rows below are in TOON format: the header [N]{field,...}: gives the number of rows and the field names once, then each line is one row with its values in field order, separated by commas.';

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function encode(array $rows, TableInputFormat $format): string
    {
        if ($format === TableInputFormat::TOON && $this->isTable($rows)) {
            try {
                return $this->wrap(Toon::encode($rows, new EncodeOptions(version: SpecVersion::V4_4)));
            } catch (Throwable) {
                // The library refuses a string that is not valid UTF-8. The
                // JSON encoding below decides what such rows become, as it
                // did before TOON existed.
            }
        }

        return json_encode($rows, JSON_PRETTY_PRINT) ?: '[]';
    }

    /**
     * Whether the rows meet TOON's tabular form with primitive columns only
     * (specification §9.3): every element an object with at least one key,
     * the same set of keys in each, and every value a primitive.
     *
     * @param array<mixed> $rows
     */
    private function isTable(array $rows): bool
    {
        if ($rows === [] || !array_is_list($rows)) {
            return false;
        }

        $keys = null;
        foreach ($rows as $row) {
            // An empty row is a list too, so it is caught here as well.
            if (!is_array($row) || array_is_list($row)) {
                return false;
            }

            $rowKeys = array_keys($row);
            sort($rowKeys);
            if ($keys !== null && $rowKeys !== $keys) {
                return false;
            }

            $keys = $rowKeys;
            foreach ($row as $value) {
                if ($value !== null && !is_scalar($value)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Put the note in front and the TOON text in a code fence. The fence is
     * longer than the longest run of backticks in the text, so a value cannot
     * close it.
     */
    private function wrap(string $toon): string
    {
        $longest = 0;
        preg_match_all('/`+/', $toon, $runs);
        foreach ($runs[0] as $run) {
            $longest = max($longest, strlen($run));
        }

        $fence = str_repeat('`', max(3, $longest + 1));

        return self::TOON_NOTE . "\n" . $fence . "toon\n" . $toon . "\n" . $fence;
    }
}
