<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;

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
     * If using criteria mode, finds the best matching model based on criteria.
     */
    public function resolveModel(LlmConfiguration $configuration): ?Model;

    /**
     * Find a model matching the given criteria.
     *
     * @param array{capabilities?: string[], adapterTypes?: string[], minContextLength?: int, maxCostInput?: int, preferLowestCost?: bool} $criteria
     */
    public function findMatchingModel(array $criteria): ?Model;

    /**
     * Find all models matching the given criteria.
     *
     * @param array{capabilities?: string[], adapterTypes?: string[], minContextLength?: int, maxCostInput?: int, preferLowestCost?: bool} $criteria
     *
     * @return Model[]
     */
    public function findCandidates(array $criteria): array;

    /**
     * Check if a model matches the given criteria.
     *
     * @param array{capabilities?: string[], adapterTypes?: string[], minContextLength?: int, maxCostInput?: int, preferLowestCost?: bool} $criteria
     */
    public function modelMatchesCriteria(Model $model, array $criteria): bool;

    /**
     * Get available selection modes.
     *
     * @return array<string, string>
     */
    public static function getSelectionModes(): array;
}
