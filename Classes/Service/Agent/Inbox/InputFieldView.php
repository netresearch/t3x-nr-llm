<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Inbox;

/**
 * One accessible form field derived from a tool's input JSON-Schema property
 * (ADR-109). All Fluid form logic lives in the factory that builds these, so
 * the template stays logic-free.
 *
 * `$options` are the members of the property's `enum`, which the input path
 * enforces on submission (ADR-214 item 9); a field with options is a
 * {@see self::CONTROL_SELECT}. `$description` is a UX aid only.
 */
final readonly class InputFieldView
{
    /** A field whose schema enumerates its values, rendered as a select of them. */
    public const CONTROL_SELECT = 'select';

    /**
     * @param string       $controlType {@see self::CONTROL_SELECT} for an enumerated field, otherwise one of {@see \Netresearch\NrLlm\Service\Tool\SchemaPropertyClassifier}'s constants: 'text'|'number'|'integer'|'checkbox'|'unsupported'
     * @param string       $htmlType    the `<input type>` for a textual control ('text' or 'number') — precomputed so the template stays logic-free
     * @param string       $step        the numeric `step` ('' when not applicable)
     * @param string       $inputMode   the `inputmode` hint ('' when not applicable)
     * @param list<string> $options     the enum's members, enforced on submission
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $controlType,
        public bool $required,
        public string $htmlType = 'text',
        public string $step = '',
        public string $inputMode = '',
        public array $options = [],
        public ?string $description = null,
    ) {}
}
