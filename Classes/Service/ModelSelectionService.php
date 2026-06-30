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
final readonly class ModelSelectionService implements ModelSelectionServiceInterface
{
    public function __construct(
        private ModelRepository $modelRepository,
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
        return $this->matchesCapabilities($model, $criteria)
            && $this->matchesAdapterTypes($model, $criteria)
            && $this->matchesMinContextLength($model, $criteria)
            && $this->matchesMaxCostInput($model, $criteria);
    }

    /**
     * Check whether the model satisfies all required capabilities.
     *
     * @param array{capabilities?: string[], adapterTypes?: string[], minContextLength?: int, maxCostInput?: int, preferLowestCost?: bool} $criteria
     */
    private function matchesCapabilities(Model $model, array $criteria): bool
    {
        // Check required capabilities. The criteria's `capabilities` array
        // is a `string[]` from external input (configuration / wizard form),
        // so we route through the typed `CapabilitySet`. Behaviour is
        // unchanged for every previously-valid criteria token (legacy
        // `hasCapability()` already used strict `in_array(...,true)` over
        // `explode(',')`); the migration's real value is twofold —
        // criteria tokens are trimmed before `ModelCapability::tryFrom()`
        // (so `' chat'` resolves the same as `'chat'`), and unknown
        // tokens that may exist in the persisted CSV (schema drift,
        // removed-but-still-stored capabilities) are dropped at parse
        // time rather than matched against an equally-unknown criteria
        // string (REC #6 slice 16b).
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
     * Check whether the model's provider adapter type is among the allowed types.
     *
     * @param array{capabilities?: string[], adapterTypes?: string[], minContextLength?: int, maxCostInput?: int, preferLowestCost?: bool} $criteria
     */
    private function matchesAdapterTypes(Model $model, array $criteria): bool
    {
        if (empty($criteria['adapterTypes'])) {
            return true;
        }

        $provider = $model->getProvider();
        if ($provider === null) {
            return false;
        }

        return in_array($provider->getAdapterType(), $criteria['adapterTypes'], true);
    }

    /**
     * Check whether the model meets the minimum context length requirement.
     *
     * @param array{capabilities?: string[], adapterTypes?: string[], minContextLength?: int, maxCostInput?: int, preferLowestCost?: bool} $criteria
     */
    private function matchesMinContextLength(Model $model, array $criteria): bool
    {
        if (!isset($criteria['minContextLength']) || $criteria['minContextLength'] <= 0) {
            return true;
        }

        $contextLength = $model->getContextLength();

        // Skip models with unknown context length (0) when minimum is required
        return $contextLength !== 0 && $contextLength >= $criteria['minContextLength'];
    }

    /**
     * Check whether the model's input cost is within the allowed maximum.
     *
     * @param array{capabilities?: string[], adapterTypes?: string[], minContextLength?: int, maxCostInput?: int, preferLowestCost?: bool} $criteria
     */
    private function matchesMaxCostInput(Model $model, array $criteria): bool
    {
        if (!isset($criteria['maxCostInput']) || $criteria['maxCostInput'] <= 0) {
            return true;
        }

        $costInput = $model->getCostInput();

        // Allow models with unknown cost (0)
        return $costInput <= 0 || $costInput <= $criteria['maxCostInput'];
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
        usort(
            $candidates,
            fn(Model $a, Model $b): int => $this->compareCandidates($a, $b, $preferLowestCost),
        );

        return $candidates;
    }

    /**
     * Compare two candidate models according to the selection preferences.
     */
    private function compareCandidates(Model $a, Model $b, bool $preferLowestCost): int
    {
        // First priority: provider priority (higher is better)
        $priorityA = $a->getProvider()?->getPriority() ?? 0;
        $priorityB = $b->getProvider()?->getPriority() ?? 0;
        $byPriority = $priorityB <=> $priorityA; // Higher priority first
        if ($byPriority !== 0) {
            return $byPriority;
        }

        // Second priority: cost preference
        if ($preferLowestCost) {
            $byCost = $this->compareByCost($a, $b);
            if ($byCost !== 0) {
                return $byCost;
            }
        }

        // Third priority: default model, then sorting order
        return $this->compareByDefaultThenSorting($a, $b);
    }

    /**
     * Compare two models by combined input/output cost (lower cost first).
     *
     * Unknown cost (0) is treated as the highest cost to deprioritize it.
     */
    private function compareByCost(Model $a, Model $b): int
    {
        $costA = $a->getCostInput() + $a->getCostOutput();
        $costB = $b->getCostInput() + $b->getCostOutput();
        // Treat 0 (unknown) as highest cost to deprioritize
        if ($costA === 0) {
            $costA = PHP_INT_MAX;
        }
        if ($costB === 0) {
            $costB = PHP_INT_MAX;
        }

        return $costA <=> $costB; // Lower cost first
    }

    /**
     * Compare two models by default flag (default first), then by sorting order.
     */
    private function compareByDefaultThenSorting(Model $a, Model $b): int
    {
        // Third priority: default model
        if ($a->isDefault() !== $b->isDefault()) {
            return $a->isDefault() ? -1 : 1; // Default first
        }

        // Fourth priority: sorting order — handled at the query level.
        // ModelRepository hydrates candidates already ordered by `sorting, name`
        // (its $defaultOrderings), and usort() is stable, so equal-priority
        // candidates keep that order without an explicit tiebreaker here. (A
        // getSorting() comparison would be a no-op anyway: `sorting` is a TCA
        // ctrl.sortby field that Extbase does not hydrate onto the model.)
        return 0;
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
