<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Service for dynamic model selection based on criteria.
 *
 * Resolves the best matching model at runtime based on:
 * - Required capabilities (chat, vision, tools, etc.)
 * - Preferred adapter types (openai, anthropic, etc.)
 * - Minimum context length requirements
 * - Cost constraints and preferences
 * - The capability the running operation itself requires (ADR-138)
 */
final readonly class ModelSelectionService implements ModelSelectionServiceInterface
{
    private const OBSERVE = 'observe';

    public function __construct(
        private ModelRepository $modelRepository,
        private ?ExtensionConfiguration $extensionConfiguration = null,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * Resolve a model for the given configuration.
     *
     * If the configuration uses fixed mode, returns the configured model.
     * If using criteria mode, finds the best matching model based on criteria,
     * constrained by the capability `$operation` requires (ADR-138). Pass null
     * for a resolution that is not tied to one operation.
     */
    public function resolveModel(LlmConfiguration $configuration, ?ProviderOperation $operation): ?Model
    {
        if (!$configuration->usesCriteriaSelection()) {
            // Fixed mode: return the directly configured model. Nothing is
            // being chosen here — the operator named this model — so there is
            // nothing for the operation to constrain. A fixed model that cannot
            // do the operation still fails at the adapter, exactly as before.
            return $configuration->getLlmModel();
        }

        // Criteria mode: find best matching model
        $criteria = $configuration->getModelSelectionCriteriaArray();

        $capability = $operation instanceof ProviderOperation
            ? OperationCapabilityMap::capabilityFor($operation)
            : null;
        if (!$operation instanceof ProviderOperation || !$capability instanceof ModelCapability) {
            return $this->findMatchingModel($criteria);
        }

        if (!$this->enforcingOperationCapability()) {
            $model = $this->findMatchingModel($criteria);
            $this->reportObservedMismatch($model, $capability, $operation, $configuration);

            return $model;
        }

        $constrained                        = $criteria;
        $constrained['operationCapability'] = $capability->value;

        $model = $this->findMatchingModel($constrained);
        if ($model instanceof Model) {
            return $model;
        }

        // Distinguish the two ways of ending up with nothing. Criteria that
        // match no model at all are the pre-existing "has no model assigned"
        // condition and stay a null return. Criteria that DO match, but match
        // only models declaring they cannot do this operation, are a
        // misconfiguration worth naming — and naming it here is the point of
        // the ticket, because the alternative is an opaque provider error.
        if ($this->findMatchingModel($criteria) instanceof Model) {
            throw new UnsupportedFeatureException(
                sprintf(
                    'Configuration "%s" selects its model by criteria, but every matching model declares '
                    . 'capabilities without "%s", which the "%s" operation requires. '
                    . 'Add the capability to the model record, widen the criteria, or set '
                    . 'routing.operationCapabilityEnforcement = observe.',
                    $configuration->getIdentifier(),
                    $capability->value,
                    $operation->value,
                ),
                1786100138,
            );
        }

        return null;
    }

    /**
     * Find a model matching the given criteria.
     *
     * @param array{capabilities?: string[], operationCapability?: string, adapterTypes?: string[], minContextLength?: int, maxCostInput?: int, preferLowestCost?: bool} $criteria
     */
    public function findMatchingModel(array $criteria): ?Model
    {
        $candidates = $this->findCandidates($criteria);

        if ($candidates === []) {
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
     * @param array{capabilities?: string[], operationCapability?: string, adapterTypes?: string[], minContextLength?: int, maxCostInput?: int, preferLowestCost?: bool} $criteria
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
     * @param array{capabilities?: string[], operationCapability?: string, adapterTypes?: string[], minContextLength?: int, maxCostInput?: int, preferLowestCost?: bool} $criteria
     */
    public function modelMatchesCriteria(Model $model, array $criteria): bool
    {
        return $this->matchesCapabilities($model, $criteria)
            && $this->matchesOperationCapability($model, $criteria)
            && $this->matchesAdapterTypes($model, $criteria)
            && $this->matchesMinContextLength($model, $criteria)
            && $this->matchesMaxCostInput($model, $criteria);
    }

    /**
     * Check whether the model can serve the operation the call is running.
     *
     * A SEPARATE key from `capabilities`, deliberately, because the two answer
     * different questions and need different treatment of an undeclared model.
     * `capabilities` is what an operator asked for and is matched strictly; a
     * model that declares nothing does not satisfy it. `operationCapability` is
     * derived from the running call, and there an empty capability CSV means
     * "undeclared", not "cannot" (ADR-138): the field is optional, plenty of
     * installations never filled it, and refusing every such model would break
     * them for a fact nobody ever stated.
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
     * Check whether the model satisfies all required capabilities.
     *
     * @param array{capabilities?: string[], operationCapability?: string, adapterTypes?: string[], minContextLength?: int, maxCostInput?: int, preferLowestCost?: bool} $criteria
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
     * Check whether the model meets the minimum context length requirement.
     *
     * @param array{capabilities?: string[], operationCapability?: string, adapterTypes?: string[], minContextLength?: int, maxCostInput?: int, preferLowestCost?: bool} $criteria
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
     * @param array{capabilities?: string[], operationCapability?: string, adapterTypes?: string[], minContextLength?: int, maxCostInput?: int, preferLowestCost?: bool} $criteria
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

        // Fourth priority: explicit sorting-order tiebreak. Extbase maps the
        // ctrl.sortby `sorting` column onto the model, and ModelRepository
        // already pre-orders by `sorting, name`, so this yields a deterministic
        // result without relying on usort() input-order preservation.
        return $a->getSorting() <=> $b->getSorting();
    }

    /**
     * Whether the operation-capability axis is enforced or merely observed
     * (ADR-138, following the ADR-113 switch shape).
     *
     * Fail-closed in the same sense as the tool gate: the axis observes ONLY on
     * an explicit `observe`. An unreadable extension configuration, a malformed
     * `routing` section, a missing value or a typo all enforce, so a broken
     * setting cannot silently disable the check.
     *
     * Note what fail-closed does NOT mean here: an empty capability CSV on a
     * model is still read as "undeclared" and passes. Refusing every model that
     * never filled the optional field would break working installations without
     * evidence that anything is actually wrong — see
     * {@see self::matchesOperationCapability()}.
     */
    private function enforcingOperationCapability(): bool
    {
        try {
            /** @var array<string, mixed> $config */
            $config = $this->extensionConfiguration?->get('nr_llm') ?? [];
        } catch (Throwable) {
            return true;
        }

        $routing = $config['routing'] ?? null;
        if (!is_array($routing)) {
            return true;
        }

        $mode = $routing['operationCapabilityEnforcement'] ?? null;

        return !is_string($mode) || strtolower(trim($mode)) !== self::OBSERVE;
    }

    /**
     * Observe mode: selection is left untouched, but a model that declares it
     * cannot serve the operation is reported, so an operator can see what
     * enforcement would do before switching it on.
     *
     * Silent for an undeclared (empty) capability set — that is not a mismatch,
     * it is an absent statement, and logging it on every call would bury the
     * real findings.
     */
    private function reportObservedMismatch(
        ?Model $model,
        ModelCapability $capability,
        ProviderOperation $operation,
        LlmConfiguration $configuration,
    ): void {
        if (!$model instanceof Model) {
            return;
        }

        $capabilities = $model->getCapabilitySet();
        if ($capabilities->isEmpty() || $capabilities->has($capability)) {
            return;
        }

        $this->logger?->warning(
            'Criteria-mode selection resolved a model that does not declare the capability the operation requires. '
            . 'Enforcement is set to observe, so the call proceeds.',
            [
                'configuration'       => $configuration->getIdentifier(),
                'operation'           => $operation->value,
                'requiredCapability'  => $capability->value,
                'model'               => $model->getModelId(),
                'declaredCapabilities' => $capabilities->toCsv(),
            ],
        );
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
