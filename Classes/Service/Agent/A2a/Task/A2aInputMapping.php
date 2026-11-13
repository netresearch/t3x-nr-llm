<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Task;

/**
 * How a client's text answers an input pause (spec 051 A2A-MT1, A2A-MT2).
 *
 * An A2A client answers INPUT_REQUIRED with a message; this stage accepts
 * `text/plain` parts only. An input tool declares a JSON schema. The text is
 * the value of the schema's one string property when the schema asks for
 * exactly one required string (as `ask_choice` does), or a JSON object when
 * it is one. Anything else cannot be told apart from a guess and is not
 * mapped; the caller refuses the message. The values are validated against
 * the schema afterwards like every submission (ADR-105).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class A2aInputMapping
{
    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>|null the submission, or null when the text cannot be mapped
     */
    public static function submission(string $text, array $schema): ?array
    {
        $property = self::singleStringProperty($schema);
        if ($property !== null) {
            return [$property => trim($text)];
        }

        $decoded = json_decode(trim($text), true);
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return null;
        }

        $data = [];
        foreach ($decoded as $key => $value) {
            $data[(string) $key] = $value;
        }

        return $data;
    }

    /**
     * The answers the schema allows, when its one string property is an enum;
     * empty otherwise.
     *
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    public static function allowedAnswers(array $schema): array
    {
        $property = self::singleStringProperty($schema);
        $spec     = $property !== null && is_array($schema['properties'] ?? null) ? ($schema['properties'][$property] ?? null) : null;
        $enum     = is_array($spec) ? ($spec['enum'] ?? null) : null;

        return is_array($enum) ? array_values(array_filter($enum, is_string(...))) : [];
    }

    /**
     * The title of the schema's one string property, which `ask_choice` sets
     * to its question; empty when there is none.
     *
     * @param array<string, mixed> $schema
     */
    public static function title(array $schema): string
    {
        $property = self::singleStringProperty($schema);
        $spec     = $property !== null && is_array($schema['properties'] ?? null) ? ($schema['properties'][$property] ?? null) : null;

        return is_array($spec) && is_string($spec['title'] ?? null) ? trim($spec['title']) : '';
    }

    /**
     * What a client needs to answer a schema it cannot answer with plain
     * text: the fields of the JSON object, the required ones marked. Empty for
     * a schema the plain text answers.
     *
     * @param array<string, mixed> $schema
     */
    public static function fieldHint(array $schema): string
    {
        $properties = $schema['properties'] ?? null;
        if (self::singleStringProperty($schema) !== null || !is_array($properties) || $properties === []) {
            return '';
        }

        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $fields   = [];
        foreach ($properties as $name => $spec) {
            $type     = is_array($spec) && is_string($spec['type'] ?? null) ? $spec['type'] : 'value';
            $fields[] = $name . ' (' . $type . (in_array($name, $required, true) ? ', required' : '') . ')';
        }

        return 'Answer with a JSON object with the fields: ' . implode(', ', $fields);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function singleStringProperty(array $schema): ?string
    {
        $properties = $schema['properties'] ?? null;
        $required   = $schema['required'] ?? null;
        if (($schema['type'] ?? null) !== 'object' || !is_array($properties) || !is_array($required) || count($required) !== 1 || count($properties) !== 1) {
            return null;
        }

        $name = $required[0] ?? null;
        $spec = is_string($name) ? ($properties[$name] ?? null) : null;

        return is_string($name) && is_array($spec) && ($spec['type'] ?? null) === 'string' ? $name : null;
    }
}
