<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

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
     * The only top-level keys carried over. Everything else is either an
     * annotation a provider ignores (`title`, `$id`, `examples`, vendor
     * extensions) or a constraint handled by self::UNSUPPORTED_KEYWORDS.
     *
     * The applicators are listed so a union declared at the TOP level survives
     * the filter; inside a property they ride along with the property schema,
     * which is copied whole.
     */
    private const RETAINED_KEYS = [
        'type',
        'description',
        'properties',
        'required',
        'additionalProperties',
        'allOf',
        'anyOf',
        'oneOf',
        'not',
    ];

    /**
     * Keywords this import refuses to carry, because carrying them would be a
     * lie and dropping them would widen what the tool accepts.
     *
     * The distinction that decides membership is whether the keyword is
     * SELF-CONTAINED. A union (`anyOf`) carries its alternatives inline, so
     * handing it to the provider verbatim preserves exactly what the server
     * said — those keywords are carried (see self::RETAINED_KEYS). A reference
     * points into a definition block this filter does not carry, so it would
     * arrive dangling; and the draft-2019/2020 applicators below have no
     * dependable support across the providers this extension talks to, so
     * carrying them trades an import-time refusal for a call-time failure with
     * a worse error message.
     *
     * A property literally named after one of these keywords is rejected along
     * with them: the walk does not distinguish a schema position from a
     * property-name position. That costs an unusable tool, never a wrong one.
     */
    private const UNSUPPORTED_KEYWORDS = [
        '$ref',
        '$dynamicRef',
        '$defs',
        'definitions',
        'if',
        'then',
        'else',
        'patternProperties',
        'propertyNames',
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

        if ($this->carriesUnsupportedKeyword($inputSchema)) {
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
     * Why {@see self::normalise()} refused a schema, in words an operator can
     * act on — or null when it did not refuse.
     *
     * The rejections are deliberate, but "no usable parameter schema" tells the
     * person reading the import report nothing: they cannot see whether the
     * server sent something malformed, something too large, or something well
     * formed that this import does not carry (a `$ref`, a union). Naming the
     * reason is the difference between a dead end and a decision.
     *
     * The checks below mirror {@see self::normalise()} in the same order and on
     * the same data — in particular the keyword walk runs over the FILTERED
     * schema, so a keyword sitting in a key that is dropped anyway is not
     * reported as the reason it was refused.
     */
    public function rejectionReason(mixed $inputSchema): ?string
    {
        if (!is_array($inputSchema)) {
            return 'the server advertised no parameter schema';
        }

        if (($inputSchema['type'] ?? null) !== 'object') {
            return 'its top-level type is not "object"';
        }

        if ($this->carriesUnsupportedKeyword($inputSchema)) {
            return $this->unsupportedKeywordReason($this->firstUnsupportedKeyword($inputSchema) ?? '');
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

            return $keyword !== null
                ? $this->unsupportedKeywordReason($keyword)
                : sprintf('it nests deeper than %d levels', self::MAX_DEPTH);
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
            'it uses "%s", which this import does not carry: dropping the keyword would widen what the tool '
            . 'accepts, letting a model produce arguments the server then rejects',
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
}
