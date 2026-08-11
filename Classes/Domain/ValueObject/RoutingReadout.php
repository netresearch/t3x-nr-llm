<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Domain\Enum\RoutingPolicyMode;
use Netresearch\NrLlm\Domain\Model\Model;

/**
 * What a configuration would resolve to, and why (ADR-148).
 *
 * Two states, and keeping them apart is the point of the type:
 *
 * 1. **Fixed mode chooses nothing.** The operator named the model, so there is
 *    no decision, no policy mode, no ranking and no rejected candidate. The
 *    readout says exactly that and manufactures none of it. Presenting a fixed
 *    configuration as a decision would invent reasoning the runtime never did.
 * 2. **Criteria mode carries the real {@see RoutingDecision}** — the one
 *    {@see \Netresearch\NrLlm\Service\Routing\RoutingDecisionService} produced,
 *    not a re-derivation of it.
 *
 * Every field that describes a decision is therefore null in fixed mode. A
 * `false` there would read as a statement about a switch that was never
 * consulted.
 *
 * @internal
 */
final readonly class RoutingReadout
{
    /**
     * @param Model|null           $namedModel                   fixed mode only: the model the operator named,
     *                                                           null when the configuration names none
     * @param RoutingDecision|null $decision                     criteria mode only
     * @param ModelCapability|null $requiredCapability           criteria mode only: what the operation the readout
     *                                                           was asked about needs a model to declare, null when
     *                                                           it constrains nothing (ADR-138)
     * @param bool|null            $operationCapabilityEnforcing criteria mode only: whether that requirement was
     *                                                           applied to the decision or merely observed
     * @param bool|null            $modeOverridden               criteria mode only: whether the decision was taken
     *                                                           under a mode the caller asked for instead of the
     *                                                           configured one
     */
    private function __construct(
        public bool $fixed,
        public ?Model $namedModel,
        public ?RoutingDecision $decision,
        public ?ModelCapability $requiredCapability,
        public ?bool $operationCapabilityEnforcing,
        public ?bool $modeOverridden,
    ) {}

    public static function fixed(?Model $namedModel): self
    {
        return new self(true, $namedModel, null, null, null, null);
    }

    public static function decided(
        RoutingDecision $decision,
        ?ModelCapability $requiredCapability,
        bool $operationCapabilityEnforcing,
        bool $modeOverridden,
    ): self {
        return new self(false, null, $decision, $requiredCapability, $operationCapabilityEnforcing, $modeOverridden);
    }

    /**
     * The mode the decision was taken under, or null in fixed mode where none
     * was consulted.
     *
     * Named `get…` so Fluid can reach it — see
     * {@see RoutingPolicyMode::getLabelKey()} for the convention.
     */
    public function getMode(): ?RoutingPolicyMode
    {
        return $this->decision?->mode;
    }

    /**
     * Whether a model came out of this — either the one the operator named or
     * the one the decision selected.
     *
     * Fluid reaches this as `{readout.selection}`: the get/is/has convention
     * strips the prefix, so `{readout.hasSelection}` looks for
     * `getHasSelection()` and silently yields null. Same shape as
     * {@see \Netresearch\NrLlm\Service\Governance\EffectivePolicyRow::isKnown()},
     * which the Governance template reads as `{row.known}`.
     */
    public function hasSelection(): bool
    {
        return $this->fixed
            ? $this->namedModel instanceof Model
            : ($this->decision?->hasSelection() ?? false);
    }

    /**
     * No model was even considered: the catalogue holds no active model.
     *
     * The case worth separating from "every candidate was rejected", because
     * the two need opposite fixes — one is an empty catalogue, the other a
     * criteria/model mismatch that the rejection reasons name.
     *
     * Fluid reaches this as `{readout.emptyCatalogue}` — see
     * {@see self::hasSelection()} for the convention.
     */
    public function isEmptyCatalogue(): bool
    {
        return $this->decision instanceof RoutingDecision && $this->decision->candidates === [];
    }
}
