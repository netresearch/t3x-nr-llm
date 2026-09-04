<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Model\Model;

/**
 * The model a call resolved to, and the decision that produced it (ADR-156).
 *
 * It exists so the decision reaches telemetry from the ONE evaluation the
 * runtime already performs. The alternative — resolving, then calling
 * `explainRouting()` to learn why — would run the candidate discovery, the
 * eligibility pass and the ranking a second time on every request, and two runs
 * can disagree the moment a model is toggled between them. One evaluation, two
 * consumers.
 *
 * {@see $routingSummary} is null whenever no automatic selection happened:
 * fixed mode (the operator named the model, so there is nothing to explain) and
 * the paths that resolve no configuration at all.
 *
 * Public since #922, because a caller that must size a payload against the
 * serving model has to take this call's decision itself and hand it over --
 * `LlmServiceManagerInterface::chatForConfiguration()` accepts one so the
 * dispatch does not take a second. Both members were already reachable from
 * the frozen surface ({@see \Netresearch\NrLlm\Domain\Model\Model} and
 * {@see RoutingSummary} are both `@api`), so this publishes the pairing rather
 * than any new data.
 *
 * @api
 */
final readonly class ModelResolution
{
    public function __construct(
        public ?Model $model,
        public ?RoutingSummary $routingSummary,
    ) {}

    /**
     * A resolution nothing was chosen in: fixed mode, or no model at all.
     */
    public static function withoutDecision(?Model $model): self
    {
        return new self($model, null);
    }
}
