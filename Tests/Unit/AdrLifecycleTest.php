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
 * that every cross-reference resolves, and that nobody invents a status word
 * outside the documented three.
 *
 * The pairing check covers ADR-to-ADR links only. A record superseded by
 * something that is not an ADR — ADR-012, replaced by the nr-vault integration
 * — names it in prose and has no counterpart to pair with.
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
     * Every value of the named RST field(s), each joined across its
     * continuation lines.
     *
     * A field body wraps onto indented lines (ADR-021's status does), so a
     * single-line match would drop the tail — and with it any cross-reference
     * living there. The alternation is the field NAME, so `:php:` roles at
     * column 0 inside a body cannot terminate or start a match.
     *
     * @return list<string>
     */
    private function fieldValues(string $contents, string $names): array
    {
        preg_match_all(
            '/^:(?:' . $names . '):[ \t]*(.*(?:\n[ \t]+\S.*)*)$/m',
            $contents,
            $matches,
        );

        return array_map(
            static fn(string $value): string => trim((string)preg_replace('/\s+/', ' ', $value)),
            $matches[1],
        );
    }

    private function statusOf(string $file): string
    {
        $values = $this->fieldValues((string)file_get_contents($file), 'Status');
        self::assertCount(1, $values, basename($file) . ' must declare exactly one :Status: field.');

        return $values[0];
    }

    /**
     * @return list<string> the `adr-NNN` labels a field body cross-references
     */
    private function referencedLabels(string $value): array
    {
        preg_match_all('/:ref:`[^`]*?<?(adr-\d{3})>?`/', $value, $matches);

        return $matches[1];
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
            if ($this->referencedLabels($status) === []) {
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
            $contents = (string)file_get_contents($file);

            // The status line names records too — `Accepted (… amended by
            // :ref:`ADR-135 <adr-135>`)` — and a typo there renders as an
            // unresolved reference just the same.
            $values = array_merge(
                $this->fieldValues($contents, 'Amended|Superseded|Amends|Supersedes'),
                $this->fieldValues($contents, 'Status'),
            );

            foreach ($values as $value) {
                foreach ($this->referencedLabels($value) as $label) {
                    self::assertContains(
                        $label,
                        $known,
                        basename($file) . ' references ' . $label . ', which no record defines.',
                    );
                }
            }
        }
    }

    /**
     * @return array<string, list<string>> file basename => ADR labels it names
     */
    private function lifecycleLinks(string $names): array
    {
        $links = [];
        foreach ($this->adrFiles() as $file) {
            $targets = [];
            foreach ($this->fieldValues((string)file_get_contents($file), $names) as $value) {
                foreach ($this->referencedLabels($value) as $label) {
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
        $forward  = $this->lifecycleLinks('Amends|Supersedes');
        $backward = $this->lifecycleLinks('Amended|Superseded');

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
