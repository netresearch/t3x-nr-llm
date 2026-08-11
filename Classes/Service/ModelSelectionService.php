<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Domain\Enum\RoutingPolicyMode;
use Netresearch\NrLlm\Domain\Enum\RoutingRejectionReason;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\ValueObject\ModelResolution;
use Netresearch\NrLlm\Domain\ValueObject\RoutingCandidate;
use Netresearch\NrLlm\Domain\ValueObject\RoutingDecision;
use Netresearch\NrLlm\Domain\ValueObject\RoutingReadout;
use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\Routing\CandidateRanker;
use Netresearch\NrLlm\Service\Routing\EligibilityEvaluator;
use Netresearch\NrLlm\Service\Routing\RoutingDecisionService;
use Netresearch\NrLlm\Service\Routing\RoutingSummaryFactory;
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
        // The decision point every automatic selection runs through (ADR-142).
        // Optional in the ctor only so the positional test wiring keeps working;
        // production autowires it, and a null builds one over this service's own
        // repository and configuration — the same decision, not a second one.
        private ?RoutingDecisionService $routingDecisionService = null,
        // The one implementation of "may this model serve this call". Optional
        // for the same reason; a null builds the same stateless evaluator the
        // decision service uses.
        private ?EligibilityEvaluator $eligibilityEvaluator = null,
    ) {}

    /**
     * The decision point, falling back to one built from this service's own
     * collaborators when none was injected.
     */
    private function routing(): RoutingDecisionService
    {
        return $this->routingDecisionService ?? new RoutingDecisionService(
            $this->modelRepository,
            $this->eligibility(),
            new CandidateRanker(),
            $this->extensionConfiguration,
        );
    }

    private function eligibility(): EligibilityEvaluator
    {
        return $this->eligibilityEvaluator ?? new EligibilityEvaluator();
    }

    /**
     * Explain an automatic selection: which model was chosen, and why every
     * other active model was not (ADR-142).
     *
     * Criteria mode only. Fixed mode chooses nothing — the operator named the
     * model — so there is no decision to explain.
     *
     * @param array{capabilities?: string[], operationCapability?: string, adapterTypes?: string[], minContextLength?: int, maxCostInput?: int, preferLowestCost?: bool} $criteria
     */
    public function decide(array $criteria): RoutingDecision
    {
        return $this->routing()->decide($criteria);
    }

    /**
     * Explain what this configuration would resolve to, for an operator rather
     * than for a caller (ADR-148).
     *
     * The same branch {@see self::resolveModel()} takes, answered with the
     * reasoning attached instead of just the model. It lives here because this
     * class already owns every predicate the answer needs — the fixed-vs-criteria
     * branch, the stored criteria, the operation-capability map and the
     * enforcement switch. A separate readout service would have to own second
     * copies of all four.
     *
     * `$policyMode` evaluates a hypothetical mode and writes nothing; null
     * answers for the mode the runtime would use.
     */
    public function explainRouting(
        LlmConfiguration $configuration,
        ?ProviderOperation $operation,
        ?RoutingPolicyMode $policyMode,
    ): RoutingReadout {
        if (!$configuration->usesCriteriaSelection()) {
            // Nothing is chosen here, so nothing is explained. See
            // RoutingReadout for why this is not answered as a decision.
            return RoutingReadout::fixed($configuration->getLlmModel());
        }

        $capability = $operation instanceof ProviderOperation
            ? OperationCapabilityMap::capabilityFor($operation)
            : null;
        $enforcing = $this->enforcingOperationCapability();

        return RoutingReadout::decided(
            $this->routing()->decide(
                $this->constrainedCriteria($configuration->getModelSelectionCriteriaArray(), $capability, $enforcing),
                $policyMode,
            ),
            $operation instanceof ProviderOperation,
            $capability,
            $enforcing,
            $policyMode instanceof RoutingPolicyMode,
        );
    }

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
        return $this->resolveModelForCall($configuration, $operation)->model;
    }

    /**
     * The same resolution, handing back the decision that produced it
     * (ADR-156).
     *
     * {@see self::resolveModel()} is this method with the reasoning dropped, so
     * the two cannot diverge and a caller that wants both pays for one
     * evaluation. That is the reason this exists rather than a caller pairing
     * `resolveModel()` with `explainRouting()`: the pair would run the
     * discovery, eligibility and ranking twice per request, and a model
     * activated between the two runs would make the recorded reason describe a
     * decision that never ran.
     *
     * @throws UnsupportedFeatureException see {@see self::resolveModel()}
     */
    public function resolveModelForCall(LlmConfiguration $configuration, ?ProviderOperation $operation): ModelResolution
    {
        if (!$configuration->usesCriteriaSelection()) {
            // Fixed mode: return the directly configured model. Nothing is
            // being chosen here — the operator named this model — so there is
            // nothing for the operation to constrain, and nothing to record as
            // a decision. A fixed model that cannot do the operation still
            // fails at the adapter, exactly as before.
            return ModelResolution::withoutDecision($configuration->getLlmModel());
        }

        // Criteria mode: find best matching model
        $criteria = $configuration->getModelSelectionCriteriaArray();

        $capability = $operation instanceof ProviderOperation
            ? OperationCapabilityMap::capabilityFor($operation)
            : null;
        // The extension setting is read only when an operation actually
        // constrains something, as before: a resolution with no capability
        // requirement cannot be enforcing or observing anything.
        $enforcing = $operation instanceof ProviderOperation
            && $capability instanceof ModelCapability
            && $this->enforcingOperationCapability();

        $decision = $this->routing()->decide($this->constrainedCriteria($criteria, $capability, $enforcing));
        $summary  = (new RoutingSummaryFactory())->fromDecision($decision);

        if ($operation instanceof ProviderOperation && $capability instanceof ModelCapability) {
            if (!$enforcing) {
                $this->reportObservedMismatch($decision->selected, $capability, $operation, $configuration);
            } elseif (!$decision->hasSelection()) {
                $this->refuseUnservableOperation($decision, $configuration, $capability, $operation);
            }
        }

        return new ModelResolution($decision->selected, $summary);
    }

    /**
     * Distinguish the two ways an enforcing resolution ends up with nothing.
     *
     * Criteria that match no model at all are the pre-existing "has no model
     * assigned" condition and stay a null resolution. Criteria that DO match,
     * but match only models declaring they cannot do this operation, are a
     * misconfiguration worth naming, because the alternative is an opaque
     * provider error.
     *
     * The decision already carries that distinction as a rejection reason
     * (ADR-142), so it is read off the one evaluation instead of resolving a
     * second time against the unconstrained criteria.
     *
     * @throws UnsupportedFeatureException
     */
    private function refuseUnservableOperation(
        RoutingDecision $decision,
        LlmConfiguration $configuration,
        ModelCapability $capability,
        ProviderOperation $operation,
    ): void {
        $refusedForOperation = array_filter(
            $decision->rejectedCandidates(),
            static fn(RoutingCandidate $candidate): bool => $candidate->rejectionReason === RoutingRejectionReason::OPERATION_CAPABILITY_MISSING,
        );

        if ($refusedForOperation === []) {
            return;
        }

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

    /**
     * The criteria a criteria-mode selection is actually evaluated against.
     *
     * The operation capability joins them only while enforcement is on — that
     * rule exists once, here, and both the resolution and the readout read it,
     * so the page cannot show a decision the runtime would not have taken.
     *
     * @param array{capabilities?: string[], operationCapability?: string, adapterTypes?: string[], minContextLength?: int, maxCostInput?: int, preferLowestCost?: bool} $criteria
     *
     * @return array{capabilities?: string[], operationCapability?: string, adapterTypes?: string[], minContextLength?: int, maxCostInput?: int, preferLowestCost?: bool}
     */
    private function constrainedCriteria(array $criteria, ?ModelCapability $capability, bool $enforcing): array
    {
        if (!$capability instanceof ModelCapability || !$enforcing) {
            return $criteria;
        }

        $criteria['operationCapability'] = $capability->value;

        return $criteria;
    }

    /**
     * Find a model matching the given criteria.
     *
     * @param array{capabilities?: string[], operationCapability?: string, adapterTypes?: string[], minContextLength?: int, maxCostInput?: int, preferLowestCost?: bool} $criteria
     */
    public function findMatchingModel(array $criteria): ?Model
    {
        // The selection and its explanation are the same computation (ADR-142);
        // this is the answer without the reasoning, for callers that only need
        // the model.
        return $this->routing()->decide($criteria)->selected;
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
        // One implementation of the hard constraints, shared with the decision
        // point (ADR-142). The boolean is this method's contract; the reason
        // behind it is available through {@see self::decide()}.
        return !$this->eligibility()->evaluate($model, $criteria) instanceof RoutingRejectionReason;
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
     * {@see EligibilityEvaluator} for where that rule now lives.
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
