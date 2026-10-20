<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

/**
 * The single authority on whether a tool's declared input schema (ADR-105) is
 * usable — one predicate shared by the tool loop's capture-time gate and the
 * AgentRuntime's rehydrate gate, so the two can never drift.
 *
 * A degenerate schema (empty, or with no declared shape) is a programming error,
 * NOT "accept anything": validating a submission against `[]` would return true
 * and let unvalidated user input flow into a tool. Both gates reject a
 * non-usable schema fail-closed (capture -> LogicException; rehydrate ->
 * CorruptSuspendedStateException).
 */
final class InputSchema
{
    /**
     * A schema is usable only if it declares a real shape: a `type`, or at least
     * one `properties` entry.
     *
     * @param array<string, mixed> $schema
     */
    public static function isUsable(array $schema): bool
    {
        if ($schema === []) {
            return false;
        }

        if (isset($schema['type'])) {
            return true;
        }

        return isset($schema['properties'])
            && is_array($schema['properties'])
            && $schema['properties'] !== [];
    }

    /**
     * Whether every submitted top-level property that declares an `enum` holds
     * one of its members (ADR-214 item 9, the choice builtin).
     *
     * The validator the input path uses checks `type`, `required` and
     * `properties` only and ignores `enum`, which is right for structured
     * output, where it is used too. An answer to a choice is the one input
     * whose whole point is the enumeration, so the input path checks it here,
     * beside that validation, at submission and again at resume. Members are
     * compared strictly — the person picks a value, and a string never
     * matches a number — except that two numbers compare by value.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $schema
     */
    public static function enumsHold(array $data, array $schema): bool
    {
        $properties = $schema['properties'] ?? null;
        if (!is_array($properties)) {
            return true;
        }

        foreach ($properties as $name => $property) {
            if (!is_array($property) || !isset($property['enum']) || !array_key_exists($name, $data)) {
                continue;
            }

            if (!is_array($property['enum']) || !self::isMember($data[$name], $property['enum'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Strict membership, except that numbers compare by value: a form field
     * coerced to `1.0` holds the member `1`.
     *
     * @param array<array-key, mixed> $enum
     */
    private static function isMember(mixed $value, array $enum): bool
    {
        foreach ($enum as $member) {
            if ($value === $member) {
                return true;
            }

            if ((is_int($value) || is_float($value)) && (is_int($member) || is_float($member)) && $value == $member) {
                return true;
            }
        }

        return false;
    }
}
