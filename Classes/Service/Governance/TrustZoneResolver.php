<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Governance;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;

/**
 * The trust zone a run can actually reach, and the data-class ceiling that
 * follows from it (ADR-094).
 *
 * Not simply the zone of the configuration's own provider: when a call fails,
 * {@see \Netresearch\NrLlm\Provider\Middleware\FallbackMiddleware} re-runs it
 * against a *different* configuration from the fallback chain. A locally hosted
 * primary with an external fallback would otherwise be offered secret-adjacent
 * tools and then fail over to that external provider mid-run, carrying the tool
 * output with it. So the effective zone is the least trusted zone reachable.
 *
 * The chain is walked exactly ONE level. Fallback is documented as shallow — a
 * fallback configuration's own chain is ignored to prevent recursion and cycles
 * — so walking deeper would model a code path that cannot execute.
 *
 * Everything unknown fails closed to {@see TrustZone::EXTERNAL_GLOBAL}: no
 * provider, no model, an unresolvable chain entry, an empty column.
 *
 * Operator-facing consequence worth knowing: one external fallback drags an
 * otherwise local configuration down to the external ceiling. That is correct —
 * the run really can reach that provider — but it surprises operators who added
 * a fallback purely for availability.
 */
final readonly class TrustZoneResolver
{
    public function __construct(
        private ?LlmConfigurationRepository $configurationRepository = null,
    ) {}

    public function zoneForProvider(?Provider $provider): TrustZone
    {
        return $provider?->getTrustZoneEnum() ?? TrustZone::EXTERNAL_GLOBAL;
    }

    /**
     * The least trusted zone the run can reach: the configuration's own
     * provider, or any provider one fallback hop away.
     *
     * `$servingModel` is the model that will actually serve the call, where the
     * caller already resolved one (ADR-149). It exists because a criteria-mode
     * configuration has no model relation and therefore no provider of its own:
     * without it every such configuration answers `EXTERNAL_GLOBAL`, however
     * local the model routing picks. Passing nothing keeps the configuration's
     * own relation, which is the fixed-mode case and what every caller had
     * before — and in fixed mode the two are the same object anyway, because
     * {@see LlmConfiguration::getProvider()} reads through that relation.
     *
     * Nothing here resolves a model. The zone follows routing; it must never
     * drive it.
     *
     * The fallback hops are deliberately NOT given the same treatment: a
     * criteria-mode fallback still contributes `EXTERNAL_GLOBAL`. Resolving a
     * model for every chain entry would run the routing decision once per hop
     * for a chain that may never be walked.
     */
    public function zoneFor(LlmConfiguration $configuration, ?Model $servingModel = null): TrustZone
    {
        $zone = $this->zoneForProvider(
            $servingModel instanceof Model ? $servingModel->getProvider() : $configuration->getProvider(),
        );

        foreach ($configuration->getFallbackChainDTO()->configurationIdentifiers as $identifier) {
            $fallback = $this->configurationRepository?->findOneByIdentifier($identifier);
            $zone     = TrustZone::leastTrusted(
                $zone,
                $fallback instanceof LlmConfiguration ? $this->zoneForProvider($fallback->getProvider()) : TrustZone::EXTERNAL_GLOBAL,
            );
        }

        return $zone;
    }

    /**
     * The most sensitive data class a tool may return into a run against this
     * configuration.
     */
    public function ceilingFor(LlmConfiguration $configuration): ToolDataClass
    {
        return $this->zoneFor($configuration)->maxDataClass();
    }
}
