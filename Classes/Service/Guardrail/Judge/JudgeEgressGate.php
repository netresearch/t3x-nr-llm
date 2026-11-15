<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail\Judge;

use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Domain\ValueObject\ProviderIdentifier;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;

/**
 * Where a judge may send a text (ADR-288 R10, ADR-300).
 *
 * One implementation for the guardrail and for the check of a delivered text,
 * so the two cannot disagree about which judge may read what.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class JudgeEgressGate
{
    public function __construct(
        private TrustZoneResolver $trustZones,
        private ProviderRepository $providers,
        private LlmConfigurationRepository $configurations,
    ) {}

    /**
     * The configuration that judges for $configuration: chosen, not itself,
     * existing and active, or the check cannot run.
     *
     * @throws JudgeUnavailableException
     */
    public function judgingConfiguration(LlmConfiguration $configuration): LlmConfiguration
    {
        $uid = $configuration->getRuleJudgeConfiguration();
        if ($uid <= 0 || $uid === $configuration->getUid()) {
            throw new JudgeUnavailableException('No judging configuration is chosen.', 9422928822);
        }

        $judging = $this->configurations->findByUid($uid);
        if (!$judging instanceof LlmConfiguration || !$judging->isActive()) {
            throw new JudgeUnavailableException('The judging configuration does not exist or is not active.', 4624017905);
        }

        return $judging;
    }

    /**
     * The zone of the provider that wrote the text, failing closed.
     *
     * The provider that served the call where it is named, else the
     * configuration's own provider. Where neither is known, or the provider
     * declares no zone, the text is treated as written in the most trusted
     * zone, so only a judge in that zone is asked. The usual default for an
     * unknown zone, the least trusted one, would let any judge pass here: it
     * is the right default for where data may go, and the wrong one for where
     * it came from.
     */
    public function writerZone(string $servingProviderIdentifier, ?LlmConfiguration $configuration): TrustZone
    {
        $provider = null;
        if ($servingProviderIdentifier !== '') {
            $provider = $this->providers->findOneByIdentifier(new ProviderIdentifier($servingProviderIdentifier));
        }

        $provider ??= $configuration?->getProvider();

        return $provider instanceof Provider
            ? TrustZone::tryFrom($provider->getTrustZone()) ?? TrustZone::LOCAL
            : TrustZone::LOCAL;
    }

    /**
     * The judge sends the text and the rules to another provider. It may not
     * reach a provider less trusted than the one that wrote the text, nor one
     * whose zone does not admit a rule snippet's declared class. The judging
     * side counts every provider it can reach, its fallbacks included.
     *
     * @throws JudgeUnavailableException
     */
    public function assertMayReceive(TrustZone $own, LlmConfiguration $judging, RuleSet $rules): void
    {
        $judge = $this->trustZones->zoneFor($judging);
        if (TrustZone::leastTrusted($own, $judge) !== $own) {
            throw new JudgeUnavailableException('The judging configuration reaches a less trusted provider than the judged one.', 2088642690);
        }

        foreach ($rules->dataClasses as $class) {
            if (!$judge->permits($class)) {
                throw new JudgeUnavailableException('A rule snippet declares a data class the judging configuration may not receive.', 8136373669);
            }
        }
    }
}
