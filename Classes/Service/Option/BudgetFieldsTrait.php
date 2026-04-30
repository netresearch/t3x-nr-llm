<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Option;

use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * Common implementation of the `BudgetAwareOptionsInterface` surface.
 *
 * Every option type that reaches `BudgetMiddleware` (`ChatOptions`,
 * `EmbeddingOptions`, `VisionOptions`, `TranslationOptions`) consumes
 * this trait so the budget pre-flight fields, getters, fluent setters,
 * and validation rules live in one place. Centralising the validation
 * matters for security: if a future option type forgets the negative-
 * value rejection it would silently let `plannedCost = -1.0` credit
 * the per-day ceiling in `BudgetMiddleware`.
 *
 * Consumers must:
 * - Receive `?int $beUserUid = null, ?float $plannedCost = null` in
 *   their constructor and assign them via the trait's `setBudgetFields()`
 *   so validation runs at construction time. The fields are NOT
 *   declared via constructor promotion in consuming classes; the trait
 *   declares them as private properties so the inheritance and clone
 *   semantics work correctly.
 * - Call `validateBudgetFields()` from their own `validate()` method.
 * - NOT include these fields in `toArray()` — they are pipeline
 *   metadata, not provider wire payload, and must never reach the
 *   provider.
 */
trait BudgetFieldsTrait
{
    private ?int $beUserUid = null;

    private ?float $plannedCost = null;

    public function getBeUserUid(): ?int
    {
        return $this->beUserUid;
    }

    public function getPlannedCost(): ?float
    {
        return $this->plannedCost;
    }

    public function withBeUserUid(int $beUserUid): static
    {
        $clone = clone $this;
        $clone->beUserUid = $beUserUid;
        $clone->validate();
        return $clone;
    }

    public function withPlannedCost(float $plannedCost): static
    {
        $clone = clone $this;
        $clone->plannedCost = $plannedCost;
        $clone->validate();
        return $clone;
    }

    /**
     * Assign the budget fields. Called from the consuming class's
     * constructor so the field assignment lives next to the typed
     * promoted-parameter assignments instead of a separate later step.
     */
    private function setBudgetFields(?int $beUserUid, ?float $plannedCost): void
    {
        $this->beUserUid = $beUserUid;
        $this->plannedCost = $plannedCost;
    }

    /**
     * Reject negative `beUserUid` and `plannedCost` consistently
     * across every consuming option type. Called from each consumer's
     * `validate()` method so the trait does not need to override the
     * full validate flow.
     *
     * @throws InvalidArgumentException
     */
    private function validateBudgetFields(): void
    {
        if ($this->beUserUid !== null && $this->beUserUid < 0) {
            throw new InvalidArgumentException(
                sprintf('be_user_uid must be >= 0, got %d', $this->beUserUid),
                7461293505,
            );
        }

        if ($this->plannedCost !== null && $this->plannedCost < 0.0) {
            throw new InvalidArgumentException(
                sprintf('planned_cost must be >= 0.0, got %s', $this->plannedCost),
                4658297018,
            );
        }
    }
}
