<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\InjectionSeverity;

/**
 * The outcome of scanning a skill body for prompt-injection patterns (ADR-061).
 *
 * Carries every {@see InjectionFinding} plus the derived decision signals the
 * ingest path consumes: {@see hasHighConfidence()} force-disables a skill at
 * import (fail-closed), while any non-empty result flags the record for review.
 */
final readonly class InjectionScanResult
{
    /**
     * @param list<InjectionFinding> $findings
     */
    public function __construct(
        public array $findings = [],
    ) {}

    public function isClean(): bool
    {
        return $this->findings === [];
    }

    public function highestSeverity(): ?InjectionSeverity
    {
        $highest = null;
        foreach ($this->findings as $finding) {
            if ($highest === null || $finding->severity->rank() > $highest->rank()) {
                $highest = $finding->severity;
            }
        }

        return $highest;
    }

    /**
     * At least one HIGH-confidence finding — the fail-closed force-disable gate.
     */
    public function hasHighConfidence(): bool
    {
        return $this->highestSeverity() === InjectionSeverity::HIGH;
    }

    /**
     * Serialise findings to a JSON-encodable array for storage / the audit trail.
     *
     * @return list<array{label: string, severity: string, excerpt: string}>
     */
    public function toArray(): array
    {
        return array_map(static fn(InjectionFinding $f): array => $f->toArray(), $this->findings);
    }
}
