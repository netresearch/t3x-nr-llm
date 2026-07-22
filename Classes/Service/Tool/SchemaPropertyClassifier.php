<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

/**
 * The SINGLE mapping from a JSON-Schema property's declared `type` to a backend
 * form control kind, used by BOTH {@see \Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunViewFactory}
 * (which widget to render) AND {@see SchemaInputCoercer} (how to cast the posted
 * string). Sharing one classifier prevents a rendered-widget vs coercion drift
 * that would otherwise 422 a valid submission (e.g. one side treating a field as
 * a boolean checkbox, the other as free text).
 *
 * Deliberately minimal (ADR-109): the approvals-inbox form renders flat scalar
 * fields only. A nested `object`/`array` property is `UNSUPPORTED` — no shipping
 * input tool declares one, so a JSON widget would be speculative.
 */
final readonly class SchemaPropertyClassifier
{
    public const TEXT = 'text';

    public const NUMBER = 'number';

    public const INTEGER = 'integer';

    public const CHECKBOX = 'checkbox';

    public const UNSUPPORTED = 'unsupported';

    /**
     * @param array<string, mixed> $propSchema one entry of a schema's `properties`
     *
     * @return string one of this class's control constants
     */
    public function classify(array $propSchema): string
    {
        return match ($this->scalarType($propSchema['type'] ?? null)) {
            'integer'          => self::INTEGER,
            'number'           => self::NUMBER,
            'boolean'          => self::CHECKBOX,
            'object', 'array'  => self::UNSUPPORTED,
            // 'string' and any unknown/absent type render as a text field and
            // coerce as a string — the safe pass-through (the server validator
            // stays authoritative).
            default            => self::TEXT,
        };
    }

    /**
     * JSON-Schema permits a `type` array (e.g. `["string", "null"]`); reduce it
     * to the first non-null scalar type name. A non-string, non-array value
     * yields '' (→ the TEXT default).
     */
    private function scalarType(mixed $type): string
    {
        if (is_string($type)) {
            return $type;
        }

        if (is_array($type)) {
            foreach ($type as $candidate) {
                if (is_string($candidate) && $candidate !== 'null') {
                    return $candidate;
                }
            }
        }

        return '';
    }
}
