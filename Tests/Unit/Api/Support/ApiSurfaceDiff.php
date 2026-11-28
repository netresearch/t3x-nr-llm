<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api\Support;

use ReflectionClass;

/**
 * Classifies the difference between two rendered API surfaces.
 *
 * A snapshot assertion that only reports "different" makes a new value
 * object look exactly like a deleted method, so every diff gets read with
 * the same shrug. This splits the difference into buckets and answers the
 * only question the author actually has: may I regenerate the file, do I have
 * to decide something, or does the snapshot not know?
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
 *   recorded constructors at all, and a class or method made `final`.
 * - **widened** — a change that breaks nobody: an abstract class made
 *   concrete, a final class made non-final, trailing optional parameters on
 *   a constructor, a method of a final class or a final method, an abstract method given a body, an interface added to
 *   `implements` whose methods are rendered.
 * - **check by hand** — what the snapshot cannot classify: a vanished
 *   protected or private abstract method (concrete ones are not rendered, so a
 *   body and a deletion look the same) and an interface from outside nr_llm
 *   and PHP added to an abstract class (its open methods are not rendered).
 *   Not additive; the verdict is `check by hand`.
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
     * @param list<array{entry: string, was: string, now: string}> $widened          changes that break nobody: an abstract class made concrete, an interface added to `implements`, an abstract method given a body
     * @param list<string>                                         $checkByHand      changes the snapshot cannot classify: a vanished protected abstract method (given a body or deleted), a third-party interface added to an abstract class (its open methods are not rendered)
     */
    private function __construct(
        public array $added,
        public array $removed,
        public array $changed,
        public array $addedToContracts = [],
        public array $widened = [],
        public array $checkByHand = [],
    ) {}

    public static function between(string $expected, string $actual): self
    {
        $before = self::parse($expected);
        $after  = self::parse($actual);

        $added            = [];
        $removed          = [];
        $changed          = [];
        $addedToContracts = [];
        $widened          = [];
        $checkByHand      = [];

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

                // An abstract method given a body breaks nobody, whether or
                // not the class itself stays abstract.
                $onlyLostAbstract = self::withoutAbstract($before[$fqcn][$key]) === $line;
                $thirdParty       = $key === self::DECLARATION_KEY ? self::addedThirdPartyInterfaces($before[$fqcn][$key], $line) : [];
                if ($thirdParty !== [] && self::isWidening($before[$fqcn][$key], $line)) {
                    $checkByHand[] = $fqcn . ' :: ' . $key . ' now implements ' . implode(', ', $thirdParty)
                        . ' — an abstract class leaves the methods of an interface from outside nr_llm or PHP'
                        . ' unrendered, so the snapshot cannot tell whether subclasses now have to implement any';
                } elseif ($before[$fqcn][$key] !== $line && (($key === self::DECLARATION_KEY && self::isWidening($before[$fqcn][$key], $line)) || $onlyLostAbstract
                    || self::isOptionalParameterWidening($members[self::DECLARATION_KEY] ?? '', $key, $before[$fqcn][$key], $line))) {
                    $widened[] = ['entry' => $fqcn . ' :: ' . $key, 'was' => $before[$fqcn][$key], 'now' => $line];
                } elseif ($before[$fqcn][$key] !== $line) {
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
                if (isset($after[$fqcn][$key])) {
                    continue;
                }

                // Concrete protected and private methods are not rendered, so
                // a hook given a body and a deleted one look the same.
                if (str_starts_with($line, 'method abstract protected ') || str_starts_with($line, 'method abstract private ')) {
                    $checkByHand[] = $fqcn . ' :: ' . $line;

                    continue;
                }

                $removed[] = $fqcn . ' :: ' . $line;
            }
        }

        sort($added);
        sort($removed);
        sort($addedToContracts);
        sort($checkByHand);
        usort($changed, static fn (array $a, array $b): int => strcmp($a['entry'], $b['entry']));

        return new self($added, $removed, $changed, $addedToContracts, $widened, $checkByHand);
    }

    public function isEmpty(): bool
    {
        return $this->added === [] && $this->removed === [] && $this->changed === [] && $this->widened === []
            && $this->checkByHand === [];
    }

    /**
     * True when nothing was removed, nothing changed shape and no interface or
     * enum gained a member — the case an author may resolve by regenerating
     * the snapshot.
     */
    public function isAdditiveOnly(): bool
    {
        return !$this->isEmpty() && $this->removed === [] && $this->changed === [] && $this->addedToContracts === []
            && $this->checkByHand === [];
    }

    /**
     * True when the only entries that are not additive are ones the snapshot
     * cannot classify.
     */
    private function needsCheckByHand(): bool
    {
        return $this->checkByHand !== [] && $this->removed === [] && $this->changed === [] && $this->addedToContracts === [];
    }

    /**
     * `identical`, `additive`, `check by hand` or `breaking` — the word the
     * failure message leads with.
     */
    public function verdict(): string
    {
        return match (true) {
            $this->isEmpty()          => 'identical',
            $this->isAdditiveOnly()   => 'additive',
            $this->needsCheckByHand() => 'check by hand',
            default                   => 'breaking',
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
            '%d added to an interface, abstract class or enum, %d other addition(s), %d removed, %d changed, %d widened, %d to check by hand',
            count($contract),
            count($other),
            count($this->removed),
            count($this->changed),
            count($this->widened),
            count($this->checkByHand),
        );
        $contractSentence = 'A new method on an @api interface or a new abstract method on an @api class '
            . 'breaks every implementation outside nr_llm, and a new case of an @api enum breaks every '
            . 'exhaustive match without a default arm.';

        $sections = [];

        if ($this->needsCheckByHand()) {
            $sections[] = strtoupper($this->verdict()) . ' — ' . $counts . '. The snapshot cannot tell '
                . 'whether the entries below break a subclass.';
            $sections[] = 'What to do: read the class. A protected abstract method that now has a body breaks '
                . 'nobody; a deleted one, or an interface whose methods a subclass now has to implement, is '
                . 'breaking and needs a changelog.d/ fragment whose top-level bullet opens with `- **BREAKING`. '
                . 'Then regenerate the snapshot.';
        } elseif ($this->isAdditiveOnly()) {
            $sections[] = strtoupper($this->verdict()) . ' — ' . $counts . '. Nothing was removed, '
                . 'no existing signature changed and no contract gained a member, so no caller breaks.';
            $sections[] = 'What to do: regenerate the snapshot (delete Tests/Unit/Api/api-surface.txt, '
                . 'run the unit suite twice) and note the addition in a changelog.d/<branch-slug>.added.md '
                . 'fragment. The release that ships it raises only the last version number.';
        } else {
            array_push($sections, ...$this->breakingHeadline($counts, $contractSentence));

            $sections[] = 'What to do: this is a decision, not a regeneration. Either revert the '
                . 'change, or follow Documentation/Api/Deprecation.rst — deprecate first, '
                . 'remove later — and write a changelog.d/ fragment whose top-level bullet opens '
                . 'with `- **BREAKING` and says what a consumer has to change, before you regenerate the '
                . 'snapshot. During 0.x the release that ships it raises the middle version number '
                . '(Documentation/Api/Stability.rst). Trailing optional parameters on a constructor, '
                . 'a method of a final class or a final method are already reported as widened; on '
                . 'any other method they break every override, and on an interface method every '
                . 'implementation. '
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

        if ($this->checkByHand !== []) {
            $sections[] = $this->bucket('cannot tell given a body from deleted, or open methods not rendered (check by hand)', '?', $this->checkByHand);
        }

        if ($this->widened !== []) {
            $lines = [];
            foreach ($this->widened as $entry) {
                $lines[] = $entry['entry'] . "\n        was: " . $entry['was'] . "\n        now: " . $entry['now'];
            }

            $sections[] = $this->bucket('widened header (additive)', '+', $lines);
        }

        return implode("\n\n", $sections);
    }

    /**
     * The opening of a breaking report: a diff that only adds to a contract
     * leads with that; otherwise the removed/changed explanation comes first.
     *
     * @return list<string>
     */
    private function breakingHeadline(string $counts, string $contractSentence): array
    {
        $lead = strtoupper($this->verdict()) . ' — ' . $counts . '. ';
        if ($this->removed === [] && $this->changed === []) {
            return [$lead . $contractSentence];
        }

        $sections = [$lead . 'A removed line breaks every caller that used it. A changed one may or may '
            . 'not: an added OPTIONAL parameter breaks nobody, an added required one breaks every call. '
            . 'Read the was/now pair below — the rendering marks an optional parameter with " = …".'];
        if ($this->addedToContracts !== []) {
            $sections[] = $contractSentence;
        }

        if (array_filter($this->changed, static fn (array $c): bool => str_ends_with($c['entry'], ' :: ' . self::DECLARATION_KEY)) !== []) {
            $sections[] = 'A changed header line is breaking unless it only moves the parent class to a '
                . 'subclass of the old one, which keeps every member a subclass or caller relied on; the '
                . 'snapshot cannot tell, so check the new parent before deciding.';
        }

        return $sections;
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
     * True when a member only gained trailing parameters with a default value
     * and nothing can override it: a constructor, a method of a final class
     * or a final method. No call breaks, and no override or implementation
     * can have the old signature. On an overridable method, an interface
     * method or an abstract one the same change breaks every override.
     */
    private static function isOptionalParameterWidening(string $header, string $key, string $was, string $now): bool
    {
        $overridable = $key !== 'constructor'
            && self::headerParts($header)['kind'] !== 'final class'
            && !str_starts_with($now, 'method final ');
        if ($overridable) {
            return false;
        }

        $before = self::splitSignature($was);
        $after  = self::splitSignature($now);
        if ($before === null || $after === null || $before['head'] !== $after['head'] || $before['tail'] !== $after['tail']) {
            return false;
        }

        $added = array_slice($after['parameters'], count($before['parameters']));

        return $added !== []
            && array_slice($after['parameters'], 0, count($before['parameters'])) === $before['parameters']
            && array_filter($added, static fn (string $p): bool => !str_ends_with($p, ' = …')) === [];
    }

    /**
     * `method run(string $a, int $b = …): string` → head `method run`, the
     * parameters, tail `: string`. Parentheses inside types are skipped.
     *
     * @return array{head: string, parameters: list<string>, tail: string}|null
     */
    private static function splitSignature(string $line): ?array
    {
        $open = strpos($line, '(');
        if ($open === false) {
            return null;
        }

        $depth = 0;
        $close = null;
        for ($i = $open, $n = strlen($line); $i < $n; ++$i) {
            if ($line[$i] === '(') {
                ++$depth;
            } elseif ($line[$i] === ')' && --$depth === 0) {
                $close = $i;

                break;
            }
        }

        if ($close === null) {
            return null;
        }

        $inner = substr($line, $open + 1, $close - $open - 1);

        return [
            'head'       => substr($line, 0, $open),
            'parameters' => $inner === '' ? [] : explode(', ', $inner),
            'tail'       => substr($line, $close + 1),
        ];
    }

    /**
     * Interfaces from outside nr_llm and PHP that an abstract class lists in
     * `implements` now and did not before. The renderer leaves their methods
     * out, so the snapshot cannot show what a subclass has to implement.
     *
     * @return list<string>
     */
    private static function addedThirdPartyInterfaces(string $was, string $now): array
    {
        $after = self::headerParts($now);
        if ($after['kind'] !== 'abstract class') {
            return [];
        }

        $added = array_diff($after['implements'], self::headerParts($was)['implements']);

        return array_values(array_filter(
            $added,
            static fn (string $name): bool => !str_starts_with($name, 'Netresearch\\NrLlm\\')
                && (!interface_exists($name) || !(new ReflectionClass($name))->isInternal()),
        ));
    }

    /**
     * `method abstract protected x()` → `method protected x()`.
     */
    private static function withoutAbstract(string $line): string
    {
        return str_starts_with($line, 'method abstract ') ? 'method ' . substr($line, strlen('method abstract ')) : $line;
    }

    /**
     * True when a changed header line breaks nobody: the kind stays, an
     * abstract class becomes concrete or a final class stops being final, the
     * parent stays, and every interface
     * the header listed is still listed. Methods a newly implemented interface
     * leaves open show up as `method abstract` lines and are judged there.
     * A changed parent is never a widening here: a subclass of the old parent
     * would break nobody, but the snapshot alone cannot tell.
     */
    private static function isWidening(string $was, string $now): bool
    {
        $before = self::headerParts($was);
        $after  = self::headerParts($now);

        $kindOk = $before['kind'] === $after['kind']
            || ($before['kind'] === 'abstract class' && $after['kind'] === 'class')
            || ($before['kind'] === 'final class' && $after['kind'] === 'class');

        return $kindOk
            && $before['extends'] === $after['extends']
            && array_diff($before['implements'], $after['implements']) === [];
    }

    /**
     * `Foo (class) extends Bar implements A, B` → kind, extends, implements.
     * An interface's parents are its `extends` list and must stay identical.
     *
     * @return array{kind: string, extends: string, implements: list<string>}
     */
    private static function headerParts(string $header): array
    {
        preg_match('/^\S+ \(([a-z ]+)\)(?: extends ([^ ]+(?:, [^ ]+)*?))?(?: implements (.+))?$/', $header, $m);

        return [
            'kind'       => $m[1] ?? $header,
            'extends'    => $m[2] ?? '',
            'implements' => isset($m[3]) && $m[3] !== '' ? explode(', ', $m[3]) : [],
        ];
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

        if (preg_match('/^(method|property) (?:abstract |final )?(?:protected |private )?(?:static |readonly )?(\w+)/', $line, $matches) === 1) {
            return $matches[1] . ' ' . $matches[2];
        }

        if (preg_match('/^(const|case) (\w+)/', $line, $matches) === 1) {
            return $matches[1] . ' ' . $matches[2];
        }

        return $line;
    }
}
