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
 * Guards BASELINE.md against claiming more than CI enforces.
 *
 * BASELINE.md is a compliance attestation. Two of its rows had drifted from
 * `.github/workflows/ci.yml`: it named a TYPO3 14.0 matrix leg that had become
 * 14.3, and it presented Infection's MSI target as an enforced minimum while
 * the mutation job runs on the weekly schedule only. Both are readable out of
 * ci.yml, so both are checkable here.
 */
#[CoversNothing]
final class BaselineConsistencyTest extends AbstractUnitTestCase
{
    private function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function baseline(): string
    {
        return (string)file_get_contents($this->repoRoot() . '/BASELINE.md');
    }

    private function ciWorkflow(): string
    {
        return (string)file_get_contents($this->repoRoot() . '/.github/workflows/ci.yml');
    }

    /**
     * @return list<string> e.g. ['^13.4', '^14.3']
     */
    private function ciTypo3Versions(): array
    {
        self::assertSame(
            1,
            preg_match("/^\\s*typo3-versions:\\s*'(\\[[^']*\\])'/m", $this->ciWorkflow(), $matches),
            'ci.yml must declare typo3-versions for the blocking matrix.',
        );

        $decoded = json_decode($matches[1], true);
        self::assertIsArray($decoded);

        /** @var list<string> $decoded */
        return $decoded;
    }

    #[Test]
    public function baselineNamesExactlyTheTypo3VersionsCiRuns(): void
    {
        $baseline = $this->baseline();

        self::assertSame(
            1,
            preg_match('/^\|\s*Multi-version CI\s*\|(.*)\|$/m', $baseline, $row),
            'BASELINE.md must carry a "Multi-version CI" row.',
        );

        preg_match_all('/\^?(\d+)\.(\d+)/', $row[1], $stated);
        $statedVersions = [];
        foreach ($stated[1] as $i => $major) {
            // Skip the PHP range in the same cell (8.x); TYPO3 legs are 13.x/14.x.
            if ((int)$major >= 12) {
                $statedVersions[] = $major . '.' . $stated[2][$i];
            }
        }

        $ciVersions = array_map(
            static fn(string $constraint): string => ltrim($constraint, '^~'),
            $this->ciTypo3Versions(),
        );

        sort($statedVersions);
        sort($ciVersions);

        self::assertSame(
            $ciVersions,
            array_values(array_unique($statedVersions)),
            'BASELINE.md claims a TYPO3 matrix that ci.yml does not run.',
        );
    }

    #[Test]
    public function baselineDoesNotPresentMutationTestingAsAnEnforcedMinimum(): void
    {
        $mutationIsScheduleOnly = preg_match(
            '/run-mutation-tests:\s*\$\{\{[^}]*schedule[^}]*\}\}/',
            $this->ciWorkflow(),
        ) === 1;

        if (!$mutationIsScheduleOnly) {
            self::markTestSkipped('Mutation testing now runs outside the weekly schedule — revisit the claim in BASELINE.md.');
        }

        self::assertSame(
            0,
            preg_match('/\b(minimum|required|enforced)\s+(covered\s+)?MSI\b/i', $this->baseline()),
            'ci.yml gates the mutation job on the weekly schedule, so MSI is a target, not a minimum. '
            . 'State it as monitored in BASELINE.md.',
        );
    }

    #[Test]
    public function baselineDeclaresWhenItWasLastVerified(): void
    {
        self::assertSame(
            1,
            preg_match('/^Last verified: (\d{4})-(\d{2})-(\d{2})$/m', $this->baseline(), $matches),
            'BASELINE.md must carry a machine-readable "Last verified: YYYY-MM-DD" line.',
        );

        self::assertTrue(
            checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1]),
            'BASELINE.md "Last verified" must be a real date.',
        );
    }
}
