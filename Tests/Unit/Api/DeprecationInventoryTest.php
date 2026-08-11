<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Binds the deprecation inventory in `Documentation/Api/Deprecation.rst` to
 * the `@deprecated` tags in the code, in both directions.
 *
 * This is the enforceable half of the deprecation policy. The policy's other
 * half — that a removal waits at least one minor line after the deprecation
 * ships — is NOT enforced here and cannot be: nothing in the repository knows
 * in which release a docblock tag first appeared. What this test does enforce
 * is that no `@deprecated` member of an `@api` class exists without a written
 * migration — a row whose "Use instead" cell is empty fails the same way a
 * missing row does — and that the page cannot keep listing something the code
 * no longer deprecates. All five declaration shapes count: method, constant,
 * public property, enum case and the type itself.
 *
 * Scope is deliberately the `@api` surface only. `@internal` classes may
 * change in any release, so a deprecation there is a courtesy, not a promise.
 */
#[CoversNothing]
final class DeprecationInventoryTest extends TestCase
{
    private const CLASSES_DIR = __DIR__ . '/../../../Classes';

    private const PAGE_PATH = __DIR__ . '/../../../Documentation/Api/Deprecation.rst';

    private const INVENTORY_START = '.. deprecation-inventory-start';

    private const INVENTORY_END = '.. deprecation-inventory-end';

    /**
     * One `@deprecated` docblock plus the declaration it belongs to.
     *
     * `(?:#\[...\]\s*)*` skips any attributes between the two — the shape
     * every extension-point interface here already has, and the one a
     * deprecation would otherwise slip through unseen. The optional type
     * before a name is PHP 8.3's typed class constant: without it the pattern
     * keys `public const string FOO` as `Class::string`, which no inventory
     * row can satisfy. `declarationShapes()` is the fixture that keeps both
     * true.
     */
    private const DECLARATION_PATTERN = '/\/\*\*(?<doc>(?:[^*]|\*(?!\/))*?@deprecated(?:[^*]|\*(?!\/))*)\*\/\s*'
        . '(?:#\[[^\n]*\]\s*)*'
        . '(?<mods>(?:(?:public|protected|private|final|static|readonly|abstract)\s+)*)'
        . '(?:'
        . '(?<kind>function|const|case|class|interface|trait|enum)\s+(?:[\w\\\\|?]+\s+(?=\w+\s*=))?(?<name>\w+)'
        . '|(?<type>\??[\w\\\\]+(?:\|[\w\\\\]+)*)\s+\$(?<property>\w+)'
        . ')/';

    #[Test]
    public function everyDeprecatedApiMemberIsListedWithAMigration(): void
    {
        $rows    = $this->listedRows();
        $missing = [];

        foreach (array_keys($this->deprecatedApiMembers()) as $member) {
            if (!isset($rows[$member])) {
                $missing[] = $member . ' (no row)';

                continue;
            }

            // Listed is not enough. The row's "Use instead" cell is the
            // migration; an empty one announces a removal nobody can act on.
            if ($rows[$member] === '') {
                $missing[] = $member . ' (row present, "Use instead" cell empty)';
            }
        }

        self::assertSame(
            [],
            $missing,
            "These @api members are @deprecated in the code but carry no migration entry in\n"
            . "Documentation/Api/Deprecation.rst. Add one row per member between the\n"
            . "inventory markers, and fill its third cell with the call that replaces it —\n"
            . "a deprecation without a documented migration is a removal announcement\n"
            . "nobody can act on:\n  " . implode("\n  ", $missing),
        );
    }

    #[Test]
    public function everyListedMemberIsStillDeprecatedInTheCode(): void
    {
        $known = $this->deprecatedApiMembers();
        $stale = [];

        foreach ($this->listedMembers() as $member) {
            if (!isset($known[$member])) {
                $stale[] = $member;
            }
        }

        self::assertSame(
            [],
            $stale,
            "Documentation/Api/Deprecation.rst lists members that are no longer @deprecated\n"
            . "on an @api class — they were removed, un-deprecated or renamed. Move the row to\n"
            . "the removal history or drop it:\n  " . implode("\n  ", $stale),
        );
    }

