<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Schema;

/**
 * Lightweight structural JSON-Schema matcher (ADR-082, ADR-126).
 *
 * Validates a decoded value against a subset schema — the top-level ``type``,
 * an object's ``required`` keys, and recursive ``properties`` types. Extra keys
 * are allowed; this is deliberately **not** a full JSON Schema draft validator,
 * so there is no runtime dependency.
 *
 * The logic was extracted verbatim from `DeterministicGrader` (ADR-060) so the
 * evaluation grader and the structured-completion path share one matcher instead
 * of duplicating it.
 *
 * Two modes since ADR-126, and the boundary between them is load-bearing:
 *
 * - `validate()` is the LENIENT mode above, byte-identical to its pre-126
 *   behaviour. The ADR-105 tool-input gate, the resume paths and the
 *   evaluation grader all sit on it; changing its semantics moves a security
 *   boundary, so the strict mode is a separate method rather than a flag.
 * - `validateStrict()` enforces the named subset in {@see StrictSchemaSubset}:
 *   every subset keyword is honoured, any unknown keyword or degenerate
 *   schema FAILS. Callers should pre-flight the schema with
 *   {@see self::supportsSchema()} before spending money on a provider call —
 *   an out-of-subset schema fails for every possible response.
 *
 * The invariant tying the modes together, pinned by a fuzzy test: anything
 * strict accepts, lenient accepts. Strict only ever rejects MORE, which is
 * why its primitive type matcher byte-mirrors the lenient one (`5.0` is not
 * an integer here, unlike in the spec).
 */
