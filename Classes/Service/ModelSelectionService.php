<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;

/**
 * Service for dynamic model selection based on criteria.
 *
 * Resolves the best matching model at runtime based on:
 * - Required capabilities (chat, vision, tools, etc.)
 * - Preferred adapter types (openai, anthropic, etc.)
 * - Minimum context length requirements
 * - Cost constraints and preferences
 */
class ModelSelectionService
{
    public function __construct(
        private readonly ModelRepository $modelRepository,
    ) {}

    /**
     * Resolve a model for the given configuration.
     *
     * If the configuration uses fixed mode, returns the configured model.
     * If using criteria mode, finds the best matching model based on criteria.
     */
    public function resolveModel(LlmConfiguration $configuration): ?Model
    {
        if (!$configuration->usesCriteriaSelection()) {
            // Fixed mode: return the directly configured model
            return $configuration->getLlmModel();
        }

        // Criteria mode: find best matching model
        $criteria = $configuration->getModelSelectionCriteriaArray();
        return $this->findMatchingModel($criteria);
    }

    /**
     * Find a model matching the given criteria.
     *
     * @param array{capabilities?: string[], adapterTypes?: string[], minContextLength?: int, maxCostInput?: int, preferLowestCost?: bool} $criteria
     */
    public function findMatchingModel(array $criteria): ?Model
    {
        $candidates = $this->findCandidates($criteria);

        if (empty($candidates)) {
            return null;
        }

        // Sort candidates by preference
        $preferLowestCost = $criteria['preferLowestCost'] ?? false;
        $sorted = $this->sortCandidates($candidates, $preferLowestCost);

        return $sorted[0] ?? null;
    }

    /**
     * Find all models matching the given criteria.
     *
     * @param array{capabilities?: string[], adapterTypes?: string[], minContextLength?: int, maxCostInput?: int, preferLowestCost?: bool} $criteria
     *
     * @return Model[]
     */
    public function findCandidates(array $criteria): array
    {
        $allModels = $this->modelRepository->findActive();
        $candidates = [];

        foreach ($allModels as $model) {
            // @phpstan-ignore instanceof.alwaysTrue (defensive type guard)
            if (!$model instanceof Model) {
                continue;
            }

            if ($this->modelMatchesCriteria($model, $criteria)) {
                $candidates[] = $model;
            }
        }

        return $candidates;
    }

    /**
     * Check if a model matches the given criteria.
     *
     * @param array{capabilities?: string[], adapterTypes?: string[], minContextLength?: int, maxCostInput?: int, preferLowestCost?: bool} $criteria
     */
    public function modelMatchesCriteria(Model $model, array $criteria): bool
    {
        // Check required capabilities
        if (!empty($criteria['capabilities'])) {
            foreach ($criteria['capabilities'] as $capability) {
                if (!$model->hasCapability($capability)) {
                    return false;
                }
            }
        }

        // Check adapter types
        if (!empty($criteria['adapterTypes'])) {
            $provider = $model->getProvider();
            if ($provider === null) {
                return false;
            }
            if (!in_array($provider->getAdapterType(), $criteria['adapterTypes'], true)) {
                return false;
            }
        }

        // Check minimum context length
        if (isset($criteria['minContextLength']) && $criteria['minContextLength'] > 0) {
            $contextLength = $model->getContextLength();
            // Skip models with unknown context length (0) when minimum is required
            if ($contextLength === 0 || $contextLength < $criteria['minContextLength']) {
                return false;
            }
        }

        // Check maximum input cost
        if (isset($criteria['maxCostInput']) && $criteria['maxCostInput'] > 0) {
            $costInput = $model->getCostInput();
            // Allow models with unknown cost (0)
            if ($costInput > 0 && $costInput > $criteria['maxCostInput']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Sort candidate models by preference.
     *
     * @param Model[] $candidates
     *
     * @return Model[]
     */
    private function sortCandidates(array $candidates, bool $preferLowestCost): array
    {
        usort($candidates, function (Model $a, Model $b) use ($preferLowestCost): int {
            // First priority: provider priority (higher is better)
            $providerA = $a->getProvider();
            $providerB = $b->getProvider();
            $priorityA = $providerA?->getPriority() ?? 0;
            $priorityB = $providerB?->getPriority() ?? 0;

            if ($priorityA !== $priorityB) {
                return $priorityB <=> $priorityA; // Higher priority first
            }

            // Second priority: cost preference
            if ($preferLowestCost) {
                $costA = $a->getCostInput() + $a->getCostOutput();
                $costB = $b->getCostInput() + $b->getCostOutput();
                // Treat 0 (unknown) as highest cost to deprioritize
                if ($costA === 0) {
                    $costA = PHP_INT_MAX;
                }
                if ($costB === 0) {
                    $costB = PHP_INT_MAX;
                }
                if ($costA !== $costB) {
                    return $costA <=> $costB; // Lower cost first
                }
            }

            // Third priority: default model
            if ($a->isDefault() !== $b->isDefault()) {
                return $a->isDefault() ? -1 : 1; // Default first
            }

            // Fourth priority: sorting order
            return $a->getSorting() <=> $b->getSorting();
        });

        return $candidates;
    }

    /**
     * Get available selection modes.
     *
     * @return array<string, string>
     */
    public static function getSelectionModes(): array
    {
        return [
            LlmConfiguration::SELECTION_MODE_FIXED => 'Fixed Model',
            LlmConfiguration::SELECTION_MODE_CRITERIA => 'Dynamic (Criteria)',
        ];
    }
}
