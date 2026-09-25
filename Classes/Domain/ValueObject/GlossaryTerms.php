<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * The term pairs of a translation glossary (ADR-208).
 *
 * Parsed from the `entries` text field of tx_nrllm_glossary: one pair per
 * line, the source term and the target term separated either by a tab (what a
 * spreadsheet paste produces) or by the first `=`. A line with a tab is split
 * on the tab, so a term that itself contains `=` stays expressible.
 *
 * Lines that cannot be a pair are skipped rather than rejected: an empty line,
 * a line starting with `#` (a comment), a line without a separator, a pair with
 * an empty side, and a pair whose target still contains a tab after the split.
 * The last is the one DeepL's TSV format cannot carry. A repeated source term
 * keeps its LAST translation, which is what an editor appending a correction
 * at the bottom expects.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class GlossaryTerms
{
    /**
     * @param array<string, string> $terms source term => target term
     */
    private function __construct(
        private array $terms,
    ) {}

    public static function fromText(string $text): self
    {
        $terms = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $separator = str_contains($line, "\t") ? "\t" : '=';
            if (!str_contains($line, $separator)) {
                continue;
            }

            [$source, $target] = explode($separator, $line, 2);
            $source = trim($source);
            $target = trim($target);
            if ($source === '' || $target === '' || str_contains($target, "\t")) {
                continue;
            }

            $terms[$source] = $target;
        }

        return new self($terms);
    }

    /**
     * @return array<string, string> source term => target term, in input order
     */
    public function toArray(): array
    {
        return $this->terms;
    }

    public function count(): int
    {
        return count($this->terms);
    }

    public function isEmpty(): bool
    {
        return $this->terms === [];
    }

    /**
     * The pairs in DeepL's `tsv` entries format: one `source<TAB>target` per
     * line.
     */
    public function toTsv(): string
    {
        $lines = [];
        foreach ($this->terms as $source => $target) {
            $lines[] = $source . "\t" . $target;
        }

        return implode("\n", $lines);
    }
}
