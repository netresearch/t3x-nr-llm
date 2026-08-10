<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * Enforces the ADR record lifecycle documented in Documentation/Adr/Index.rst.
 *
 * This checks form, not meaning: no test can tell that ADR-122's "all 44
 * builtin tools read" stopped being true. What it can tell is that a status
 * naming another record carries the matching :Amended: / :Superseded: field,
 * that the field points at a record that exists, and that nobody invents a
 * fifth status word.
 */
#[CoversNothing]
final class AdrLifecycleTest extends AbstractUnitTestCase
{
    private const ALLOWED_STATUSES = ['Accepted', 'Superseded', 'Deprecated'];

    private function adrDir(): string
    {
        return dirname(__DIR__, 2) . '/Documentation/Adr';
    }

    /**
     * @return list<string> absolute paths, Index.rst excluded
     */
    private function adrFiles(): array
    {
        $files = glob($this->adrDir() . '/Adr*.rst');
        self::assertIsArray($files);
        self::assertNotSame([], $files);

        return $files;
    }

    /**
     * The `.. _adr-NNN:` labels every record defines, so a cross-reference can
     * be resolved without rendering the docs.
     *
     * @return list<string>
     */
    private function knownAdrLabels(): array
    {
        $labels = [];
        foreach ($this->adrFiles() as $file) {
            preg_match_all('/^\.\. _(adr-\d{3}):$/m', (string)file_get_contents($file), $matches);
            foreach ($matches[1] as $label) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /**
     * The `:Status:` value, joined across its RST continuation lines.
     */
    private function statusOf(string $file): string
    {
        $contents = (string)file_get_contents($file);
        self::assertSame(
            1,
            preg_match('/^:Status:[ \t]*(.+?)(?=^:[A-Za-z-]+:)/ms', $contents, $matches),
            basename($file) . ' must declare a :Status: field.',
        );

        return trim((string)preg_replace('/\s+/', ' ', $matches[1]));
    }

    #[Test]
    public function everyRecordUsesAKnownStatusWord(): void
    {
        foreach ($this->adrFiles() as $file) {
            $status = $this->statusOf($file);
            $word   = explode(' ', $status)[0];

            self::assertContains(
                $word,
                self::ALLOWED_STATUSES,
                basename($file) . ' has status "' . $status . '". The vocabulary is '
                . implode(' / ', self::ALLOWED_STATUSES) . ' — see Documentation/Adr/Index.rst.',
            );
        }
    }

    #[Test]
    public function aStatusNamingAnotherRecordCarriesTheLifecycleField(): void
    {
        foreach ($this->adrFiles() as $file) {
            $status = $this->statusOf($file);

            // Section labels such as `adr-021-scope` are self-references, not
            // cross-record ones, and carry no lifecycle claim.
            if (preg_match('/:ref:`[^`]*<?(adr-\d{3})>?`/', $status) !== 1) {
                continue;
            }

            $contents = (string)file_get_contents($file);
            self::assertSame(
                1,
                preg_match('/^:(?:Amended|Superseded):[ \t]*\S/m', $contents),
                basename($file) . ' says another record overtook part of it but carries no '
                . ':Amended: / :Superseded: field. The amending ADR owns that edit.',
            );
        }
    }

    #[Test]
    public function everyLifecycleFieldPointsAtARecordThatExists(): void
    {
        $known = $this->knownAdrLabels();

        foreach ($this->adrFiles() as $file) {
            preg_match_all(
                '/^:(?:Amended|Superseded|Amends|Supersedes):[ \t]*(.+)$/m',
                (string)file_get_contents($file),
                $fields,
            );

            foreach ($fields[1] as $value) {
                preg_match_all('/:ref:`[^`]*?<?(adr-\d{3})>?`/', $value, $refs);
                foreach ($refs[1] as $label) {
                    self::assertContains(
                        $label,
                        $known,
                        basename($file) . ' points its lifecycle field at ' . $label . ', which no record defines.',
                    );
                }
            }
        }
    }

    /**
     * @return array<string, list<string>> file basename => ADR labels it names
     */
    private function lifecycleLinks(string $pattern): array
    {
        $links = [];
        foreach ($this->adrFiles() as $file) {
            preg_match_all($pattern, (string)file_get_contents($file), $fields);

            $targets = [];
            foreach ($fields[1] as $value) {
                preg_match_all('/:ref:`[^`]*?<?(adr-\d{3})>?`/', $value, $refs);
                foreach ($refs[1] as $label) {
                    $targets[] = $label;
                }
            }

            if ($targets !== []) {
                $links[basename($file)] = $targets;
            }
        }

        return $links;
    }

    /**
     * The label an ADR file defines, e.g. `adr-122`.
     */
    private function ownLabel(string $basename): string
    {
        self::assertSame(1, preg_match('/^Adr(\d{3})/', $basename, $matches));

        return 'adr-' . $matches[1];
    }

    #[Test]
    public function amendingAndAmendedRecordsPointAtEachOther(): void
    {
        // "Amending is the amender's job" — the forward field on the newer
        // record and the backward field on the older one are written together
        // or the pair is incomplete.
        $forward  = $this->lifecycleLinks('/^:(?:Amends|Supersedes):[ \t]*(.+)$/m');
        $backward = $this->lifecycleLinks('/^:(?:Amended|Superseded):[ \t]*(.+)$/m');

        foreach ($forward as $file => $targets) {
            foreach ($targets as $target) {
                $targetFile = $this->fileDefining($target);
                self::assertContains(
                    $this->ownLabel($file),
                    $backward[$targetFile] ?? [],
                    $file . ' declares it amends/supersedes ' . $target . ', but ' . $targetFile
                    . ' does not say so. Edit both in the same change.',
                );
            }
        }

        foreach ($backward as $file => $targets) {
            foreach ($targets as $target) {
                $targetFile = $this->fileDefining($target);
                self::assertContains(
                    $this->ownLabel($file),
                    $forward[$targetFile] ?? [],
                    $file . ' says ' . $target . ' overtook part of it, but ' . $targetFile
                    . ' does not claim it. Edit both in the same change.',
                );
            }
        }
    }

    private function fileDefining(string $label): string
    {
        foreach ($this->adrFiles() as $file) {
            if (preg_match('/^\.\. _' . preg_quote($label, '/') . ':$/m', (string)file_get_contents($file)) === 1) {
                return basename($file);
            }
        }

        self::fail('No ADR defines the label ' . $label . '.');
    }

    #[Test]
    public function aSupersededRecordSaysWhatReplacedIt(): void
    {
        foreach ($this->adrFiles() as $file) {
            if (!str_starts_with($this->statusOf($file), 'Superseded')) {
                continue;
            }

            self::assertSame(
                1,
                preg_match('/^:Superseded:[ \t]*\S/m', (string)file_get_contents($file)),
                basename($file) . ' is Superseded but does not say by what.',
            );
        }
    }
}
