<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Support;

/**
 * The one place `.github/workflows/ci.yml`'s matrix keys are parsed.
 *
 * `php-versions` and `typo3-versions` appear in more than one workflow call:
 * the main `ci:` job runs the full list, `ci-functional-mariadb` declares its
 * own narrower one, and the merge queue reduces the PHP set inline. Two tests
 * read those keys, for two different questions — and reading them twice with
 * two hand-rolled rules is how the answers drift apart the day a third job
 * appears.
 *
 * - {@see ciMatrixValuesOfJob()} answers *what does THIS job run*.
 *   `BASELINE.md`'s "Multi-version CI" row describes the main matrix, so
 *   `BaselineConsistencyTest` asks the `ci:` job alone: a narrower sibling leg
 *   must neither weaken nor widen that attestation.
 * - {@see ciMatrixUnion()} answers *what is the extension tested against at
 *   all*. `Documentation/Api/SupportMatrix.rst` promises support, and support
 *   is the union over every leg — a single cell is a subset by design.
 *
 * A new job therefore widens the union on its own and leaves the job-scoped
 * read untouched. For both assertions that is the intended behaviour, not an
 * oversight.
 */
trait CiMatrixReaderTrait
{
    /**
     * The values one job declares for a matrix key.
     *
     * @return list<string> sorted and deduplicated
     */
    private function ciMatrixValuesOfJob(
        string $workflow,
        string $job,
        string $key,
        string $valuePattern,
    ): array {
        self::assertSame(
            1,
            preg_match(
                '/^  ' . preg_quote($job, '/') . ':\n.*?^[ \t]*'
                . preg_quote($key, '/') . ':[ \t]*([^\n]*)$/ms',
                $workflow,
                $line,
            ),
            '.github/workflows/ci.yml must declare ' . $key . ' inside the `' . $job . ':` job.',
        );

        return $this->ciMatrixValues([$line[1]], $key, $valuePattern);
    }

    /**
     * Every value the named matrix key carries anywhere in the workflow.
     *
     * @return list<string> sorted and deduplicated
     */
    private function ciMatrixUnion(string $workflow, string $key, string $valuePattern): array
    {
        self::assertGreaterThan(
            0,
            preg_match_all(
                '/^[ \t]*' . preg_quote($key, '/') . ':[ \t]*([^\n]*)$/m',
                $workflow,
                $lines,
            ),
            '.github/workflows/ci.yml declares no ' . $key . ' — the matrix moved.',
        );

        return $this->ciMatrixValues($lines[1], $key, $valuePattern);
    }

    /**
     * @param list<string> $lines the raw right-hand sides of the matched key lines
     *
     * @return list<string>
     */
    private function ciMatrixValues(array $lines, string $key, string $valuePattern): array
    {
        $values = [];
        foreach ($lines as $line) {
            preg_match_all($valuePattern, $line, $found);
            foreach ($found[1] as $value) {
                $values[] = $value;
            }
        }

        $values = array_values(array_unique($values));
        sort($values);

        self::assertNotSame([], $values, 'No ' . $key . ' values parsed out of .github/workflows/ci.yml.');

        return $values;
    }
}
