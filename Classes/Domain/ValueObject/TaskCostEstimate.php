<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * What one run of a task is expected to cost, before it starts (NEXT-170).
 *
 * The task-shaped sibling of {@see EditorActionCostEstimate}, built by the same
 * rules:
 *
 * - `$inputTokens` is {@see \Netresearch\NrLlm\Service\Context\TranscriptEstimator}
 *   over the configuration's system prompt and the task prompt built with the
 *   input the server knows. `$inputIncluded` says whether that is the task's
 *   input: false for a manual task, whose input is typed after the page loads.
 * - `$maxOutputTokens` is the configuration's `maxTokens`; null when it sets
 *   none, which means the answer length is unbounded, not zero.
 * - `$costLow` assumes no output, `$costHigh` assumes the full ceiling. Both are
 *   null unless the model has an input price AND an output price and the
 *   configuration a ceiling — a one-sided price would print a bound of 0.0000,
 *   which reads as free when it means unknown.
 *
 * Skills, prompt snippets and approved instruction sections the run adds are
 * not counted, so the real input is higher than `$inputTokens`.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class TaskCostEstimate
{
    public function __construct(
        public int $inputTokens,
        public ?int $maxOutputTokens,
        public bool $inputIncluded,
        public ?float $costLow = null,
        public ?float $costHigh = null,
    ) {}

    /**
     * No configuration resolves for the task, so the run has no model to price.
     */
    public static function withoutConfiguration(bool $inputIncluded): self
    {
        return new self(0, null, $inputIncluded);
    }

    /**
     * Fluid reads `{estimate.priced}`: a legitimate 0.0 bound is falsy there.
     */
    public function isPriced(): bool
    {
        return $this->costLow !== null && $this->costHigh !== null;
    }

    /**
     * The cost to pre-flight the budget with: the upper bound, or 0.0 when no
     * range exists — which is what the execution itself plans with.
     */
    public function plannedCost(): float
    {
        return $this->costHigh ?? 0.0;
    }
}
