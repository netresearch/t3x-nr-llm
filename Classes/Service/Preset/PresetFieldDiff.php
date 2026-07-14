<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Preset;

/**
 * A single field-level delta between a declared preset and the imported
 * record it manages (ADR-056 update flow).
 *
 * `field` is the machine name of the changed field (e.g. `temperature` or
 * `criteria.capabilities`); `current` and `declared` are the display strings
 * of the record's current value and the declaration's value, in the column
 * order the re-confirm modal renders (Field, Current, Declared).
 */
final readonly class PresetFieldDiff
{
    public function __construct(
        public string $field,
        public string $current,
        public string $declared,
    ) {}
}
