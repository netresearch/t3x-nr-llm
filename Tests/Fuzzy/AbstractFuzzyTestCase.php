<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fuzzy;

use Eris\Generator;
use Eris\TestTrait;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;

/**
 * Base class for fuzzy/property-based tests.
 *
 * Uses Eris for property-based testing with random inputs.
 */
abstract class AbstractFuzzyTestCase extends AbstractUnitTestCase
{
    use TestTrait;

    /**
     * Generate random float between min and max.
     *
     * Uses integer division to avoid floating point precision issues
     *
     * @return Generator<float>
     */
    protected function floatBetween(float $min, float $max): Generator
    {
        // @phpstan-ignore function.notFound, return.type
        return Generator\map(
            static function (int $n) use ($min, $max) {
                // Use a scale factor to get better precision without edge case issues
                $scale = abs($n % 10001) / 10000.0; // 0.0 to 1.0
                $value = $min + ($max - $min) * $scale;
                // Clamp to ensure we stay in bounds
                return max($min, min($max, $value));
            },
            Generator\int(), // @phpstan-ignore function.notFound
        );
    }

    /**
     * Generate random embedding vector of given dimension.
     *
     * @param int $dimensions Number of dimensions
     *
     * @return Generator<array<float>>
     */
    protected function embeddingVector(int $dimensions = 1536): Generator
    {
        // @phpstan-ignore function.notFound, return.type
        return Generator\tuple(
            ...array_fill(0, min($dimensions, 100), $this->floatBetween(-1.0, 1.0)),
        );
    }

    /**
     * Generate non-empty string.
     *
     * @return Generator<string>
     */
    protected function nonEmptyText(): Generator
    {
        // @phpstan-ignore function.notFound, return.type
        return Generator\filter(
            static fn(string $s) => strlen(trim($s)) > 0,
            Generator\string(), // @phpstan-ignore function.notFound
        );
    }

    /**
     * Generate positive integer.
     *
     * @return Generator<int>
     */
    protected function positiveInt(): Generator
    {
        // @phpstan-ignore function.notFound, return.type
        return Generator\pos();
    }

    /**
     * Generate temperature value (0.0 to 2.0).
     *
     * @return Generator<float>
     */
    protected function temperature(): Generator
    {
        return $this->floatBetween(0.0, 2.0);
    }

    /**
     * Generate top_p value (0.0 to 1.0).
     *
     * @return Generator<float>
     */
    protected function topP(): Generator
    {
        return $this->floatBetween(0.0, 1.0);
    }

    /**
     * Generate penalty value (-2.0 to 2.0).
     *
     * @return Generator<float>
     */
    protected function penalty(): Generator
    {
        return $this->floatBetween(-2.0, 2.0);
    }
}
