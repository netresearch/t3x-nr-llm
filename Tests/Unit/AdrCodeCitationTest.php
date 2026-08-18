<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Checks the `File.php:NNN` citations in the ADR corpus against the tree.
 *
 * A line number is invalidated by any edit above it in the cited file, and
 * nothing failed when that happened: ADR-171 cited ResumeCoordinator.php:204
 * for an authorisation check that a later commit pushed to :205, and the record
 * went on reading like a verified citation.
 *
 * What this catches, exactly:
 *
 * - a citation naming a file that is no longer in the tree, or whose basename
 *   became ambiguous;
 * - a citation pointing past the end of its file, or at a blank line.
 *
 * What it does NOT catch, stated so nobody reads more into it: a line that
 * moved onto different but non-blank code. That is the common case and the
 * dangerous one — the citation then asserts something the cited code does not
 * say, and its precision is what makes a reviewer trust it. No test can tell
 * the difference, which is the argument for citing a symbol rather than a line
 * number wherever the prose allows it.
 *
 * Citations into TYPO3 core and sibling extensions cannot be checked here at
 * all: that code lives in .Build/vendor, which is not committed, and its line
 * numbers move with every patch release of a dependency this repository does
 * not pin. They are listed instead, so adding one is a deliberate act rather
 * than something the resolver quietly skips.
 */
#[CoversNothing]
final class AdrCodeCitationTest extends AbstractUnitTestCase
{
    /**
     * Citations that resolve to no file in this repository, as
     * ADR basename => list of cited paths.
     *
     * Every entry is TYPO3 core or a sibling extension, read from
     * .Build/vendor while the record was written. Nothing here is verifiable
     * by this suite; the list exists so a NEW unverifiable citation fails
     * rather than passing as one of these.
     *
     * @var array<string, list<string>>
     */
    private const CITATIONS_OUTSIDE_THE_REPOSITORY = [
        'Adr140EffectivePolicyReadoutWithoutApplyPath.rst' => [
            'ExtensionConfiguration.php',
        ],
        'Adr169RecordManagementUsesTypo3Permissions.rst' => [
            'BackendUtility.php',
            'Clipboard.php',
            'DataHandler.php',
            'DatabaseUserPermissionCheck.php',
            'ElementHistoryController.php',
            'ElementInformationController.php',
            'RecordHistory.php',
            'RecordListController.php',
            'RootLevelCapability.php',
            'SuggestWizardController.php',
            'TcaItemsProcessorFunctions.php',
            'Typo3Version.php',
            'VaultFieldHelper.php',
            'cms-install/Configuration/Backend/Modules.php',
            'nr-vault/Configuration/Backend/Modules.php',
        ],
        'Adr171PersonasTheCodeAlreadyAssumes.rst' => [
            'cms-install/Configuration/Backend/Modules.php',
        ],
    ];

    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Every `File.php:NNN` or `File.php:NNN-MMM` in the corpus.
     *
     * @return list<array{adr: string, adrLine: int, path: string, from: int, to: int}>
     */
    private function citations(): array
    {
        $files = glob($this->repositoryRoot() . '/Documentation/Adr/Adr*.rst');
        self::assertIsArray($files);
        self::assertNotSame([], $files);

        $citations = [];
        foreach ($files as $file) {
            $lines = explode("\n", (string)file_get_contents($file));
            foreach ($lines as $index => $line) {
                preg_match_all('#([A-Za-z0-9_/.-]+\.php):(\d+)(?:-(\d+))?#', $line, $matches, PREG_SET_ORDER);
                foreach ($matches as $match) {
                    $citations[] = [
                        'adr'     => basename($file),
                        'adrLine' => $index + 1,
                        'path'    => $match[1],
                        'from'    => (int)$match[2],
                        'to'      => (int)($match[3] ?? $match[2]),
                    ];
                }
            }
        }

        return $citations;
    }

