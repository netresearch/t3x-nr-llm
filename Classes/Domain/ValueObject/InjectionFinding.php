<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\InjectionSeverity;

/**
 * A single prompt-injection scanner hit on a skill body (ADR-061).
 *
 * `label` names the detected pattern class (e.g. `instruction-override`),
 * `severity` its confidence tier, and `excerpt` a short, sanitised slice of
 * the offending text kept for the audit trail and the review UI — never the
 * whole body.
 */
final readonly class InjectionFinding
{
    public function __construct(
        public string $label,
        public InjectionSeverity $severity,
        public string $excerpt,
    ) {}

    /**
     * @return array{label: string, severity: string, excerpt: string}
     */
    public function toArray(): array
    {
        return [
            'label'    => $this->label,
            'severity' => $this->severity->value,
            'excerpt'  => $this->excerpt,
        ];
    }
}
