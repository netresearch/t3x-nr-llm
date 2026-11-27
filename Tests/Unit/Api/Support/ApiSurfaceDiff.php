<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api\Support;

/**
 * Classifies the difference between two rendered API surfaces.
 *
 * A snapshot assertion that only reports "different" makes a new value
 * object look exactly like a deleted method, so every diff gets read with
 * the same shrug. This splits the difference into three buckets and answers
 * the only question the author actually has: may I regenerate the file, or
 * do I have to decide something?
 *
 * - **added** — a class, method, property, constant or enum case that was
 *   not there before. Additive; existing callers keep compiling. Two
 *   additions are breaking all the same and are listed in
 *   `addedToContracts`: a new method on an existing interface (every
 *   implementation outside nr_llm lacks it) and a new case of an existing
 *   enum (every exhaustive `match` without a default arm fails on it).
 * - **removed** — gone. Every caller that used it breaks.
 * - **changed** — same member, different signature. Includes a widened
 *   constructor, which is the case the snapshot missed entirely before it
 *   recorded constructors at all.
 *
 * Members are matched by NAME, not by rendered line, so a signature change
 * lands in `changed` instead of showing up as an unrelated removal plus an
 * unrelated addition.
 */
final readonly class ApiSurfaceDiff
{
    /**
     * How many entries a bucket prints before it is truncated. A surface-wide
     * rename produces hundreds; the first few say the same thing.
     */
    private const MAX_LISTED = 40;

    /**
     * The synthetic member key of the block header, so a `class` that became
     * an `interface` is a change like any other.
     */
    private const DECLARATION_KEY = '(declaration)';

    /**
     * @param list<string>                                         $added
     * @param list<string>                                         $removed
     * @param list<array{entry: string, was: string, now: string}> $changed
     * @param list<string>                                         $addedToContracts the part of $added that breaks implementations or exhaustive matches (interface methods, abstract methods, enum cases)
     */
    private function __construct(
        public array $added,
        public array $removed,
        public array $changed,
        public array $addedToContracts = [],
    ) {}

    public static function between(string $expected, string $actual): self
    {
        $before = self::parse($expected);
        $after  = self::parse($actual);

        $added            = [];
        $removed          = [];
        $changed          = [];
        $addedToContracts = [];

        foreach ($after as $fqcn => $members) {
            if (!isset($before[$fqcn])) {
                $added[] = $members[self::DECLARATION_KEY] ?? $fqcn;

                continue;
            }

            foreach ($members as $key => $line) {
                if (!isset($before[$fqcn][$key])) {
                    $added[] = $fqcn . ' :: ' . $line;
                    if (self::breaksWhenAdded($members[self::DECLARATION_KEY] ?? '', $key, $line)) {
                        $addedToContracts[] = $fqcn . ' :: ' . $line;
                    }

                    continue;
                }

                if ($before[$fqcn][$key] !== $line) {
                    $changed[] = [
                        'entry' => $fqcn . ' :: ' . $key,
                        'was'   => $before[$fqcn][$key],
                        'now'   => $line,
                    ];
                }
            }
        }

        foreach ($before as $fqcn => $members) {
            if (!isset($after[$fqcn])) {
                $removed[] = $members[self::DECLARATION_KEY] ?? $fqcn;

                continue;
            }

            foreach ($members as $key => $line) {
                if (!isset($after[$fqcn][$key])) {
                    $removed[] = $fqcn . ' :: ' . $line;
                }
            }
        }

        sort($added);
        sort($removed);
        sort($addedToContracts);
        usort($changed, static fn (array $a, array $b): int => strcmp($a['entry'], $b['entry']));

        return new self($added, $removed, $changed, $addedToContracts);
    }

    public function isEmpty(): bool
    {
        return $this->added === [] && $this->removed === [] && $this->changed === [];
    }

    /**
     * True when nothing was removed, nothing changed shape and no interface or
     * enum gained a member — the case an author may resolve by regenerating
     * the snapshot.
     */
    public function isAdditiveOnly(): bool
    {
        return !$this->isEmpty() && $this->removed === [] && $this->changed === [] && $this->addedToContracts === [];
    }

    /**
     * `identical`, `additive` or `breaking` — the word the failure message
     * leads with.
     */
    public function verdict(): string
    {
        return match (true) {
            $this->isEmpty()        => 'identical',
            $this->isAdditiveOnly() => 'additive',
            default                 => 'breaking',
        };
    }

    /**
     * The failure message body: what happened, and what the author must do
     * about it.
     */
    public function describe(): string
    {
        if ($this->isEmpty()) {
            return 'The rendered @api surface matches api-surface.txt.';
        }

        $contract = $this->addedToContracts;
        $other    = array_values(array_diff($this->added, $contract));
        $counts   = sprintf(
            '%d added to an interface, abstract class or enum, %d other addition(s), %d removed, %d changed',
            count($contract),
            count($other),
            count($this->removed),
            count($this->changed),
        );
        $contractSentence = 'A new method on an @api interface or a new abstract method on an @api class '
            . 'breaks every implementation outside nr_llm, and a new case of an @api enum breaks every '
            . 'exhaustive match without a default arm.';

        $sections = [];

        if ($this->isAdditiveOnly()) {
            $sections[] = strtoupper($this->verdict()) . ' — ' . $counts . '. Nothing was removed, '
                . 'no existing signature changed and no contract gained a member, so no caller breaks.';
            $sections[] = 'What to do: regenerate the snapshot (delete Tests/Unit/Api/api-surface.txt, '
                . 'run the unit suite twice) and note the addition in a changelog.d/<branch-slug>.added.md '
                . 'fragment. The release that ships it raises only the last version number.';
        } else {
            if ($this->removed === [] && $this->changed === []) {
                $sections[] = strtoupper($this->verdict()) . ' — ' . $counts . '. ' . $contractSentence;
            } else {
                $sections[] = strtoupper($this->verdict()) . ' — ' . $counts . '. A removed line breaks '
                    . 'every caller that used it. A changed one may or may not: an added OPTIONAL '
                    . 'parameter breaks nobody, an added required one breaks every call. Read the '
                    . 'was/now pair below — the rendering marks an optional parameter with " = …".';
                if ($contract !== []) {
                    $sections[] = $contractSentence;
                }
            }

            $sections[] = 'What to do: this is a decision, not a regeneration. Either revert the '
                . 'change, or follow Documentation/Api/Deprecation.rst — deprecate first, '
                . 'remove later — and write a changelog.d/ fragment whose bullet opens with '
                . '`**BREAKING` and says what a consumer has to change, before you regenerate the '
                . 'snapshot. During 0.x the release that ships it raises the middle version number '
                . '(Documentation/Api/Stability.rst). A widening of a class method that only adds '
                . 'optional parameters is additive for callers: note it in an `added` fragment and '
                . 'regenerate; on an interface method it breaks every implementation. '
                . 'Documentation/Api/Stability.rst lists what is out of '
                . 'contract; a constructor of a service you only ever obtain from the DI container '
                . 'is, but it is still recorded here so a widened one cannot land unread.';
        }

        if ($this->removed !== []) {
            $sections[] = $this->bucket('removed', '-', $this->removed);
        }

        if ($this->changed !== []) {
            $lines = [];
            foreach ($this->changed as $entry) {
                $lines[] = $entry['entry'] . "\n        was: " . $entry['was'] . "\n        now: " . $entry['now'];
            }

            $sections[] = $this->bucket('changed', '~', $lines);
        }

        if ($contract !== []) {
            $sections[] = $this->bucket('added to an interface, abstract class or enum (breaking)', '!', $contract);
        }

        if ($other !== []) {
            $sections[] = $this->bucket('added', '+', $other);
        }

        return implode("\n\n", $sections);
    }

    /**
     * @param list<string> $entries
     */
    private function bucket(string $title, string $marker, array $entries): string
    {
        $shown     = array_slice($entries, 0, self::MAX_LISTED);
        $formatted = array_map(static fn (string $entry): string => '  ' . $marker . ' ' . $entry, $shown);

        if (count($entries) > self::MAX_LISTED) {
            $formatted[] = sprintf('  … and %d more', count($entries) - self::MAX_LISTED);
        }

        return $title . ':' . "\n" . implode("\n", $formatted);
    }

    /**
     * @return array<string, array<string, string>> FQCN => member key => rendered line
     */
    private static function parse(string $text): array
    {
        $classes = [];

        foreach (explode("\n\n", trim($text)) as $block) {
            $lines = array_values(array_filter(
                array_map(rtrim(...), explode("\n", $block)),
                static fn (string $line): bool => trim($line) !== '',
            ));

            if ($lines === []) {
                continue;
            }

            $header = trim($lines[0]);
            $fqcn   = self::classNameOf($header);

            $members = [self::DECLARATION_KEY => $header];
            foreach (array_slice($lines, 1) as $line) {
                $member                            = trim($line);
                $members[self::memberKey($member)] = $member;
            }

            $classes[$fqcn] = $members;
        }

        return $classes;
    }

    /**
     * A new method of an interface, a new abstract method of a class, or a new
     * case of an enum. A new constant is additive: nothing has to implement or
     * match it.
     */
    private static function breaksWhenAdded(string $header, string $memberKey, string $line): bool
    {
        $kind = preg_match('/^\S+ \(([a-z ]+)\)/', $header, $matches) === 1 ? $matches[1] : '';

        return ($kind === 'interface' && str_starts_with($memberKey, 'method '))
            || ($kind === 'enum' && str_starts_with($memberKey, 'case '))
            || str_starts_with($line, 'method abstract ');
    }

    /**
     * `Foo\Bar (class)` → `Foo\Bar`.
     */
    private static function classNameOf(string $header): string
    {
        return preg_match('/^(\S+) \([a-z ]+\)/', $header, $matches) === 1 ? $matches[1] : $header;
    }

    /**
     * The name a member is matched by across the two renderings.
     *
     * Modifiers are deliberately dropped from the key: a method that became
     * `static`, or a property that lost `readonly`, must land in `changed`
     * rather than read as one removal and one unrelated addition.
     */
    private static function memberKey(string $line): string
    {
        if (str_starts_with($line, 'constructor(')) {
            return 'constructor';
        }

        if (preg_match('/^(method|property) (?:abstract )?(?:static |readonly )?(\w+)/', $line, $matches) === 1) {
            return $matches[1] . ' ' . $matches[2];
        }

        if (preg_match('/^(const|case) (\w+)/', $line, $matches) === 1) {
            return $matches[1] . ' ' . $matches[2];
        }

        return $line;
    }
}
