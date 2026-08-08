<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
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
