<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\RoutingPolicyMode;
use Netresearch\NrLlm\Domain\Model\Model;

/**
 * The outcome of one automatic model selection, with its reasoning (ADR-142).
 *
 * Produced for criteria-mode configurations only. Fixed mode chooses nothing —
 * the operator named the model — so there is no decision to explain and none is
 * manufactured.
 *
 * @internal
 */
final readonly class RoutingDecision
{
    /**
     * @param list<RoutingCandidate> $candidates every model considered, eligible and rejected alike,
     *                                           eligible ones in the order they were ranked
     */
    public function __construct(
        public ?Model $selected,
        public array $candidates,
        public RoutingPolicyMode $mode,
    ) {}

    /**
     * A decision over an empty field: the criteria matched no active model at
     * all. Distinct from a decision whose candidates were all rejected, which
     * carries the reasons.
     */
    public static function noCandidates(RoutingPolicyMode $mode): self
    {
        return new self(null, [], $mode);
    }

    /**
     * @return list<RoutingCandidate>
     */
    public function eligibleCandidates(): array
    {
        return array_values(array_filter($this->candidates, static fn(RoutingCandidate $c): bool => $c->eligible));
    }

    /**
     * @return list<RoutingCandidate>
     */
    public function rejectedCandidates(): array
    {
        return array_values(array_filter($this->candidates, static fn(RoutingCandidate $c): bool => !$c->eligible));
    }

    /**
     * Whether any model was eligible. False with a non-empty candidate list
     * means every candidate was rejected by a hard constraint — the case worth
     * telling an operator about, because it is a configuration problem rather
     * than an empty catalogue.
     */
    public function hasSelection(): bool
    {
        return $this->selected instanceof Model;
    }
}
