<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
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
     */
    public function zoneFor(LlmConfiguration $configuration): TrustZone
    {
        $zone = $this->zoneForProvider($configuration->getProvider());

        foreach ($configuration->getFallbackChainDTO()->configurationIdentifiers as $identifier) {
            $fallback = $this->configurationRepository?->findOneByIdentifier($identifier);
            $zone     = TrustZone::leastTrusted(
                $zone,
                $fallback === null ? TrustZone::EXTERNAL_GLOBAL : $this->zoneForProvider($fallback->getProvider()),
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
