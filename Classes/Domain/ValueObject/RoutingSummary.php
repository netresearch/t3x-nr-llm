<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * The persistable residue of one automatic model selection (ADR-156).
 *
 * {@see RoutingDecision} is the decision: it holds Model entities, per-candidate
 * scores and per-signal floats, and it lives for the length of one call. This is
 * what survives into `tx_nrllm_telemetry` — six scalars that answer "why this
 * model" for a call that already happened, without keeping the entities alive
 * and without widening what the log stores.
 *
 * What it deliberately does NOT carry: the candidate model list. Which models
 * exist and which lost is a catalogue question, answerable on the Governance
 * tab against the live catalogue; a per-request copy of it would grow the log
 * by the size of the model table and would go stale the moment a model is
 * renamed. The COUNT and the distinct REASONS are the parts that describe this
 * request rather than the installation.
 *
 * `selectedModel` and `fallbackOccurred` are not here either: the row already
 * carries them as `served_model` and `fallback_attempts`.
 *
 * Only scalars, on purpose — this crosses the `@api` boundary through
 * {@see \Netresearch\NrLlm\Provider\Middleware\TelemetrySignals}, and the types
 * it would otherwise expose ({@see RoutingDecision},
 * {@see \Netresearch\NrLlm\Domain\Enum\RoutingPolicyMode}) are `@internal`.
 * Built by {@see \Netresearch\NrLlm\Service\Routing\RoutingSummaryFactory},
 * which is where the mapping from the decision lives.
 *
 * @api
 */
final readonly class RoutingSummary
{
    /**
     * @param string       $policyMode        the {@see \Netresearch\NrLlm\Domain\Enum\RoutingPolicyMode}
     *                                        value the decision was taken under
     * @param int          $candidateCount    every active model that was considered, eligible and
     *                                        refused alike
     * @param list<string> $rejectionReasons  the DISTINCT
     *                                        {@see \Netresearch\NrLlm\Domain\Enum\RoutingRejectionReason}
     *                                        names that refused at least one candidate, sorted. One
     *                                        reason per name, not per model: the reason set is what
     *                                        varies between requests, the per-model mapping is not.
     * @param bool         $qualitySignalUsed whether the measured-quality signal both carried weight
     *                                        in this mode AND had data for at least one eligible
     *                                        candidate. False therefore means "did not move this
     *                                        decision", which is the question an operator is asking —
     *                                        a weighted signal nobody measured moved nothing
     * @param bool         $healthSignalUsed  as above, for provider health
     * @param bool         $costSignalUsed    as above, for cost
     */
    public function __construct(
        public string $policyMode,
        public int $candidateCount,
        public array $rejectionReasons,
        public bool $qualitySignalUsed,
        public bool $healthSignalUsed,
        public bool $costSignalUsed,
    ) {}
}
