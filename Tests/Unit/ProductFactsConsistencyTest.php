<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit;

use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * Guards the hand-written product numbers against the code they describe.
 *
 * How many tools ship, how many groups they fall into, and whether they all
 * only read are facts about `Classes/Service/Tool/Builtin/`. They are also
 * copied by hand into the README, the administration guide and the public
 * landing page — and they drifted: the landing page still advertised "41
 * read-only tools in 8 toggleable groups" after the first two writing tools
 * and the `editing` group had shipped (ADR-134, ADR-135).
 *
 * This test does not check prose. It checks that no surface states a group
 * count, a tool count or an extension version that the source contradicts,
 * and that none of them still claims every builtin only reads.
 */
#[CoversNothing]
final class ProductFactsConsistencyTest extends AbstractUnitTestCase
{
    /** Surfaces that state product numbers to a reader outside the repository. */
    private const SURFACES = [
        'README.md',
        'Documentation/Administration/Tools.rst',
        'landingpage/build/data/content.json',
        'landingpage/build/data/content_de.json',
        'landingpage/build/data/features.json',
        'landingpage/build/data/features_de.json',
        'landingpage/build/data/security.json',
        'landingpage/build/data/security_de.json',
        'landingpage/build/data/seo.json',
    ];

    /** Files whose only version claim may be the shipped extension version. */
    private const VERSIONED_SURFACES = [
        'landingpage/build/data/content.json',
        'landingpage/build/data/content_de.json',
        'landingpage/build/data/seo.json',
    ];

    private function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return list<class-string<ToolInterface>>
     */
    private function builtinToolClasses(): array
    {
        $files = glob($this->repoRoot() . '/Classes/Service/Tool/Builtin/*Tool.php');
        self::assertIsArray($files);
        self::assertNotSame([], $files, 'No builtin tool classes found — has the directory moved?');

        $classes = [];
        foreach ($files as $file) {
            /** @var class-string $class */
            $class = 'Netresearch\\NrLlm\\Service\\Tool\\Builtin\\' . basename($file, '.php');
            $reflection = new ReflectionClass($class);
            if (!$reflection->implementsInterface(ToolInterface::class)) {
                continue;
            }

            /** @var class-string<ToolInterface> $class */
            $classes[] = $class;
        }

        return $classes;
    }

    /**
     * Builtins declare their group and their effect as literals — no builtin
     * getter reads `$this` — so an uninitialised instance answers correctly
     * without the constructor's dependencies.
     *
     * @param class-string<ToolInterface> $class
     */
    private function toolInstance(string $class): ToolInterface
    {
        $instance = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(ToolInterface::class, $instance);

        return $instance;
    }

    private function totalToolCount(): int
    {
        return count($this->builtinToolClasses());
    }

    private function readOnlyToolCount(): int
    {
        $readOnly = 0;
        foreach ($this->builtinToolClasses() as $class) {
            $tool = $this->toolInstance($class);
            if (!$tool instanceof ToolEffectInterface || !$tool->getEffect()->isWrite()) {
                ++$readOnly;
            }
        }

        return $readOnly;
    }

    /**
     * @return list<string>
     */
    private function toolGroups(): array
    {
        $groups = [];
        foreach ($this->builtinToolClasses() as $class) {
            $groups[$this->toolInstance($class)->getGroup()] = true;
        }

        return array_keys($groups);
    }

    private function groupCount(): int
    {
        return count($this->toolGroups());
    }

    private function extensionVersion(): string
    {
        $contents = (string)file_get_contents($this->repoRoot() . '/ext_emconf.php');
        self::assertSame(1, preg_match("/'version'\\s*=>\\s*'([^']+)'/", $contents, $matches));

        return $matches[1];
    }

    private function surfaceContents(string $surface): string
    {
        $path = $this->repoRoot() . '/' . $surface;
        self::assertFileExists($path);

        return (string)file_get_contents($path);
    }

    /**
     * @return list<array{string}>
     */
    public static function surfaceProvider(): array
    {
        return array_map(static fn(string $s): array => [$s], self::SURFACES);
    }

    /**
     * @return list<array{string}>
     */
    public static function versionedSurfaceProvider(): array
    {
        return array_map(static fn(string $s): array => [$s], self::VERSIONED_SURFACES);
    }

    #[Test]
    #[DataProvider('surfaceProvider')]
    public function statedGroupCountMatchesTheShippedGroups(string $surface): void
    {
        $expected = $this->groupCount();

        preg_match_all(
            '/(\d+)\s+(?:toggleable|schaltbaren|umschaltbaren)\s+(?:groups|Gruppen)/iu',
            $this->surfaceContents($surface),
            $matches,
        );

        foreach ($matches[1] as $stated) {
            self::assertSame(
                $expected,
                (int)$stated,
                $surface . ' claims ' . $stated . ' tool groups; Classes/Service/Tool/Builtin declares '
                . $expected . '. Update the surface, not this test.',
            );
        }
    }

