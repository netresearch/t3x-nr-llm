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
 * on every run).
 *
 * When not, `missingRequirement` names the first criterion that eliminates
 * every candidate, and `remedy` names what to do about it — the two answer
 * different questions, and the second is the one an operator acts on.
 * `remedySubject` carries the concrete names the remedy talks about: the
 * models to switch on, or the providers a model would be added to.
 */
final readonly class PresetPreflightResult
{
    private function __construct(
        public bool $satisfiable,
        public ?string $missingRequirement,
        public ?string $matchedModelLabel,
        public ?PresetRemedy $remedy = null,
        public ?string $remedySubject = null,
    ) {}

    public static function satisfiable(string $matchedModelLabel): self
    {
        return new self(true, null, $matchedModelLabel);
    }

    public static function unsatisfiable(
        string $missingRequirement,
        ?PresetRemedy $remedy = null,
        ?string $remedySubject = null,
    ): self {
        return new self(false, $missingRequirement, null, $remedy, $remedySubject);
    }
}
