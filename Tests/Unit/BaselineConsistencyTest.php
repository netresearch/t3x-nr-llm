<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit;

use Netresearch\NrLlm\Tests\Unit\Support\CiMatrixReaderTrait;
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
    use CiMatrixReaderTrait;

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
     * The TYPO3 constraints the main `ci:` job runs, e.g. ['^13.4', '^14.3'].
     *
     * Job-scoped on purpose: `ci-functional-mariadb` declares its own, narrower
     * list, and BASELINE.md's row attests to the main matrix. The parsing rule
     * itself lives in {@see CiMatrixReaderTrait}, alongside the union reader
     * `VersionConsistencyTest` uses for the other question.
     *
     * @return list<string>
     */
    private function ciTypo3Versions(): array
    {
        return $this->ciMatrixValuesOfJob($this->ciWorkflow(), 'ci', 'typo3-versions', '/"(\^?[\d.]+)"/');
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
    public function mutationTestingStillRunsOnTheWeeklyScheduleOnly(): void
    {
        // Asserted rather than skipped over: BASELINE.md states this positively
        // ("runs on the weekly schedule only"), so the day it stops being true
        // the attestation is wrong and must fail, not go quiet.
        self::assertSame(
            1,
            preg_match('/run-mutation-tests:\s*\$\{\{[^}]*schedule[^}]*\}\}/', $this->ciWorkflow()),
            'BASELINE.md says mutation runs on the weekly schedule only. ci.yml no longer gates it that way.',
        );
    }

    #[Test]
    public function baselineDoesNotPresentMutationTestingAsAnEnforcedMinimum(): void
    {
        self::assertSame(
            0,
            preg_match('/\b(minimum|required|enforced)\s+(covered\s+)?MSI\b/i', $this->baseline()),
            'ci.yml gates the mutation job on the weekly schedule, so MSI is a target, not a minimum. '
            . 'State it as monitored in BASELINE.md.',
        );
    }

    /**
     * How long an attestation may stand before it must be re-checked.
     *
     * This deliberately makes the build fail on a calendar, which is otherwise
     * a bad property for a test. It is the right one here: the archived
     * 2026-01-05 audit set its own review date to 2026-07-05, that date passed
     * unnoticed, and the document kept saying "Ready for production" for
     * another five months. A yearly red build is the reminder that was missing.
     */
    private const MAX_AGE_DAYS = 365;

    #[Test]
    public function baselineDeclaresWhenItWasLastVerifiedAndIsNotStale(): void
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

        $verified = (int)mktime(0, 0, 0, (int)$matches[2], (int)$matches[3], (int)$matches[1]);
        $ageDays  = (int)floor((time() - $verified) / 86400);

        self::assertLessThanOrEqual(
            self::MAX_AGE_DAYS,
            $ageDays,
            'BASELINE.md was last verified ' . $ageDays . ' days ago. Re-check every criterion against the '
            . 'repository and the rulesets (gh api repos/netresearch/t3x-nr-llm/rules/branches/main), then '
            . 'bump the date. Do not bump it without re-checking.',
        );

        self::assertGreaterThanOrEqual(
            0,
            $ageDays,
            'BASELINE.md claims it was verified in the future.',
        );
    }
}