final readonly class JsonSchemaValidator
{
    public function __construct(
        // A promoted-parameter default so the six constructor-default wirings
        // across the codebase keep working without a Services.yaml entry.
        private StrictSchemaSubset $subset = new StrictSchemaSubset(),
    ) {}

    /**
     * Validate a decoded value against a subset JSON Schema.
     *
     * @param array<array-key, mixed> $schema
     */
    public function validate(mixed $data, array $schema): bool
    {
        $type = $schema['type'] ?? null;
        if (is_string($type) && !$this->matchesType($data, $type)) {
            return false;
        }

        if (isset($schema['required']) && is_array($schema['required'])) {
            // An empty JSON object decodes to []; treat it as an object here
            // (consistent with matchesType()) so an empty object is not
            // mistaken for a list and the required-key checks still run.
            if (!is_array($data) || ($data !== [] && array_is_list($data))) {
                return false;
            }

            foreach ($schema['required'] as $key) {
                if (!is_string($key) || !array_key_exists($key, $data)) {
                    return false;
                }
            }
        }

        if (isset($schema['properties']) && is_array($schema['properties']) && is_array($data)) {
            foreach ($schema['properties'] as $key => $propSchema) {
                if (is_array($propSchema) && array_key_exists($key, $data)
                    && !$this->validate($data[$key], $propSchema)
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Validate a JSON response string against a JSON-encoded subset schema.
     * Returns false when either side is not valid JSON.
     */
    public function validateJson(string $json, string $schemaJson): bool
    {
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        $schema = json_decode($schemaJson, true);
        if (!is_array($schema)) {
            return false;
        }

        return $this->validate($data, $schema);
    }

    /**
     * Whether a schema lies entirely inside the strict subset (ADR-126).
     *
     * Callers on a paid path MUST pre-flight with this before the first
     * provider call: an out-of-subset schema fails strict validation for
     * every possible response, and discovering that after the repair
     * round-trip costs two requests.
     *
     * @param array<array-key, mixed> $schema
     */
    public function supportsSchema(array $schema): bool
    {
        return $this->subset->supported($schema);
    }

    /**
     * Validate a decoded value against the strict subset (ADR-126).
     *
     * Fail-closed twice over: a schema outside the subset returns false, and
     * every subset keyword present is enforced. Assertions apply only to
     * instances of their type, per JSON Schema — a `pattern` does not reject
     * an integer, a `minimum` does not reject a string.
     *
     * @param array<array-key, mixed> $schema
     */
    public function validateStrict(mixed $data, array $schema): bool
    {
        if (!$this->subset->supported($schema)) {
            return false;
        }

        return $this->strictNode($data, $schema);
    }

    /**
     * Strict counterpart of {@see self::validateJson()}.
     */
    public function validateJsonStrict(string $json, string $schemaJson): bool
    {
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        $schema = json_decode($schemaJson, true);
        if (!is_array($schema)) {
            return false;
        }

        return $this->validateStrict($data, $schema);
    }

    /**
     * @param array<array-key, mixed> $schema already subset-checked
     */
    private function strictNode(mixed $data, array $schema): bool
    {
        if (isset($schema['type']) && !$this->strictMatchesTypeValue($data, $schema['type'])) {
            return false;
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && !$this->inEnum($data, $schema['enum'])) {
            return false;
        }

        if (array_key_exists('const', $schema) && !$this->jsonEquals($data, $schema['const'])) {
            return false;
        }

        if (!$this->strictStringAssertions($data, $schema)) {
            return false;
        }

        if (!$this->strictNumberAssertions($data, $schema)) {
            return false;
        }

        if (!$this->strictArrayAssertions($data, $schema)) {
            return false;
        }

        if (!$this->strictObjectAssertions($data, $schema)) {
            return false;
        }

        return $this->strictApplicators($data, $schema);
    }

    /**
     * @param array<array-key, mixed> $schema
     */
    private function strictStringAssertions(mixed $data, array $schema): bool
    {
        if (!is_string($data)) {
            return true;
        }

        // Code points, not bytes — the spec counts characters, and a schema
        // written for "max 10 characters" must not reject 4 umlauts.
        if (isset($schema['minLength']) && is_int($schema['minLength']) && mb_strlen($data) < $schema['minLength']) {
            return false;
        }

        if (isset($schema['maxLength']) && is_int($schema['maxLength']) && mb_strlen($data) > $schema['maxLength']) {
            return false;
        }

        if (isset($schema['pattern']) && is_string($schema['pattern'])) {
            $compiled = $this->subset->compilePattern($schema['pattern']);
            // A pattern that stopped compiling (or hits the backtrack limit at
            // match time, where preg_match returns false) rejects: fail-closed.
            if ($compiled === null || preg_match($compiled, $data) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<array-key, mixed> $schema
     */
    private function strictNumberAssertions(mixed $data, array $schema): bool
    {
        if (!is_int($data) && !is_float($data)) {
            return true;
        }

        $min = $schema['minimum'] ?? null;
        if ((is_int($min) || is_float($min)) && $data < $min) {
            return false;
        }

        $max = $schema['maximum'] ?? null;
        if ((is_int($max) || is_float($max)) && $data > $max) {
            return false;
        }

        $exMin = $schema['exclusiveMinimum'] ?? null;
        if ((is_int($exMin) || is_float($exMin)) && $data <= $exMin) {
            return false;
        }

        $exMax = $schema['exclusiveMaximum'] ?? null;
        if ((is_int($exMax) || is_float($exMax)) && $data >= $exMax) {
            return false;
        }

        $multiple = $schema['multipleOf'] ?? null;
        if (is_int($multiple) && $multiple > 0) {
            $isMultiple = is_int($data) ? $data % $multiple === 0 : fmod($data, (float)$multiple) === 0.0;
            if (!$isMultiple) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<array-key, mixed> $schema
     */
    private function strictArrayAssertions(mixed $data, array $schema): bool
    {
        // Mirrors the lenient matcher: [] counts as object AND array; a
        // non-empty map is not a list.
        if (!is_array($data) || !array_is_list($data)) {
            return true;
        }

        $count = count($data);
        if (isset($schema['minItems']) && is_int($schema['minItems']) && $count < $schema['minItems']) {
            return false;
        }

        if (isset($schema['maxItems']) && is_int($schema['maxItems']) && $count > $schema['maxItems']) {
            return false;
        }

        if (($schema['uniqueItems'] ?? false) === true) {
            $seen = [];
            foreach ($data as $item) {
                $normalised = $this->normaliseJson($item);
                if (in_array($normalised, $seen, true)) {
                    return false;
                }

                $seen[] = $normalised;
            }
        }

        $prefix = $schema['prefixItems'] ?? null;
        $prefixCount = 0;
        if (is_array($prefix)) {
            $prefixCount = count($prefix);
            foreach ($prefix as $i => $itemSchema) {
                if (is_int($i) && is_array($itemSchema) && array_key_exists($i, $data)
                    && !$this->strictNode($data[$i], $itemSchema)
                ) {
                    return false;
                }
            }
        }

        // In 2020-12, `items` constrains the elements AFTER prefixItems.
        $items = $schema['items'] ?? null;
        if (is_array($items)) {
            foreach ($data as $i => $item) {
                if ($i >= $prefixCount && !$this->strictNode($item, $items)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param array<array-key, mixed> $schema
     */
    private function strictObjectAssertions(mixed $data, array $schema): bool
    {
        $isObject = is_array($data) && (!array_is_list($data) || $data === []);

        if (isset($schema['required']) && is_array($schema['required'])) {
            if (!$isObject) {
                return false;
            }

            foreach ($schema['required'] as $key) {
                if (!is_string($key) || !array_key_exists($key, $data)) {
                    return false;
                }
            }
        }

        if (!$isObject || !is_array($data)) {
            return true;
        }

        $properties = $schema['properties'] ?? null;
        $declared = [];
        if (is_array($properties)) {
            foreach ($properties as $key => $propSchema) {
                $declared[$key] = true;
                if (is_array($propSchema) && array_key_exists($key, $data)
                    && !$this->strictNode($data[$key], $propSchema)
                ) {
                    return false;
                }
            }
        }

        $additional = $schema['additionalProperties'] ?? null;
        if ($additional === false) {
            foreach (array_keys($data) as $key) {
                if (!isset($declared[$key])) {
                    return false;
                }
            }
        } elseif (is_array($additional)) {
            foreach ($data as $key => $value) {
                if (!isset($declared[$key]) && !$this->strictNode($value, $additional)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param array<array-key, mixed> $schema
     */
    private function strictApplicators(mixed $data, array $schema): bool
    {
        $allOf = $schema['allOf'] ?? null;
        if (is_array($allOf)) {
            foreach ($allOf as $branch) {
                if (!is_array($branch) || !$this->strictNode($data, $branch)) {
                    return false;
                }
            }
        }

        $anyOf = $schema['anyOf'] ?? null;
        if (is_array($anyOf)) {
            $matched = false;
            foreach ($anyOf as $branch) {
                if (is_array($branch) && $this->strictNode($data, $branch)) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                return false;
            }
        }

        $oneOf = $schema['oneOf'] ?? null;
        if (is_array($oneOf)) {
            $matches = 0;
            foreach ($oneOf as $branch) {
                if (is_array($branch) && $this->strictNode($data, $branch)) {
                    ++$matches;
                    if ($matches > 1) {
                        return false;
                    }
                }
            }

            if ($matches !== 1) {
                return false;
            }
        }

        $not = $schema['not'] ?? null;
        return !is_array($not) || !$this->strictNode($data, $not);
    }

    /**
     * Strict primitive matching — a BYTE MIRROR of the lenient
     * {@see self::matchesType()} for the shared type names, minus its
     * fail-open default arm. That mirroring is what makes the invariant
     * "strict accepts implies lenient accepts" hold: notably `5.0` is NOT an
     * integer here, although the spec says it is (ADR-126 names the
     * deviation). Do not "fix" either matcher toward the spec alone.
     */
    private function strictMatchesTypeValue(mixed $data, mixed $type): bool
    {
        if (is_string($type)) {
            return $this->strictMatchesType($data, $type);
        }

        if (is_array($type)) {
            foreach ($type as $candidate) {
                if (is_string($candidate) && $this->strictMatchesType($data, $candidate)) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    private function strictMatchesType(mixed $data, string $type): bool
    {
        return match ($type) {
            'object' => is_array($data) && (!array_is_list($data) || $data === []),
            'array' => is_array($data) && array_is_list($data),
            'string' => is_string($data),
            'number' => is_int($data) || is_float($data),
            'integer' => is_int($data),
            'boolean' => is_bool($data),
            'null' => $data === null,
            default => false,
        };
    }

    /**
     * @param array<array-key, mixed> $enum
     */
    private function inEnum(mixed $data, array $enum): bool
    {
        foreach ($enum as $member) {
            if ($this->jsonEquals($data, $member)) {
                return true;
            }
        }

        return false;
    }

    /**
     * JSON value equality on decoded data: maps compare key-order-insensitive,
     * lists order-sensitive, scalars with === (so 1 and "1" differ, and — a
     * named ADR-126 deviation — 1 and 1.0 differ although JSON has one number
     * type).
     */
    private function jsonEquals(mixed $a, mixed $b): bool
    {
        return $this->normaliseJson($a) === $this->normaliseJson($b);
    }

    private function normaliseJson(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $normalised = [];
        foreach ($value as $key => $item) {
            $normalised[$key] = $this->normaliseJson($item);
        }

        if (!array_is_list($normalised)) {
            ksort($normalised);
        }

        return $normalised;
    }

    private function matchesType(mixed $data, string $type): bool
    {
        // LENIENT ONLY. The `default => true` arm is load-bearing: unknown
        // type names pass here, and the ADR-105 input gate depends on that
        // staying true. Strict mode has its own matcher above — never point
        // strict at this one, never remove the default arm.
        return match ($type) {
            // An empty JSON object and array both decode to []; ambiguity is
            // accepted for this lightweight matcher.
            'object' => is_array($data) && (!array_is_list($data) || $data === []),
            'array' => is_array($data) && array_is_list($data),
            'string' => is_string($data),
            'number' => is_int($data) || is_float($data),
            'integer' => is_int($data),
            'boolean' => is_bool($data),
            'null' => $data === null,
            default => true,
        };
    }
}
