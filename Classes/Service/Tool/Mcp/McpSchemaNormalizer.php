<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp;

/**
 * Maps the `inputSchema` an MCP server advertises in its `tools/list` response
 * onto the parameter schema handed to :php:`ToolSpec` (ADR-116).
 *
 * The input arrives from a remote server over HTTP and is untrusted: it may be
 * malformed, may use JSON-Schema constructs no provider accepts, and may be
 * arbitrarily large or deep. Every rejection returns `null`; the caller drops
 * the tool. A schema is never partially repaired — a silently altered schema
 * makes the model call the tool with arguments the server then rejects, which
 * surfaces as an opaque tool failure mid-run rather than as a missing tool at
 * import time.
 *
 * Empty `properties` is NOT handled here: :php:`ToolSpec` already converts `[]`
 * to a `stdClass` so it serialises as `{}` instead of `[]`. Do not add that
 * conversion a second time.
 */
final readonly class McpSchemaNormalizer
{
    /**
     * Nesting levels the schema may occupy, counted in PHP array levels: the
     * root object is level 1, its `properties` map is level 2, each property
     * sub-schema is level 3, so one level of declared object nesting costs two.
     * Twelve therefore permits roughly five levels of nested objects — beyond
     * that a model no longer fills the arguments reliably, and the cap bounds
     * the recursive walk over a hostile schema.
     */
    public const MAX_DEPTH = 12;

    /**
     * Size of the JSON-encoded result. The schema is re-sent to the provider on
     * every request that offers the tool, so its size is a recurring per-request
     * token cost billed to the operator, not a one-off import cost. 16 KiB is
     * roughly 4k tokens for a single tool declaration.
     */
    public const MAX_ENCODED_BYTES = 16384;

    /**
     * The only top-level keys carried over. Known constraints outside this list
     * are refused by UNSUPPORTED_KEYWORDS or DROPPED_ROOT_CONSTRAINTS; annotations
     * such as title, $id and examples are dropped.
     *
     * The applicators are listed so a union declared at the top level survives
     * the filter; inside a property they ride along with the property schema,
     * which is copied whole.
     */
    private const RETAINED_KEYS = ['type', 'description', 'properties', 'required', 'additionalProperties', 'allOf', 'anyOf', 'oneOf', 'not'];

    /**
     * Keywords this import does not support. References can depend on definitions
     * or recursive anchors the root filter drops. Conditional, dependency and
     * unevaluated-property constraints are outside the supported import subset.
     * Refusing the whole tool preserves its contract instead of dropping a rule.
     *
     * A property literally named after one of these keywords is rejected along
     * with them: the recursive walk also visits property-name positions.
     */
    private const UNSUPPORTED_KEYWORDS = [
        '$ref',
        '$dynamicRef',
        '$recursiveRef',
        '$defs',
        'definitions',
        'if',
        'then',
        'else',
        'patternProperties',
        'propertyNames',
        'dependencies',
        'dependentSchemas',
        'dependentRequired',
        'unevaluatedProperties',
    ];

    /**
     * @return array<string, mixed>|null the provider-ready parameter schema, or
     *                                   null if it cannot be made safe
     */
    public function normalise(mixed $inputSchema): ?array
    {
        if (!is_array($inputSchema)) {
            return null;
        }

        // A union such as `['object', 'null']` is rejected: no provider agrees
        // on how to render it, and guessing one member alters the contract.
        if (($inputSchema['type'] ?? null) !== 'object') {
            return null;
        }

        if ($this->carriesUnsupportedKeyword($inputSchema) || $this->firstDroppedRootConstraint($inputSchema) !== null) {
            return null;
        }

        $normalised = [];
        foreach (self::RETAINED_KEYS as $key) {
            if (array_key_exists($key, $inputSchema)) {
                $normalised[$key] = $inputSchema[$key];
            }
        }

        if (!$this->retainedValuesAreWellFormed($normalised)) {
            return null;
        }

        // Depth and size are measured on what survives the filter, because that
        // is what is stored and sent — an oversized annotation block that was
        // dropped costs nothing.
        if (!$this->isWithinDepth($normalised, 1)) {
            return null;
        }

        // Non-UTF-8 bytes anywhere in the remote schema fail the encode; such a
        // schema cannot reach a provider intact either way.
        $encoded = json_encode($normalised);

        if ($encoded === false || strlen($encoded) > self::MAX_ENCODED_BYTES) {
            return null;
        }

        return $normalised;
    }

    /**
     * Why normalise() refused a schema, in words an operator can act on,
     * or null when it did not refuse.
     *
     * Root constraints and unsupported root keywords are checked on the original
     * input before filtering. Recursive keyword, depth and size checks then use
     * the retained schema; a discarded annotation does not contribute to them.
     */
    public function rejectionReason(mixed $inputSchema): ?string
    {
        if (!is_array($inputSchema)) {
            return 'the server advertised no parameter schema';
        }

        if (($inputSchema['type'] ?? null) !== 'object') {
            return 'its top-level type is not "object"';
        }

        if (($keyword = $this->firstDroppedRootConstraint($inputSchema)) !== null || $this->carriesUnsupportedKeyword($inputSchema)) {
            return $this->unsupportedKeywordReason($keyword ?? $this->firstUnsupportedKeyword($inputSchema) ?? '');
        }

        $normalised = [];
        foreach (self::RETAINED_KEYS as $key) {
            if (array_key_exists($key, $inputSchema)) {
                $normalised[$key] = $inputSchema[$key];
            }
        }

        if (!$this->retainedValuesAreWellFormed($normalised)) {
            return 'its properties or required list are not well formed';
        }

        if (!$this->isWithinDepth($normalised, 1)) {
            $keyword = $this->firstUnsupportedKeyword($normalised);

            return $keyword !== null ? $this->unsupportedKeywordReason($keyword) : sprintf('it nests deeper than %d levels', self::MAX_DEPTH);
        }

        $encoded = json_encode($normalised);
        if ($encoded === false) {
            return 'it contains bytes that are not valid UTF-8';
        }

        if (strlen($encoded) > self::MAX_ENCODED_BYTES) {
            return sprintf('it exceeds %d bytes once stored', self::MAX_ENCODED_BYTES);
        }

        return null;
    }

    private function unsupportedKeywordReason(string $keyword): string
    {
        return sprintf(
            'it uses "%s", which this import does not carry: dropping the keyword would widen what the tool ' . 'accepts, letting a model produce arguments the server then rejects',
            $keyword,
        );
    }

    /**
     * The keyword {@see self::isWithinDepth()} tripped over, at any level.
     *
     * @param array<array-key, mixed> $node
     */
    private function firstUnsupportedKeyword(array $node): ?string
    {
        foreach (self::UNSUPPORTED_KEYWORDS as $keyword) {
            if (array_key_exists($keyword, $node)) {
                return $keyword;
            }
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $nested = $this->firstUnsupportedKeyword($value);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $node
     */
    private function carriesUnsupportedKeyword(array $node): bool
    {
        foreach (self::UNSUPPORTED_KEYWORDS as $keyword) {
            if (array_key_exists($keyword, $node)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $node
     */
    private function isWithinDepth(array $node, int $depth): bool
    {
        if ($depth > self::MAX_DEPTH) {
            return false;
        }

        foreach ($node as $value) {
            if (!is_array($value)) {
                continue;
            }

            if ($this->carriesUnsupportedKeyword($value)) {
                return false;
            }

            if (!$this->isWithinDepth($value, $depth + 1)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A retained key with an unexpected value type is a malformed schema, not
     * something to coerce: the coercion is the silent alteration this class
     * exists to avoid.
     *
     * @param array<string, mixed> $normalised
     */
    private function retainedValuesAreWellFormed(array $normalised): bool
    {
        if (array_key_exists('description', $normalised) && !is_string($normalised['description'])) {
            return false;
        }

        if (array_key_exists('additionalProperties', $normalised) && !is_bool($normalised['additionalProperties'])) {
            return false;
        }

        if (array_key_exists('properties', $normalised)) {
            $properties = $normalised['properties'];

            if (!is_array($properties)) {
                return false;
            }

            foreach ($properties as $propertySchema) {
                if (!is_array($propertySchema)) {
                    return false;
                }
            }
        }

        if (array_key_exists('required', $normalised)) {
            $required = $normalised['required'];

            if (!is_array($required) || !array_is_list($required)) {
                return false;
            }

            foreach ($required as $name) {
                if (!is_string($name)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Constraints the top-level key filter would drop. Nested versions already
     * ride along with their containing property schema and remain intact.
     */
    private const DROPPED_ROOT_CONSTRAINTS = ['minProperties', 'maxProperties', 'enum', 'const'];

    /**
     * @param array<array-key, mixed> $schema
     */
    private function firstDroppedRootConstraint(array $schema): ?string
    {
        foreach (self::DROPPED_ROOT_CONSTRAINTS as $keyword) {
            if (array_key_exists($keyword, $schema)) {
                return $keyword;
            }
        }

        return null;
    }
}
