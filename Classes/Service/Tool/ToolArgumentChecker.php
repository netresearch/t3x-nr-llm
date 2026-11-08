<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function floor;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_finite;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function json_encode;
use function mb_strlen;
use function sprintf;
use function strtolower;

use const JSON_UNESCAPED_UNICODE;

/**
 * Check model arguments against offered tool schemas (ADR-246). Boolean schemas require native JSON booleans so accepted values preserve meaning through PHP casts. Other documented scalar leniency remains; unjudgeable schema constructs are delegated to the tool. @internal.
 */
final readonly class ToolArgumentChecker
{
    /** At most this many mismatches are named; a model fixes the rest next. */
    public const MAX_VIOLATIONS = 10;

    /** At most this many enum values or parameter names are listed. */
    private const MAX_LISTED = 20;

    private const UNJUDGEABLE = ['$ref', 'oneOf', 'anyOf', 'allOf', 'not', 'if'];

    /**
     * @param array<array-key, mixed> $arguments the decoded arguments of the call
     * @param array<array-key, mixed> $schema    the `parameters` of the offered tool spec
     *
     * @return list<string> one sentence per mismatch, empty when the arguments fit
     */
    public function check(array $arguments, array $schema): array
    {
        if ($arguments !== [] && array_is_list($arguments)) {
            return ['The arguments must be a JSON object of named parameters, not a list.'];
        }

        $violations = [];
        $this->checkObject($arguments, $schema, '', $violations);

        return array_slice($violations, 0, self::MAX_VIOLATIONS);
    }

    /**
     * @param array<array-key, mixed> $value
     * @param array<array-key, mixed> $schema
     * @param list<string>            $violations
     */
    private function checkObject(array $value, array $schema, string $path, array &$violations): void
    {
        if ($this->unjudgeable($schema)) {
            return;
        }

        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        foreach ($required as $key) {
            if (is_string($key) && (!array_key_exists($key, $value) || ($value[$key] === null && !$this->admitsNull($properties[$key] ?? null)))) {
                $violations[] = sprintf('"%s" is required.', $this->join($path, $key));
            }
        }

        foreach ($value as $key => $item) {
            $key = (string) $key;
            if (array_key_exists($key, $properties)) {
                $propertySchema = $properties[$key];
                if (is_array($propertySchema) && $item !== null) {
                    $this->checkValue($item, $propertySchema, $this->join($path, $key), $violations);
                }

                continue;
            }

            if (($schema['additionalProperties'] ?? true) === false) {
                $names        = array_map(strval(...), array_keys($properties));
                $violations[] = $names === []
                    ? sprintf('"%s" is not a parameter here; this takes none.', $this->join($path, $key))
                    : sprintf('"%s" is not a parameter here. Parameters: %s.', $this->join($path, $key), $this->listed($names));
            }
        }
    }

    /**
     * @param array<array-key, mixed> $schema
     * @param list<string>            $violations
     */
    private function checkValue(mixed $value, array $schema, string $path, array &$violations): void
    {
        if ($this->unjudgeable($schema)) {
            return;
        }

        $types = $this->declaredTypes($schema['type'] ?? null);
        if ($types !== [] && !$this->matchesAny($value, $types)) {
            $violations[] = sprintf('"%s" must be %s, not %s.', $path, $this->typePhrase($types), $this->kindOf($value));

            return;
        }

        if (is_array($schema['enum'] ?? null) && $schema['enum'] !== [] && !$this->inEnum($value, $schema['enum'])) {
            $violations[] = sprintf(
                '"%s" must be one of: %s.',
                $path,
                $this->listed(array_values(array_map(static fn (mixed $v): string => (string) json_encode($v), $schema['enum']))),
            );

            return;
        }

        if (!is_array($value)) {
            return;
        }

        if (array_is_list($value) && $value !== []) {
            if (is_array($schema['items'] ?? null) && !array_is_list($schema['items'])) {
                foreach ($value as $index => $item) {
                    if ($item !== null) {
                        $this->checkValue($item, $schema['items'], sprintf('%s[%d]', $path, $index), $violations);
                    }
                }
            }

            return;
        }

        if (in_array('object', $types, true) || isset($schema['properties']) || isset($schema['required'])) {
            $this->checkObject($value, $schema, $path, $violations);
        }
    }

    /**
     * Whether null is a value a required argument may carry: only a property
     * schema that declares its types and leaves "null" out rules it out. A
     * nullable required argument (zod `.nullable()`, pydantic `str | None`
     * without a default, which writes an `anyOf` and no `type`) is sent as
     * null on purpose.
     */
    private function admitsNull(mixed $propertySchema): bool
    {
        if (!is_array($propertySchema)) {
            return true;
        }

        $types = $this->declaredTypes($propertySchema['type'] ?? null);

        return $types === [] || in_array('null', $types, true);
    }

    /**
     * @param array<array-key, mixed> $schema
     */
    private function unjudgeable(array $schema): bool
    {
        foreach (self::UNJUDGEABLE as $keyword) {
            if (array_key_exists($keyword, $schema)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function declaredTypes(mixed $type): array
    {
        if (is_string($type)) {
            return [$type];
        }

        if (!is_array($type)) {
            return [];
        }

        $types = [];
        foreach ($type as $one) {
            if (is_string($one)) {
                $types[] = $one;
            }
        }

        return $types;
    }

    /**
     * @param list<string> $types
     */
    private function matchesAny(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            if ($this->matches($value, $type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lenient on purpose: see the class comment for what passes and why.
     */
    private function matches(mixed $value, string $type): bool
    {
        return match ($type) {
            'string'  => is_string($value) || is_int($value) || is_float($value),
            'integer' => is_int($value)
                // floor(), not an int cast: a float beyond the integer range
                // warns when cast, and PHP's warnings may be exceptions here.
                || (is_float($value) && is_finite($value) && floor($value) === $value)
                || (is_string($value) && is_numeric($value) && (string) (int) $value === $value),
            'number'  => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)),
            'boolean' => is_bool($value),
            'array'   => is_array($value) && ($value === [] || array_is_list($value)),
            // Any array: a JSON object whose keys are 0, 1, 2 (a map keyed by
            // language uid) decodes to a PHP list and cannot be told apart.
            'object' => is_array($value),
            'null'   => $value === null,
            // An unknown type name is not this check's to judge.
            default => true,
        };
    }

    /**
     * @param array<array-key, mixed> $enum
     */
    private function inEnum(mixed $value, array $enum): bool
    {
        foreach ($enum as $allowed) {
            if ($allowed === $value) {
                return true;
            }

            // "5" for 5, 5 for "5", and "Partial" for "partial": a tool that
            // reads the value commonly casts and lowercases it.
            if ((is_int($allowed) || is_float($allowed) || is_string($allowed))
                && (is_int($value) || is_float($value) || is_string($value))
                && strtolower((string) $allowed) === strtolower((string) $value)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $types
     */
    private function typePhrase(array $types): string
    {
        $phrases = array_map(static fn (string $type): string => match ($type) {
            'string'  => 'text',
            'integer' => 'an integer',
            'number'  => 'a number',
            'boolean' => 'true or false',
            'array'   => 'a list',
            'object'  => 'an object',
            'null'    => 'null',
            default   => $type,
        }, $types);

        return implode(' or ', $phrases);
    }

    private function kindOf(mixed $value): string
    {
        return match (true) {
            is_array($value)                 => $value !== [] && array_is_list($value) ? 'a list' : 'an object',
            is_bool($value)                  => $value ? 'true' : 'false',
            is_int($value), is_float($value) => 'the number ' . json_encode($value),
            // The model's own value, echoed back to it; a long one is not.
            is_string($value) => mb_strlen($value) <= 40 ? 'the text ' . json_encode($value, JSON_UNESCAPED_UNICODE) : 'text',
            default           => 'null',
        };
    }

    /**
     * @param list<string> $items
     */
    private function listed(array $items): string
    {
        $shown = implode(', ', array_slice($items, 0, self::MAX_LISTED));

        return count($items) > self::MAX_LISTED ? $shown . ', …' : $shown;
    }

    private function join(string $path, string $key): string
    {
        return $path === '' ? $key : $path . '.' . $key;
    }
}
