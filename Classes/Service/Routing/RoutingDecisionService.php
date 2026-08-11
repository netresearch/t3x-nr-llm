<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Routing;

use Netresearch\NrLlm\Domain\Enum\RoutingPolicyMode;
use Netresearch\NrLlm\Domain\Enum\RoutingRejectionReason;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\ValueObject\RoutingCandidate;
use Netresearch\NrLlm\Domain\ValueObject\RoutingDecision;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * The one place an automatic model selection is decided (ADR-142).
 *
 * Discovery, then eligibility, then ranking, in that order and never mixed:
 * a model refused by a hard constraint carries no score, so no ranking signal
 * can bring it back. That is the invariant the split exists for.
 *
 * This does not add a second selection path.
 * {@see \Netresearch\NrLlm\Service\ModelSelectionService} answers exactly the
 * same question through this class, and its `modelMatchesCriteria()` shares the
 * one {@see EligibilityEvaluator} rather than keeping a second copy of the
 * predicates.
 *
 * @internal
 */
final readonly class RoutingDecisionService
{
    public function __construct(
        private ModelRepository $modelRepository,
        private EligibilityEvaluator $eligibility,
        private CandidateRanker $ranker,
        private ?ExtensionConfiguration $extensionConfiguration = null,
    ) {}

    /**
     * Decide which active model serves a call, and record why every other one
     * did not.
     *
     * The candidate universe is the ACTIVE models: an inactive model is not a
     * rejected candidate, it is not a candidate. Reporting it would say more
     * about the catalogue than about the decision.
     *
     * `$policyMode` answers "what would a different mode choose" without
     * changing what runs (ADR-148). It is READ ONLY: passing one evaluates the
     * hypothetical for this call and nothing else — the install setting is
     * neither written nor consulted, and the next call without it is back to
     * the configured mode. Null keeps the configured mode, which is the
     * runtime's only path.
     *
     * @param array{capabilities?: string[], operationCapability?: string, adapterTypes?: string[], minContextLength?: int, maxCostInput?: int, preferLowestCost?: bool} $criteria
     */
    public function decide(array $criteria, ?RoutingPolicyMode $policyMode = null): RoutingDecision
    {
        $mode = $policyMode ?? $this->policyMode();

        $eligible = [];
        $rejected = [];
        foreach ($this->modelRepository->findActive() as $model) {
            // @phpstan-ignore instanceof.alwaysTrue (defensive type guard, as in ModelSelectionService)
            if (!$model instanceof Model) {
                continue;
            }

            $reason = $this->eligibility->evaluate($model, $criteria);
            if (!$reason instanceof RoutingRejectionReason) {
                $eligible[] = $model;

                continue;
            }

            $rejected[] = RoutingCandidate::rejected($model, $reason);
        }

        if ($eligible === []) {
            return new RoutingDecision(null, $rejected, $mode);
        }

        $ranked = $this->ranker->rank($eligible, $mode, $criteria);

        return new RoutingDecision(
            $ranked[0]->model ?? null,
            [...$ranked, ...$rejected],
            $mode,
        );
    }

    /**
     * The configured policy mode.
     *
     * Defaults to {@see RoutingPolicyMode::PROVIDER_PRIORITY} — the ordering
     * this extension always applied — on an unreadable configuration, a
     * malformed `routing` section, a missing value or a typo. A broken setting
     * must not silently change which model serves a call; the conservative
     * direction here is the established behaviour, not the newest feature.
     */
    private function policyMode(): RoutingPolicyMode
    {
        try {
            /** @var array<string, mixed> $config */
            $config = $this->extensionConfiguration?->get('nr_llm') ?? [];
        } catch (Throwable) {
            return RoutingPolicyMode::PROVIDER_PRIORITY;
        }

        $routing = $config['routing'] ?? null;
        if (!is_array($routing)) {
            return RoutingPolicyMode::PROVIDER_PRIORITY;
        }

        $mode = $routing['policyMode'] ?? null;

        return RoutingPolicyMode::fromValue(is_string($mode) ? $mode : null);
    }
}
