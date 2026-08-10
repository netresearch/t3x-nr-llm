<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\RoutingRejectionReason;
use Netresearch\NrLlm\Domain\Model\Model;

/**
 * One model considered for a routing decision, and what became of it (ADR-142).
 *
 * A candidate is either eligible with a score, or rejected with a reason —
 * never both. The two states are what keeps hard constraints and ranking apart:
 * a rejected candidate carries no score at all, so there is no number for it to
 * win with.
 *
 * @internal
 */
final readonly class RoutingCandidate
{
    /**
     * @param array<string, float|null> $signals the ranking inputs, per signal name; null where a
     *                                           signal had no data for this model, which is NOT the
     *                                           same as a measured zero
     */
    private function __construct(
        public Model $model,
        public bool $eligible,
        public ?RoutingRejectionReason $rejectionReason,
        public ?float $score,
        public array $signals,
    ) {}

    /**
     * @param array<string, float|null> $signals
     */
    public static function eligible(Model $model, float $score, array $signals = []): self
    {
        return new self($model, true, null, $score, $signals);
    }

    public static function rejected(Model $model, RoutingRejectionReason $reason): self
    {
        return new self($model, false, $reason, null, []);
    }

    /**
     * The model's identifier as it appears in provider payloads and telemetry.
     */
    public function modelId(): string
    {
        return $this->model->getModelId();
    }

    public function providerIdentifier(): string
    {
        return $this->model->getProvider()?->getIdentifier() ?? '';
    }
}
