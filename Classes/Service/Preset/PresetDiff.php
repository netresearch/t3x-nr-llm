<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Preset;

/**
 * The set of field-level deltas between a declared preset and the imported
 * record it manages (ADR-056 update flow).
 *
 * The diff is computed against the record's *current* values, so it names
 * exactly what an update would overwrite. Admin-owned fields (active state,
 * default flag, backend groups, fallback chain) are never part of it — an
 * update leaves them untouched. An empty diff on a drifted record is
 * possible (e.g. the declaration only removed an optional seed, which an
 * update does not reset): applying it just re-stamps the checksum and clears
 * the drift hint.
 */
final readonly class PresetDiff
{
    /**
     * @param list<PresetFieldDiff> $changes
     */
    public function __construct(
        public string $identifier,
        public string $name,
        public array $changes,
    ) {}

    public function hasChanges(): bool
    {
        return $this->changes !== [];
    }

    /**
     * The machine names of the changed fields, in diff order.
     *
     * @return list<string>
     */
    public function changedFields(): array
    {
        return array_map(static fn(PresetFieldDiff $change): string => $change->field, $this->changes);
    }
}
