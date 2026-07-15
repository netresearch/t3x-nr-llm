<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

use Netresearch\NrLlm\Domain\Enum\AssertionType;
use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * A single deterministic expectation on a model response (ADR-060).
 *
 * The `value` is interpreted according to `type`: a literal string for
 * EXACT / CONTAINS, a PCRE pattern for REGEX, or a JSON structural schema
 * for JSON_SCHEMA. A REGEX value must be a compilable pattern; an empty
 * value is rejected for every type because it can never express a
 * meaningful assertion.
 */
final readonly class Assertion
{
    public function __construct(
        public AssertionType $type,
        public string $value,
    ) {
        if ($value === '') {
            throw new InvalidArgumentException(
                sprintf('Assertion of type "%s" must declare a non-empty value.', $type->value),
                1794000001,
            );
        }
        if ($type === AssertionType::REGEX && !self::isValidPattern($value)) {
            throw new InvalidArgumentException(
                sprintf('Assertion regex "%s" is not a valid PCRE pattern.', $value),
                1794000002,
            );
        }
    }

    /**
     * Whether the string is a compilable PCRE pattern, checked without the
     * `@` suppression operator (a temporary error handler swallows the
     * warning an invalid pattern would emit).
     */
    private static function isValidPattern(string $pattern): bool
    {
        set_error_handler(static fn(): bool => true);
        try {
            return preg_match($pattern, '') !== false;
        } finally {
            restore_error_handler();
        }
    }

    public static function exact(string $value): self
    {
        return new self(AssertionType::EXACT, $value);
    }

    public static function contains(string $value): self
    {
        return new self(AssertionType::CONTAINS, $value);
    }

    public static function regex(string $pattern): self
    {
        return new self(AssertionType::REGEX, $pattern);
    }

    public static function jsonSchema(string $schemaJson): self
    {
        return new self(AssertionType::JSON_SCHEMA, $schemaJson);
    }
}
