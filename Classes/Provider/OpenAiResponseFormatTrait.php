<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider;

/**
 * Builds the `response_format` payload field for the OpenAI-compatible
 * chat-completions dialect (OpenAI, Groq, Mistral, OpenRouter) — ADR-128.
 *
 * Three rungs, most enforcing first:
 *
 * 1. `response_schema` present AND the schema qualifies for OpenAI's strict
 *    mode → `{type: json_schema, json_schema: {strict: true, …}}`. The API
 *    then guarantees schema-conformant output.
 * 2. `response_schema` present but not strict-qualified → `{type:
 *    json_object}`. Strict mode rejects the request (HTTP 400) for schemas
 *    outside its rules, which would turn a valid ADR-126 schema into a
 *    provider error; JSON mode plus the prompt instruction and the local
 *    strict validation is the correct degradation.
 * 3. No schema, `response_format: 'json'` → `{type: json_object}`.
 *    `completeJson()` strictly `json_decode`s the reply, so the request MUST
 *    carry JSON mode — otherwise the model is free to wrap the JSON in
 *    prose/Markdown fences and the decode throws. JSON mode requires the
 *    word "json" somewhere in the messages, which the callers guarantee.
 *
 * `text` and `markdown` map to plain text: field unset.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
trait OpenAiResponseFormatTrait
{
    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>|null
     */
    private function buildResponseFormat(array $options): ?array
    {
        $schema = $options['response_schema'] ?? null;
        if (is_array($schema) && $schema !== []) {
            if ($this->qualifiesForStrictMode($schema)) {
                return [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'structured_output',
                        'strict' => true,
                        'schema' => $schema,
                    ],
                ];
            }

            return ['type' => 'json_object'];
        }

        return ($options['response_format'] ?? null) === 'json'
            ? ['type' => 'json_object']
            : null;
    }

    /**
     * Whether the schema satisfies OpenAI's strict-mode rules: root type
     * `object`, every object node with `additionalProperties: false` and
     * every property required, and only keywords from a conservative
     * allowlist. Deliberately narrower than the ADR-126 subset — a schema
     * strict mode would reject must fall back to JSON mode, never surface
     * as a provider 400. Widening the allowlist is additive.
     *
     * @param array<array-key, mixed> $schema
     */
    private function qualifiesForStrictMode(array $schema): bool
    {
        return ($schema['type'] ?? null) === 'object' && $this->strictNodeQualifies($schema);
    }

    /**
     * @param array<array-key, mixed> $node
     */
    private function strictNodeQualifies(array $node): bool
    {
        foreach (array_keys($node) as $keyword) {
            if (!is_string($keyword)
                || !in_array($keyword, ['type', 'properties', 'required', 'additionalProperties', 'items', 'enum', 'description', 'title'], true)
            ) {
                return false;
            }
        }

        $type = $node['type'] ?? null;
        if (!is_string($type)) {
            // Union types (`['string', 'null']`) are out of the conservative
            // profile even though strict mode could express some of them.
            return false;
        }

        if ($type === 'object') {
            if (($node['additionalProperties'] ?? null) !== false) {
                return false;
            }

            $properties = $node['properties'] ?? [];
            $required   = $node['required'] ?? [];
            // Non-empty required too: an empty `properties` map would encode
            // as JSON `[]` instead of `{}` (the PHP empty-array ambiguity)
            // and strict mode rejects objects without properties anyway.
            if (!is_array($properties) || $properties === [] || !is_array($required)) {
                return false;
            }

            $propertyNames = array_map(strval(...), array_keys($properties));
            sort($propertyNames);
            $requiredNames = array_values(array_filter($required, is_string(...)));
            sort($requiredNames);
            if ($propertyNames !== $requiredNames) {
                return false;
            }

            foreach ($properties as $subSchema) {
                if (!is_array($subSchema) || !$this->strictNodeQualifies($subSchema)) {
                    return false;
                }
            }
        }

        if ($type === 'array') {
            $items = $node['items'] ?? null;
            if (!is_array($items) || !$this->strictNodeQualifies($items)) {
                return false;
            }
        }

        return true;
    }
}
