<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Netresearch\NrLlm\Domain\Enum\RoutingPolicyMode;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\ValueObject\ModelResolution;
use Netresearch\NrLlm\Domain\ValueObject\RoutingReadout;
use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;

/**
 * Public surface of the dynamic model-selection service.
 *
 * Consumers (controllers, feature services, tests) should depend on this
 * interface rather than the concrete `ModelSelectionService` so the
 * implementation can be substituted without inheritance.
 */
interface ModelSelectionServiceInterface
{
    /**
     * Resolve a model for the given configuration.
     *
     * If the configuration uses fixed mode, returns the configured model.
     * If using criteria mode, finds the best matching model based on criteria,
     * additionally constrained by the capability `$operation` requires
     * (ADR-138).
     *
     * `$operation` has no default on purpose. Every criteria-mode resolution
     * that belongs to a concrete call must say which call it is, and the one
     * caller that genuinely has no operation — a bare "give me the adapter" —
     * has to say `null` out loud rather than inherit a silent skip.
     *
     * @throws UnsupportedFeatureException when enforcement is on and the
     *                                     criteria match only models that
     *                                     declare they cannot serve `$operation`
     */
    public function resolveModel(LlmConfiguration $configuration, ?ProviderOperation $operation): ?Model;

    /**
     * The same resolution, handing back the decision that produced it
     * (ADR-156).
     *
     * `resolveModel()` is this method with the reasoning dropped. A caller that
     * needs both — {@see \Netresearch\NrLlm\Service\ConfigurationCallPlanner},
     * which resolves the model and records why into the telemetry scratchpad —
     * takes this one and pays for a single evaluation.
     *
     * It is NOT `explainRouting()`. That answers an operator about a
     * hypothetical, accepts a policy-mode override, and is free to run whenever
     * the Governance tab is opened. This answers the runtime about the call it
     * is making, takes no override, and runs on every criteria-mode request —
     * so it must not cost a second discovery/eligibility/ranking pass.
     *
     * {@see \Netresearch\NrLlm\Domain\ValueObject\ModelResolution::$routingSummary}
     * is null where nothing was chosen: fixed mode names its own model.
     *
     * @throws UnsupportedFeatureException as {@see self::resolveModel()}
     */
    public function resolveModelForCall(LlmConfiguration $configuration, ?ProviderOperation $operation): ModelResolution;

    /**
     * The same resolution, with its reasoning attached (ADR-148).
     *
     * `resolveModel()` answers a caller: it returns the model and throws when
     * there is none to return. This answers an OPERATOR asking "why this model
     * and not that one", so it returns the decision instead — the selected
     * model, every candidate that was ranked, and every candidate that was
     * refused with the reason. It resolves nothing a second time: the readout
     * is the same {@see \Netresearch\NrLlm\Service\Routing\RoutingDecisionService}
     * call the runtime makes.
     *
     * On the interface because a backend controller has to reach it;
     * `decide()` deliberately stays off, because it takes a raw criteria array
     * and knows nothing about the fixed-vs-criteria branch — a controller
     * calling it would be choosing which half of the rule to apply.
     *
     * A fixed-mode configuration yields a readout that reports NO decision: the
     * operator named the model, nothing was chosen, and there is nothing to
     * explain.
     *
     * `$policyMode` asks what a different mode would choose. It is evaluated
     * for this call only and never written back to the install setting; null
     * answers for the mode the runtime is configured with.
     */
    public function explainRouting(
        LlmConfiguration $configuration,
        ?ProviderOperation $operation,
        ?RoutingPolicyMode $policyMode,
    ): RoutingReadout;

    /**
     * Find a model matching the given criteria.
     *
     * @param array{capabilities?: string[], operationCapability?: string, adapterTypes?: string[], minContextLength?: int, maxCostInput?: int, preferLowestCost?: bool} $criteria
     */
    public function findMatchingModel(array $criteria): ?Model;

    /**
     * Find all models matching the given criteria.
     *
     * @param array{capabilities?: string[], operationCapability?: string, adapterTypes?: string[], minContextLength?: int, maxCostInput?: int, preferLowestCost?: bool} $criteria
     *
     * @return Model[]
     */
    public function findCandidates(array $criteria): array;

    /**
     * Check if a model matches the given criteria.
     *
     * @param array{capabilities?: string[], operationCapability?: string, adapterTypes?: string[], minContextLength?: int, maxCostInput?: int, preferLowestCost?: bool} $criteria
     */
    public function modelMatchesCriteria(Model $model, array $criteria): bool;

    // `getSelectionModes()` is a stateless lookup of the available
    // mode constants and is intentionally NOT part of this interface
    // — interfaces meant for DI substitution should expose only
    // methods that benefit from polymorphism. It remains `public
    // static` on `ModelSelectionService`; callers reach it via
    // `ModelSelectionService::getSelectionModes()`.
}