    #[Test]
    public function memberIdentifiersAreUnambiguous(): void
    {
        // The inventory keys members as `ShortClass::member`, which is only
        // safe while no two @api classes share a short name. If that ever
        // stops holding, the page must switch to fully qualified names.
        $shortNames = [];
        foreach ($this->apiFiles() as $file) {
            $short                = $file->getBasename('.php');
            $shortNames[$short][] = $file->getPathname();
        }

        $collisions = array_filter($shortNames, static fn(array $paths): bool => count($paths) > 1);

        self::assertSame(
            [],
            $collisions,
            'Two @api classes share a short name, so `ShortClass::member` no longer '
            . 'identifies a member uniquely.',
        );
    }

    /**
     * The scanner must see every shape a deprecation can be written in.
     *
     * Two of these are not hypothetical. 66 files under `Classes/` put a PHP
     * attribute between the docblock and the declaration — twelve of them
     * `@api`, including every extension-point interface — and the CI matrix
     * runs PHP 8.3+, where a class constant may carry a type. A shape the
     * pattern does not see is a deprecation that ships with no migration and
     * no failure, which is exactly what `Documentation/Api/Deprecation.rst`
     * promises cannot happen.
     */
    #[Test]
    #[DataProvider('declarationShapes')]
    public function theScannerSeesEveryDeclarationShape(string $declaration, ?string $expected): void
    {
        self::assertSame(
            1,
            preg_match(self::DECLARATION_PATTERN, $declaration, $match),
            'The pattern does not see this declaration at all, so a @deprecated tag on it '
            . 'would never reach the inventory.',
        );

        self::assertSame($expected, $this->identifierOf('Subject', $match));
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function declarationShapes(): array
    {
        $doc = "/**\n * @deprecated since 1.0, use something else\n */\n";

        return [
            'method'                => [$doc . 'public function foo(): void {}', 'Subject::foo()'],
            'method behind an attribute' => [$doc . "#[\\Override]\npublic function foo(): void {}", 'Subject::foo()'],
            'untyped constant'      => [$doc . "public const FOO = 'x';", 'Subject::FOO'],
            'typed constant'        => [$doc . "public const string FOO = 'x';", 'Subject::FOO'],
            'nullable typed constant' => [$doc . 'public const ?string FOO = null;', 'Subject::FOO'],
            'public property'       => [$doc . "public string \$foo = 'x';", 'Subject::$foo'],
            'nullable property'     => [$doc . 'public ?string $foo = null;', 'Subject::$foo'],
            'enum case'             => [$doc . "case Foo = 'x';", 'Subject::Foo'],
            'class'                 => [$doc . 'final class Subject {}', 'Subject'],
            'interface behind an attribute' => [$doc . "#[\\Attribute]\ninterface Subject {}", 'Subject'],
            // Not public, so not part of the surface the inventory covers.
            'private method'        => [$doc . 'private function foo(): void {}', null],
        ];
    }

    /**
     * Every `@deprecated` public member of an `@api`-marked class, plus the
     * class itself when the class docblock carries the tag.
     *
     * All five shapes a deprecation can take are matched — method, constant,
     * public property, enum case and the type declaration — because a gate
     * that only sees methods and constants would let the next `@deprecated`
     * property or enum case ship with no migration at all. A deprecated type
     * is keyed by its short name alone (`ShortClass`); everything else by
     * `ShortClass::member`.
     *
     * @return array<string, string> identifier => the deprecation text
     */
    private function deprecatedApiMembers(): array
    {
        $members = [];

        foreach ($this->apiFiles() as $file) {
            $source = (string)file_get_contents($file->getPathname());
            $short  = $file->getBasename('.php');

            if (preg_match_all(self::DECLARATION_PATTERN, $source, $matches, PREG_SET_ORDER) === 0) {
                continue;
            }

            foreach ($matches as $match) {
                $id = $this->identifierOf($short, $match);
                if ($id === null) {
                    continue;
                }

                $members[$id] = trim((string)preg_replace('/\s+/', ' ', $match['doc']));
            }
        }

        ksort($members);

        return $members;
    }

    /**
     * The inventory identifier for one declaration match, or null when the
     * declaration is not part of the public surface.
     *
     * @param array<int|string, string> $match a PREG_SET_ORDER match set
     */
    private function identifierOf(string $shortClass, array $match): ?string
    {
        $kind = $match['kind'] ?? '';

        // A type declaration has no visibility of its own — a `@deprecated`
        // class IS the deprecation, and it is keyed without a member part.
        if (in_array($kind, ['class', 'interface', 'trait', 'enum'], true)) {
            return $shortClass;
        }

        // Enum cases are public by definition and carry no modifier.
        if ($kind === 'case') {
            return $shortClass . '::' . $match['name'];
        }

        if (!str_contains($match['mods'], 'public')) {
            return null;
        }

        if ($kind === '') {
            return $shortClass . '::$' . ($match['property'] ?? '');
        }

        return $shortClass . '::' . $match['name'] . ($kind === 'function' ? '()' : '');
    }

    /**
     * The member identifiers the page lists between its inventory markers.
     *
     * @return list<string>
     */
    private function listedMembers(): array
    {
        return array_keys($this->listedRows());
    }

    /**
     * The inventory rows: what the page lists, and the migration it gives.
     *
     * Rows are read as three-cell list-table rows rather than by scanning the
     * whole section for identifiers, because the third cell is the point: a
     * row that names a member and offers nothing to call instead satisfies
     * "is listed" while failing the rule the page states.
     *
     * @return array<string, string> identifier => the "Use instead" cell, whitespace-collapsed
     */
    private function listedRows(): array
    {
        $page = file_get_contents(self::PAGE_PATH);
        self::assertIsString($page, 'Documentation/Api/Deprecation.rst must be readable.');

        $start = strpos($page, self::INVENTORY_START);
        $end   = strpos($page, self::INVENTORY_END);

        self::assertNotFalse($start, 'The page must carry the ' . self::INVENTORY_START . ' marker.');
        self::assertNotFalse($end, 'The page must carry the ' . self::INVENTORY_END . ' marker.');
        self::assertGreaterThan($start, $end, 'The inventory markers are in the wrong order.');

        $section = substr($page, $start, $end - $start);

        $rows   = [];
        $chunks = preg_split('/^\s*\* - /m', $section);
        self::assertIsArray($chunks, 'The inventory section could not be split into rows.');

        foreach (array_slice($chunks, 1) as $chunk) {
            // Member / Since / Use instead. The limit keeps a "- " that opens
            // a line inside the third cell from becoming a fourth one.
            $cells = preg_split('/^\s*- /m', (string)$chunk, 3);
            if (!is_array($cells)) {
                continue;
            }

            if (preg_match('/``(\w+(?:::\$?\w+(?:\(\))?)?)``/', (string)$cells[0], $identifier) !== 1) {
                // The header row, whose first cell is the word "Member".
                continue;
            }

            $rows[$identifier[1]] = isset($cells[2])
                ? trim((string)preg_replace('/\s+/', ' ', (string)$cells[2]))
                : '';
        }

        self::assertNotSame([], $rows, 'The inventory between the markers lists no member at all.');

        return $rows;
    }

    /**
     * @return list<SplFileInfo>
     */
    private function apiFiles(): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::CLASSES_DIR, FilesystemIterator::SKIP_DOTS),
        );

        $files = [];
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }

            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            if ($source === false) {
                continue;
            }

            if (preg_match('/^\s*\*\s*@api\b/m', $source) !== 1) {
                continue;
            }

            $files[] = $file;
        }

        usort($files, static fn(SplFileInfo $a, SplFileInfo $b): int => strcmp($a->getPathname(), $b->getPathname()));

        return $files;
    }
}