    /**
     * Basename => every path under the repository root carrying it.
     *
     * .Build holds the composer install, landingpage a generator with its own
     * tree; neither is what an ADR means when it names a file.
     *
     * @return array<string, list<string>>
     */
    private function basenameIndex(): array
    {
        $root     = $this->repositoryRoot();
        $skip     = ['.Build', '.git', 'landingpage', 'node_modules', 'var', '.ddev'];
        $index    = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $relative = ltrim(str_replace($root, '', $entry->getPathname()), '/');
            $segment  = explode('/', $relative)[0];
            if (in_array($segment, $skip, true)) {
                continue;
            }

            if (!$entry->isFile() || $entry->getExtension() !== 'php') {
                continue;
            }

            $index[$entry->getBasename()][] = $relative;
        }

        return $index;
    }

    /**
     * The repository file a citation names, or null when it names none.
     *
     * @param array<string, list<string>> $index
     */
    private function resolve(string $path, array $index, string $where): ?string
    {
        if (str_contains($path, '/')) {
            return is_file($this->repositoryRoot() . '/' . $path) ? $path : null;
        }

        $hits = $index[$path] ?? [];
        self::assertLessThan(
            2,
            count($hits),
            sprintf(
                '%s cites "%s" by basename alone and the repository now holds %d files with that name (%s). '
                . 'Cite it with its path so the reference names one file.',
                $where,
                $path,
                count($hits),
                implode(', ', $hits),
            ),
        );

        return $hits[0] ?? null;
    }

    #[Test]
    public function everyCitationNamesAFileThatStillExists(): void
    {
        $index    = $this->basenameIndex();
        $declared = self::CITATIONS_OUTSIDE_THE_REPOSITORY;
        $orphans  = [];

        foreach ($this->citations() as $citation) {
            $where = sprintf('%s:%d', $citation['adr'], $citation['adrLine']);
            if ($this->resolve($citation['path'], $index, $where) !== null) {
                continue;
            }

            if (in_array($citation['path'], $declared[$citation['adr']] ?? [], true)) {
                continue;
            }

            $orphans[] = sprintf('%s cites %s, which is in neither the tree nor the declared list', $where, $citation['path']);
        }

        self::assertSame([], $orphans, implode("\n", $orphans));
    }

    #[Test]
    public function noCitationPointsPastTheEndOfItsFileOrAtABlankLine(): void
    {
        $index   = $this->basenameIndex();
        $stale   = [];

        foreach ($this->citations() as $citation) {
            $where    = sprintf('%s:%d', $citation['adr'], $citation['adrLine']);
            $resolved = $this->resolve($citation['path'], $index, $where);
            if ($resolved === null) {
                continue;
            }

            $lines = explode("\n", (string)file_get_contents($this->repositoryRoot() . '/' . $resolved));
            foreach ([$citation['from'], $citation['to']] as $number) {
                if ($number > count($lines)) {
                    $stale[] = sprintf('%s cites %s:%d; the file ends at line %d', $where, $resolved, $number, count($lines));
                    continue;
                }

                if (trim($lines[$number - 1]) === '') {
                    $stale[] = sprintf('%s cites %s:%d, which is blank', $where, $resolved, $number);
                }
            }
        }

        self::assertSame([], $stale, implode("\n", $stale));
    }

    #[Test]
    public function theListOfUncheckableCitationsMatchesTheCorpus(): void
    {
        $index  = $this->basenameIndex();
        $actual = [];

        foreach ($this->citations() as $citation) {
            $where = sprintf('%s:%d', $citation['adr'], $citation['adrLine']);
            if ($this->resolve($citation['path'], $index, $where) !== null) {
                continue;
            }

            $actual[$citation['adr']][$citation['path']] = true;
        }

        $normalised = [];
        foreach ($actual as $adr => $paths) {
            $names = array_keys($paths);
            sort($names);
            $normalised[$adr] = $names;
        }

        ksort($normalised);

        $declared = self::CITATIONS_OUTSIDE_THE_REPOSITORY;
        ksort($declared);

        self::assertSame(
            $declared,
            $normalised,
            'The citations that resolve to no file in this repository have changed. Every one of them is '
            . 'unverifiable by this suite, so the list is maintained by hand: add the new entry deliberately, '
            . 'or drop one that no longer appears.',
        );
    }
}
