<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Schema;

/**
 * Lightweight structural JSON-Schema matcher (ADR-082).
 *
 * Validates a decoded value against a subset schema — the top-level ``type``,
 * an object's ``required`` keys, and recursive ``properties`` types. Extra keys
 * are allowed; this is deliberately **not** a full JSON Schema draft validator,
 * so there is no runtime dependency.
 *
 * The logic was extracted verbatim from `DeterministicGrader` (ADR-060) so the
 * evaluation grader and the structured-completion path share one matcher instead
 * of duplicating it.
 */
final readonly class JsonSchemaValidator
{
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

    private function matchesType(mixed $data, string $type): bool
    {
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
