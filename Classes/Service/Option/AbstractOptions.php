<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Option;

use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * Base class for typed option objects.
 *
 * Provides common functionality for all option classes including
 * array conversion, merging, and validation helpers.
 */
abstract class AbstractOptions
{
    /**
     * Convert options to array format for providers.
     *
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;

    /**
     * Validate value is within numeric range.
     *
     * @throws InvalidArgumentException
     */
    protected static function validateRange(
        float|int $value,
        float|int $min,
        float|int $max,
        string $name,
    ): void {
        if ($value < $min || $value > $max) {
            throw new InvalidArgumentException(
                sprintf('%s must be between %s and %s, got %s', $name, $min, $max, $value),
                3976896171,
            );
        }
    }

    /**
     * Validate value is one of allowed options.
     *
     * @param array<int, string> $allowed
     *
     * @throws InvalidArgumentException
     */
    protected static function validateEnum(string $value, array $allowed, string $name): void
    {
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException(
                sprintf('%s must be one of: %s, got "%s"', $name, implode(', ', $allowed), $value),
                8287317140,
            );
        }
    }

    /**
     * Validate value is positive integer.
     *
     * @throws InvalidArgumentException
     */
    protected static function validatePositiveInt(int $value, string $name): void
    {
        if ($value < 1) {
            throw new InvalidArgumentException(
                sprintf('%s must be a positive integer, got %d', $name, $value),
                5622106267,
            );
        }
    }

    /**
     * Filter null values from array.
     *
     * @param array<string, mixed> $array
     *
     * @return array<string, mixed>
     */
    protected function filterNull(array $array): array
    {
        return array_filter($array, static fn($v) => $v !== null);
    }
}