    #[Test]
    #[DataProvider('surfaceProvider')]
    public function statedToolCountIsEitherTheTotalOrTheReadOnlySubset(string $surface): void
    {
        $total    = $this->totalToolCount();
        $readOnly = $this->readOnlyToolCount();

        // Adjacency only. `2 of the 46 built-in tools` is a true statement whose
        // leading number is an arbitrary subset, so bridging over `of the` /
        // `der` would reject it; the number next to the noun phrase is the
        // claim. German uses the predicative `nur lesend` — no trailing `e` —
        // which an earlier version missed, leaving the read-only count
        // unguarded on every German surface.
        preg_match_all(
            '/(\d+)\s+(?:eingebaute[nr]?|built-in|builtin|Built-in-Tools|read-only|nur\s+lesend|Function-Calling-Tools)/iu',
            $this->surfaceContents($surface),
            $matches,
        );

        foreach ($matches[1] as $stated) {
            self::assertContains(
                (int)$stated,
                [$total, $readOnly],
                $surface . ' states a builtin tool count of ' . $stated . '; the shipped numbers are '
                . $total . ' total and ' . $readOnly . ' read-only.',
            );
        }
    }

    #[Test]
    #[DataProvider('surfaceProvider')]
    public function noSurfaceClaimsEveryBuiltinToolOnlyReads(string $surface): void
    {
        // True until ADR-135. A writing tool shipping does not make the claim
        // stale everywhere by itself — someone has to go and change the prose,
        // and for four months nobody did.
        self::assertDoesNotMatchRegularExpression(
            '/\b(?:all|every\s+one\s+of\s+the|alle|jedes\s+der)\s+\d+\s+(?:built-in|builtin|eingebaute|Built-in-Tools)/iu',
            $this->surfaceContents($surface),
            $surface . ' still claims that every builtin tool is read-only. Two write (ADR-134, ADR-135).',
        );
    }

    #[Test]
    #[DataProvider('versionedSurfaceProvider')]
    public function statedExtensionVersionMatchesExtEmconf(string $surface): void
    {
        $expected = $this->extensionVersion();

        // Only nr_llm's own 0.x.y line — TYPO3 and PHP versions never match.
        preg_match_all('/(?<![\d.])0\.\d+\.\d+(?![\d.])/', $this->surfaceContents($surface), $matches);

        foreach ($matches[0] as $stated) {
            self::assertSame(
                $expected,
                $stated,
                $surface . ' advertises version ' . $stated . '; ext_emconf.php ships ' . $expected . '.',
            );
        }
    }

    /**
     * The tool name each builtin registers, read from its `ToolSpec::function(…)`
     * literal.
     *
     * Not via reflection: `UpdatePageMetadataTool::getSpec()` reads `$this`
     * (its field allow-list depends on whether EXT:seo is installed), so an
     * uninitialised instance cannot answer for every tool the way `getGroup()`
     * and `getEffect()` can.
     *
     * @return list<string>
     */
    private function builtinToolNames(): array
    {
        $names = [];
        foreach ($this->builtinToolClasses() as $class) {
            $file     = (string)(new ReflectionClass($class))->getFileName();
            $contents = (string)file_get_contents($file);

            self::assertSame(
                1,
                preg_match("/ToolSpec::function\\(\\s*'([a-z_]+)'/", $contents, $matches),
                $class . ' must register its name through ToolSpec::function().',
            );

            $names[] = $matches[1];
        }

        return $names;
    }

    #[Test]
    public function theAdministrationGuideListsEveryToolAndGroup(): void
    {
        // Tools.rst spells its counts out in words, so the numeric assertions
        // above never fire on it. Its group table is the artifact that drifts,
        // and this is what guards it.
        $guide = $this->surfaceContents('Documentation/Administration/Tools.rst');

        foreach ($this->builtinToolNames() as $name) {
            if (str_ends_with($name, '_raw')) {
                // The table writes a raw variant as a shorthand attached to its
                // base name: ``get_env`` (+ raw). Asserting only the base name
                // would be satisfied by the base tool's own row, so the raw
                // variant could drop out of the guide unnoticed.
                self::assertStringContainsString(
                    '``' . substr($name, 0, -4) . '`` (+ raw)',
                    $guide,
                    'Documentation/Administration/Tools.rst does not mark ' . $name . ' with the (+ raw) shorthand.',
                );

                continue;
            }

            self::assertStringContainsString(
                '``' . $name . '``',
                $guide,
                'Documentation/Administration/Tools.rst does not list the tool ' . $name . '.',
            );
        }

        foreach ($this->toolGroups() as $group) {
            self::assertStringContainsString(
                '``' . $group . '``',
                $guide,
                'Documentation/Administration/Tools.rst does not list the tool group ' . $group . '.',
            );
        }
    }

    #[Test]
    public function theCountersResolveTheWholeBuiltinDirectory(): void
    {
        // Without a hard expectation every surface assertion above passes
        // vacuously on a directory that silently shrank: comparing the counters
        // against a glob they are themselves derived from cannot fail.
        // Changing these two numbers is a deliberate act — the surfaces move
        // with them.
        self::assertSame(47, $this->totalToolCount(), 'Builtin tool count changed. Update every surface, then this number.');
        self::assertSame(9, $this->groupCount(), 'Tool group count changed. Update every surface, then this number.');

        self::assertCount($this->totalToolCount(), $this->builtinToolNames());
        self::assertSame($this->totalToolCount(), $this->readOnlyToolCount() + 6, 'Exactly six builtins write (ADR-134, ADR-135, ADR-146, ADR-180).');
    }
}
