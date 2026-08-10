<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Routing;

use Netresearch\NrLlm\Domain\Enum\RoutingRejectionReason;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;

/**
 * The hard constraints of a routing decision, and the reason each one refuses
 * (ADR-142).
 *
 * Extracted from :php:`ModelSelectionService::modelMatchesCriteria()`, which
 * still answers the same question through this class — there is one
 * implementation of "may this model serve this call", not two. What is new is
 * that a refusal says WHY.
 *
 * A model that passed before passes now — the set of predicates is unchanged.
 * What is chosen deliberately is their ORDER, because the order decides which
 * reason a model failing several constraints reports.
 *
 * The operator's own criteria are evaluated first and the operation capability
 * (ADR-138) LAST. That is load-bearing, not cosmetic: a caller reads
 * `OPERATION_CAPABILITY_MISSING` as "this model would have served, but cannot
 * do this operation", and the reading is only true when every other constraint
 * already passed. Evaluated earlier, a model excluded by an adapter restriction
 * would report the operation reason too, and
 * {@see \Netresearch\NrLlm\Service\ModelSelectionService::resolveModel()} would
 * raise a misconfiguration error over a model the criteria never wanted.
 *
 * @internal
 */
final readonly class EligibilityEvaluator
{
    /**
     * Why this model may not serve this call, or null when it may.
     *
     * @param array{capabilities?: string[], operationCapability?: string, adapterTypes?: string[], minContextLength?: int, maxCostInput?: int, preferLowestCost?: bool} $criteria
     */
    public function evaluate(Model $model, array $criteria): ?RoutingRejectionReason
    {
        if (!$this->matchesCapabilities($model, $criteria)) {
            return RoutingRejectionReason::CAPABILITY_MISSING;
        }

        if (!$this->matchesAdapterTypes($model, $criteria)) {
            return RoutingRejectionReason::ADAPTER_NOT_ALLOWED;
        }

        if (!$this->matchesMinContextLength($model, $criteria)) {
            return RoutingRejectionReason::CONTEXT_TOO_SMALL;
        }

        if (!$this->matchesMaxCostInput($model, $criteria)) {
            return RoutingRejectionReason::COST_ABOVE_LIMIT;
        }

        // Last, for the reason the class docblock gives: this refusal means
        // "would have served, but not this operation", and that is only true
        // once every criterion above has passed.
        if (!$this->matchesOperationCapability($model, $criteria)) {
            return RoutingRejectionReason::OPERATION_CAPABILITY_MISSING;
        }

        return null;
    }

    /**
     * Whether the model satisfies every capability the operator required.
     *
     * Matched strictly: a model that declares nothing does not satisfy an
     * explicit requirement. Criteria tokens are routed through the typed
     * `CapabilitySet`, so they are trimmed before resolution and unknown tokens
     * in a persisted CSV are dropped at parse time rather than matched against
     * an equally-unknown criteria string.
     *
     * @param array{capabilities?: string[], operationCapability?: string, adapterTypes?: string[], minContextLength?: int, maxCostInput?: int, preferLowestCost?: bool} $criteria
     */
    private function matchesCapabilities(Model $model, array $criteria): bool
    {
        if (empty($criteria['capabilities'])) {
            return true;
        }

        $capabilities = $model->getCapabilitySet();
        foreach ($criteria['capabilities'] as $capability) {
            if (!$capabilities->has($capability)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the model can serve the operation the call is running (ADR-138).
     *
     * A SEPARATE axis from `capabilities`, deliberately, because the two answer
     * different questions and need different treatment of an undeclared model.
     * `capabilities` is what an operator asked for. `operationCapability` is
     * derived from the running call, and there an empty capability CSV means
     * "undeclared", not "cannot": the field is optional, plenty of installations
     * never filled it, and refusing every such model would break them for a fact
     * nobody ever stated.
     *
     * @param array{capabilities?: string[], operationCapability?: string, adapterTypes?: string[], minContextLength?: int, maxCostInput?: int, preferLowestCost?: bool} $criteria
     */
    private function matchesOperationCapability(Model $model, array $criteria): bool
    {
        $required = $criteria['operationCapability'] ?? null;
        if (!is_string($required) || $required === '') {
            return true;
        }

        $capabilities = $model->getCapabilitySet();
        if ($capabilities->isEmpty()) {
            return true;
        }

        return $capabilities->has($required);
    }

    /**
     * Whether the model's provider adapter type is among the allowed types.
     *
     * A model with no provider fails an explicit adapter restriction: the
     * restriction cannot be shown to be met.
     *
     * @param array{capabilities?: string[], operationCapability?: string, adapterTypes?: string[], minContextLength?: int, maxCostInput?: int, preferLowestCost?: bool} $criteria
     */
    private function matchesAdapterTypes(Model $model, array $criteria): bool
    {
        if (empty($criteria['adapterTypes'])) {
            return true;
        }

        $provider = $model->getProvider();
        if (!$provider instanceof Provider) {
            return false;
        }

        return in_array($provider->getAdapterType(), $criteria['adapterTypes'], true);
    }

    /**
     * Whether the model meets the minimum context length.
     *
     * An unknown context length (0) does NOT meet a stated minimum — the
     * requirement cannot be shown to be satisfied, so the model is refused
     * rather than gambled on.
     *
     * @param array{capabilities?: string[], operationCapability?: string, adapterTypes?: string[], minContextLength?: int, maxCostInput?: int, preferLowestCost?: bool} $criteria
     */
    private function matchesMinContextLength(Model $model, array $criteria): bool
    {
        if (!isset($criteria['minContextLength']) || $criteria['minContextLength'] <= 0) {
            return true;
        }

        $contextLength = $model->getContextLength();

        return $contextLength !== 0 && $contextLength >= $criteria['minContextLength'];
    }

    /**
     * Whether the model's input cost is within the allowed maximum.
     *
     * Unknown cost (0) is allowed, unlike unknown context length: an unpriced
     * model is usually a local one, and refusing it for a cost ceiling it may
     * well satisfy would be the wrong direction.
     *
     * @param array{capabilities?: string[], operationCapability?: string, adapterTypes?: string[], minContextLength?: int, maxCostInput?: int, preferLowestCost?: bool} $criteria
     */
    private function matchesMaxCostInput(Model $model, array $criteria): bool
    {
        if (!isset($criteria['maxCostInput']) || $criteria['maxCostInput'] <= 0) {
            return true;
        }

        $costInput = $model->getCostInput();

        return $costInput <= 0 || $costInput <= $criteria['maxCostInput'];
    }
}
