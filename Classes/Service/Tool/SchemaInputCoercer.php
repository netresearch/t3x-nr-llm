<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

/**
 * Casts a backend form's all-strings POST into the types a tool's input schema
 * declares, so the progressive-enhancement (JavaScript-off) path validates
 * (ADR-109).
 *
 * Why this exists: a native `<form>` posts every field as a string, but
 * {@see \Netresearch\NrLlm\Service\Schema\JsonSchemaValidator} is strict
 * (`is_int`/`is_float`/`is_bool`) and {@see \Netresearch\NrLlm\Service\Agent\AgentRuntime}::submitInput()
 * validates the submission VERBATIM. Without coercion every integer / number /
 * boolean field would 422, breaking the no-JS form at its core.
 *
 * Contract:
 * - It ONLY makes strings type-correct and drops empty OPTIONAL fields; it never
 *   sanitises content and is NOT a security boundary (the module admin gate is).
 * - The subset validator accepts an absent optional key and rejects a
 *   wrong-typed present key, so a blank optional integer/boolean is OMITTED (not
 *   passed as `''`, which would 422 the whole submission), while a non-numeric
 *   string for an integer is left UNCOERCED so the validator rejects it with a
 *   clear per-field error rather than silently becoming `(int) "abc" === 0`.
 * - The server validator stays authoritative; coercion runs BEFORE the
 *   {@see \Netresearch\NrLlm\Service\Agent\InputSubmission} is built.
 */
final readonly class SchemaInputCoercer
{
    public function __construct(
        private SchemaPropertyClassifier $classifier,
    ) {}

    /**
     * @param array<string, mixed> $rawInput    the form's `input[...]` array (all strings)
     * @param array<string, mixed> $inputSchema the tool's declared JSON-Schema subset
     *
     * @return array<string, mixed> the type-coerced data for InputSubmission
     */
    public function coerce(array $rawInput, array $inputSchema): array
    {
        $properties = $inputSchema['properties'] ?? null;
        if (!is_array($properties)) {
            return [];
        }

        $required = $this->requiredNames($inputSchema);
        $out      = [];

        foreach ($properties as $name => $propSchema) {
            $name = (string)$name;
            $type = SchemaPropertyClassifier::TEXT;
            if (is_array($propSchema)) {
                /** @var array<string, mixed> $propSchema a JSON-Schema property object is keyed by string */
                $type = $this->classifier->classify($propSchema);
            }
            $isRequired = in_array($name, $required, true);
            $posted     = $rawInput[$name] ?? null;

            if ($type === SchemaPropertyClassifier::CHECKBOX) {
                // Checkbox semantics: a checked box posts a value, an unchecked
                // one posts nothing. A REQUIRED boolean absent is a valid false
                // (never an error); an OPTIONAL boolean absent is omitted so a
                // tool's schema default is not overridden.
                if ($this->isChecked($posted)) {
                    $out[$name] = true;
                } elseif ($isRequired) {
                    $out[$name] = false;
                }

                continue;
            }

            if ($type === SchemaPropertyClassifier::UNSUPPORTED) {
                // No widget produced a value for a nested object/array field.
                continue;
            }

            // text / integer / number: an empty value is OMITTED. Optional →
            // the validator accepts the absence; required → the required-key
            // check fails with a clear per-field 422, not a silent wrong type.
            if ($posted === null || $posted === '') {
                continue;
            }

            $out[$name] = match ($type) {
                SchemaPropertyClassifier::INTEGER => is_numeric($posted) ? (int)$posted : $posted,
                SchemaPropertyClassifier::NUMBER  => is_numeric($posted) ? (float)$posted : $posted,
                default                           => $posted,
            };
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $inputSchema
     *
     * @return list<string>
     */
    private function requiredNames(array $inputSchema): array
    {
        $required = $inputSchema['required'] ?? null;
        if (!is_array($required)) {
            return [];
        }

        return array_values(array_filter($required, is_string(...)));
    }

    private function isChecked(mixed $posted): bool
    {
        return $posted === true
            || $posted === 1
            || (is_string($posted) && in_array(strtolower($posted), ['1', 'on', 'true', 'yes'], true));
    }
}
