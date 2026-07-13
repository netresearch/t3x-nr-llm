<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Preset;

/**
 * Result of checking whether a preset's criteria can be satisfied by the
 * currently configured, active models (ADR-056).
 *
 * When satisfiable, `matchedModelLabel` names the model the criteria would
 * resolve to right now (informational — criteria-mode configurations re-resolve
 * on every run). When not, `missingRequirement` names the first criterion that
 * eliminates every candidate, so the admin knows what to configure.
 */
final readonly class PresetPreflightResult
{
    private function __construct(
        public bool $satisfiable,
        public ?string $missingRequirement,
        public ?string $matchedModelLabel,
    ) {}

    public static function satisfiable(string $matchedModelLabel): self
    {
        return new self(true, null, $matchedModelLabel);
    }

    public static function unsatisfiable(string $missingRequirement): self
    {
        return new self(false, $missingRequirement, null);
    }
}
