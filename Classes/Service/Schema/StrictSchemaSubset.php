<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Schema;

/**
 * The named JSON-Schema subset the strict validator enforces (ADR-126).
 *
 * One class owns both halves of the contract: which keywords exist, and what
 * shape each keyword's value must have. `supported()` walks a SCHEMA and
 * answers whether it lies entirely inside the subset — fail-closed on any
 * unknown keyword, so the subset is enforceable rather than aspirational.
 * The walker runs BEFORE the first provider call, because an out-of-subset
 * schema fails validation for every possible model response and must never
 * cost a paid request, let alone the repair round-trip (ADR-082).
 *
 * Deviations from JSON Schema 2020-12, each deliberate and documented in
 * ADR-126:
 *
 * - `pattern` is compiled as PCRE (delimiters added here), not ECMA-262.
 * - `multipleOf` is honoured for positive integers only; float steps would
 *   need epsilon arithmetic that quietly lies about conformance.
 * - `5.0` is NOT an integer: primitive matching mirrors the lenient
 *   validator's PHP-native checks, which is what keeps the invariant
 *   "strict accepts ⇒ lenient accepts" true.
 * - `{}` and `[]` decode identically in PHP; the ambiguity is accepted
 *   exactly as the lenient matcher documents.
 * - No `$ref`/`$defs`: reference resolution is the point at which a subset
 *   becomes a JSON-Schema implementation, and ADR-082's no-runtime-dependency
 *   decision stands.
 */
final readonly class StrictSchemaSubset
{
    /**
     * Keywords whose value shape `supported()` checks and the validator
     * enforces.
     *
     * @var list<string>
     */
    public const ASSERTIONS = [
        'type', 'enum', 'const', 'pattern',
        'minLength', 'maxLength',
        'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum',
        'multipleOf',
        'items', 'prefixItems', 'minItems', 'maxItems', 'uniqueItems',
        'properties', 'required', 'additionalProperties',
        'oneOf', 'anyOf', 'allOf', 'not',
    ];

    /**
     * Keywords accepted and ignored: annotations per JSON Schema 2020-12
     * (`format` is annotation-only by default there), plus the schema's own
     * meta declarations, which nearly every generated schema carries.
     *
     * @var list<string>
     */
    public const ANNOTATIONS = [
        'description', 'title', 'default', 'examples', 'format',
        '$schema', '$id',
    ];

    private const TYPE_NAMES = ['object', 'array', 'string', 'number', 'integer', 'boolean', 'null'];

    /**
     * Whether the schema lies entirely inside the subset.
     *
     * @param array<array-key, mixed> $schema
     */
    public function supported(array $schema): bool
    {
        // A degenerate schema is a programming error, not "accept anything"
        // (see InputSchema for the same reasoning on the tool-input path).
        if ($schema === []) {
            return false;
        }

        $hasAssertion = false;
        foreach ($schema as $keyword => $value) {
            if (!is_string($keyword)) {
                return false;
            }

            if (in_array($keyword, self::ANNOTATIONS, true)) {
                continue;
            }

            if (!in_array($keyword, self::ASSERTIONS, true)) {
                return false;
            }

            if (!$this->keywordValueSupported($keyword, $value)) {
                return false;
            }

            $hasAssertion = true;
        }

        // Annotations alone assert nothing: a schema of only description/
        // title/format would let strict validation accept every response,
        // which is the degenerate case in prettier clothes.
        return $hasAssertion;
    }

    /**
     * Compile a delimiterless JSON-Schema pattern into a PCRE the validator
     * can run, or null when it does not compile.
     *
     * The schema keyword carries an ECMA-262-style regex WITHOUT delimiters;
     * PCRE needs them, so they are added here with the delimiter escaped.
     * Compilability is checked with a temporary error handler rather than the
     * `@` operator — the idiom Assertion::isValidPattern() established.
     */
    public function compilePattern(string $pattern): ?string
    {
        // Bound what a hostile schema can make the engine chew on. Runtime
        // backtracking is already fail-closed (pcre.backtrack_limit makes
        // preg_match return false), so the cap only guards absurd inputs.
        if ($pattern === '' || strlen($pattern) > 1000) {
            return null;
        }

        $compiled = '~' . str_replace('~', '\\~', $pattern) . '~u';

        set_error_handler(static fn(): bool => true);

        try {
            return preg_match($compiled, '') !== false ? $compiled : null;
        } finally {
            restore_error_handler();
        }
    }

    private function keywordValueSupported(string $keyword, mixed $value): bool
    {
        return match ($keyword) {
            'type' => $this->typeValueSupported($value),
            'enum' => is_array($value) && $value !== [] && array_is_list($value),
            'const' => true,
            'pattern' => is_string($value) && $this->compilePattern($value) !== null,
            'minLength', 'maxLength', 'minItems', 'maxItems' => is_int($value) && $value >= 0,
            'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum' => is_int($value) || is_float($value),
            // Positive integers only — float steps would need epsilon
            // arithmetic; zero would divide by zero. Anything else is
            // out-of-subset, not "ignored".
            'multipleOf' => is_int($value) && $value > 0,
            'uniqueItems' => is_bool($value),
            'required' => $this->stringListSupported($value),
            'items', 'not' => is_array($value) && $this->supported($value),
            'prefixItems', 'oneOf', 'anyOf', 'allOf' => $this->schemaListSupported($value),
            'properties' => $this->propertyMapSupported($value),
            'additionalProperties' => is_bool($value) || (is_array($value) && $this->supported($value)),
            default => false,
        };
    }

    private function typeValueSupported(mixed $value): bool
    {
        if (is_string($value)) {
            return in_array($value, self::TYPE_NAMES, true);
        }

        if (!is_array($value) || $value === [] || !array_is_list($value)) {
            return false;
        }

        foreach ($value as $type) {
            if (!is_string($type) || !in_array($type, self::TYPE_NAMES, true)) {
                return false;
            }
        }

        return true;
    }

    private function stringListSupported(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }

        foreach ($value as $entry) {
            if (!is_string($entry)) {
                return false;
            }
        }

        return true;
    }

    private function schemaListSupported(mixed $value): bool
    {
        if (!is_array($value) || $value === [] || !array_is_list($value)) {
            return false;
        }

        foreach ($value as $subSchema) {
            if (!is_array($subSchema) || !$this->supported($subSchema)) {
                return false;
            }
        }

        return true;
    }

    private function propertyMapSupported(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $name => $subSchema) {
            if (!is_string($name) || !is_array($subSchema) || !$this->supported($subSchema)) {
                return false;
            }
        }

        return true;
    }
}
