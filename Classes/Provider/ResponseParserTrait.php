<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider;

use InvalidArgumentException;

/**
 * Trait for type-safe API response parsing.
 *
 * Provides helper methods to access array values with proper type assertions,
 * enabling PHPStan level 9+ compliance when parsing external API responses.
 */
trait ResponseParserTrait
{
    /**
     * Get a string value from array, with optional default.
     *
     * @param array<string, mixed> $data
     */
    protected function getString(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? $default;

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        return $default;
    }

    /**
     * Get an integer value from array, with optional default.
     *
     * @param array<string, mixed> $data
     */
    protected function getInt(array $data, string $key, int $default = 0): int
    {
        $value = $data[$key] ?? $default;

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int)$value;
        }

        return $default;
    }

    /**
     * Get a float value from array, with optional default.
     *
     * @param array<string, mixed> $data
     */
    protected function getFloat(array $data, string $key, float $default = 0.0): float
    {
        $value = $data[$key] ?? $default;

        if (is_float($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float)$value;
        }

        return $default;
    }

    /**
     * Get a boolean value from array, with optional default.
     *
     * @param array<string, mixed> $data
     */
    protected function getBool(array $data, string $key, bool $default = false): bool
    {
        $value = $data[$key] ?? $default;

        if (is_bool($value)) {
            return $value;
        }

        return $default;
    }

    /**
     * Get an associative array value from array, with optional default.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $default
     *
     * @return array<string, mixed>
     */
    protected function getArray(array $data, string $key, array $default = []): array
    {
        $value = $data[$key] ?? $default;

        if (!is_array($value)) {
            return $default;
        }
        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Get a list value from array (array of objects like 'choices', 'candidates', etc.).
     *
     * @param array<string, mixed> $data
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getList(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        if (!is_array($value)) {
            return [];
        }
        /** @var array<int, array<string, mixed>> $value */
        return $value;
    }

    /**
     * Get a nullable string value from array.
     *
     * @param array<string, mixed> $data
     */
    protected function getNullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        return null;
    }

    /**
     * Get a nullable integer value from array.
     *
     * @param array<string, mixed> $data
     */
    protected function getNullableInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int)$value;
        }

        return null;
    }

    /**
     * Get nested array value using dot notation.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $default
     *
     * @return array<string, mixed>
     */
    protected function getNestedArray(array $data, string $path, array $default = []): array
    {
        $keys = explode('.', $path);
        $current = $data;

        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return $default;
            }
            $current = $current[$key];
        }

        if (!is_array($current)) {
            return $default;
        }
        /** @var array<string, mixed> $current */
        return $current;
    }

    /**
     * Get nested string value using dot notation.
     *
     * @param array<string, mixed> $data
     */
    protected function getNestedString(array $data, string $path, string $default = ''): string
    {
        $keys = explode('.', $path);
        $current = $data;

        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return $default;
            }
            $current = $current[$key];
        }

        if (is_string($current)) {
            return $current;
        }

        if (is_int($current) || is_float($current)) {
            return (string)$current;
        }

        return $default;
    }

    /**
     * Get nested int value using dot notation.
     *
     * @param array<string, mixed> $data
     */
    protected function getNestedInt(array $data, string $path, int $default = 0): int
    {
        $keys = explode('.', $path);
        $current = $data;

        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return $default;
            }
            $current = $current[$key];
        }

        if (is_int($current)) {
            return $current;
        }

        if (is_numeric($current)) {
            return (int)$current;
        }

        return $default;
    }

    /**
     * Assert that a value is an associative array and return it with string keys.
     *
     * Used for API response objects that have string keys.
     *
     * @param array<string, mixed> $default
     *
     * @return array<string, mixed>
     */
    protected function asArray(mixed $value, array $default = []): array
    {
        if (!is_array($value)) {
            return $default;
        }
        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Assert that a value is a list of associative arrays.
     *
     * Used for API response lists like 'choices', 'candidates', 'data', etc.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function asList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        /** @var array<int, array<string, mixed>> $value */
        return $value;
    }

    /**
     * Assert that a value is a string and return it.
     */
    protected function asString(mixed $value, string $default = ''): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        return $default;
    }

    /**
     * Assert that a value is an int and return it.
     */
    protected function asInt(mixed $value, int $default = 0): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int)$value;
        }

        return $default;
    }

    /**
     * Assert that a value is a float and return it.
     */
    protected function asFloat(mixed $value, float $default = 0.0): float
    {
        if (is_float($value) || is_int($value)) {
            return (float)$value;
        }

        if (is_numeric($value)) {
            return (float)$value;
        }

        return $default;
    }

    /**
     * Safely decode JSON and return as array.
     *
     *
     * @throws InvalidArgumentException If JSON is invalid
     *
     * @return array<string, mixed>
     */
    protected function decodeJsonResponse(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Expected JSON object, got ' . gettype($decoded), 8142137949);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
