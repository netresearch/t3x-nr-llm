<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use SimpleXMLElement;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * The German approval card is German and names no internal field
 * (ADR-213, editorial guidelines rules 17 and 18).
 *
 * Every line but the last carries neither an English text of the
 * `approvalPreview.*` catalogue nor a `snake_case` name once the quoted values
 * are taken out: those are the record's own titles and contents, which no
 * translation touches. The last line is the technical details line, where
 * identifiers belong (rule 26).
 *
 * Also core's own labels in the language a test asserts, read from the TCA in
 * force, so an expectation does not depend on whether a language pack is
 * installed.
 */
trait AssertsGermanPreviewTrait
{
    /**
     * @param list<string> $lines
     * @param list<string> $foreign texts the line carries that come from
     *                              outside the catalogue — a fixture's or core's TCA labels, which
     *                              are English where no language pack is installed — and are taken
     *                              out before the check
     */
    private static function assertGermanEditorLines(array $lines, array $foreign = []): void
    {
        $technical = array_pop($lines);
        self::assertIsString($technical);
        self::assertStringStartsWith('Technische Details: ', $technical);

        self::assertGermanLines($lines, $foreign);
    }

    /**
     * {@see self::assertGermanEditorLines()} for a card without a technical
     * details line: every line is checked.
     *
     * @param list<string> $lines
     * @param list<string> $foreign
     */
    private static function assertGermanLines(array $lines, array $foreign = []): void
    {
        self::assertNotSame([], $lines);
        foreach ($lines as $line) {
            $prose = str_replace($foreign, '', (string)preg_replace('/„[^“]*“/u', '', $line));
            self::assertDoesNotMatchRegularExpression('/\b[a-z]+(?:_[a-z]+)+\b/', $prose, 'An internal name in: ' . $line);
            foreach (self::englishPreviewFragments() as $fragment) {
                self::assertStringNotContainsString($fragment, $prose, 'English in: ' . $line);
            }
        }
    }

    /**
     * The English source texts of the approval preview catalogue, cut at their
     * placeholders, without the pieces a German text of the catalogue uses
     * too ("UTC", and "Description" inside the guidelines' "Meta
     * Description").
     *
     * @return list<string>
     */
    private static function englishPreviewFragments(): array
    {
        $catalogue = __DIR__ . '/../../../../Resources/Private/Language/de.locallang.xlf';
        $contents  = file_get_contents($catalogue);
        self::assertIsString($contents);

        $sources = [];
        $targets = '';
        foreach ((new SimpleXMLElement($contents))->xpath('//*[local-name()="trans-unit"]') ?? [] as $unit) {
            if (str_starts_with((string)($unit['id'] ?? ''), 'approvalPreview.')) {
                $sources[] = (string)($unit->source ?? '');
                $targets .= "\n" . ($unit->target ?? '');
            }
        }

        $fragments = [];
        foreach ($sources as $source) {
            foreach (preg_split('/%(?:\d+\$)?[sd]/', $source) ?: [] as $piece) {
                $piece = trim($piece, " \t\n,.;:()");
                if (mb_strlen($piece) >= 4 && preg_match('/\p{L}/u', $piece) === 1 && !str_contains($targets, $piece)) {
                    $fragments[] = $piece;
                }
            }
        }

        return array_values(array_unique($fragments));
    }

    /**
     * A TCA label, found at a path below `$GLOBALS['TCA']`, in a language.
     */
    private function tcaLabelIn(string $language, string ...$path): string
    {
        return $this->labelIn($language, self::tcaAt(...$path));
    }

    /**
     * A column's label for a record type, in a language: the type's
     * `columnsOverrides` label where it declares one, the column's own
     * otherwise — the label the backend form shows.
     */
    private function tcaColumnLabelIn(string $language, string $table, string $column, string $recordType): string
    {
        return self::tcaAt($table, 'types', $recordType, 'columnsOverrides', $column, 'label') !== null
            ? $this->tcaLabelIn($language, $table, 'types', $recordType, 'columnsOverrides', $column, 'label')
            : $this->tcaLabelIn($language, $table, 'columns', $column, 'label');
    }

    /**
     * The label of the select item `$value` of a column, in a language.
     */
    private function tcaItemLabelIn(string $language, string $table, string $column, string $value): string
    {
        $items = self::tcaAt($table, 'columns', $column, 'config', 'items');
        self::assertIsArray($items);
        foreach ($items as $item) {
            if (is_array($item) && ($item['value'] ?? null) === $value) {
                return $this->labelIn($language, $item['label'] ?? null);
            }
        }

        self::fail(sprintf('No item "%s" in %s.%s', $value, $table, $column));
    }

    private function labelIn(string $language, mixed $label): string
    {
        self::assertIsString($label);

        return rtrim(trim($this->getService(LanguageServiceFactory::class)->create($language)->sL($label)), ':');
    }

    /**
     * Set a value at a path below `$GLOBALS['TCA']`, creating the levels on
     * the way.
     */
    private static function setTcaAt(mixed $value, string ...$path): void
    {
        $GLOBALS['TCA'] = self::withValueAt(is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [], array_values($path), $value);
    }

    /**
     * @param array<array-key, mixed> $array
     * @param list<string>            $path
     *
     * @return array<array-key, mixed>
     */
    private static function withValueAt(array $array, array $path, mixed $value): array
    {
        $key = array_shift($path);
        if ($key === null) {
            return $array;
        }

        $array[$key] = $path === [] ? $value : self::withValueAt(is_array($array[$key] ?? null) ? $array[$key] : [], $path, $value);

        return $array;
    }

    private static function tcaAt(string ...$path): mixed
    {
        $value = $GLOBALS['TCA'] ?? null;
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }
}
