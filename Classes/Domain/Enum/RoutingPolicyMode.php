<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * How a routing decision weighs its ranking signals (ADR-142).
 *
 * Four named intents, not a panel of weight sliders. An operator can say what
 * they want out of a routing decision; they cannot be expected to calibrate
 * coefficients against each other, and a backend full of sliders produces
 * settings nobody can explain afterwards.
 *
 * {@see self::PROVIDER_PRIORITY} is the default and reproduces the ordering
 * this extension has always applied. The other three opt IN to measured
 * signals, which is a deliberate choice rather than an upgrade surprise:
 * quality and health data change which model serves a call, and an installation
 * that never asked for that must not get it from a version bump. It follows the
 * shape `health.reorderFallback` already uses for the fallback chain.
 *
 * Weights apply only to signals that HAVE data. A model with no quality
 * measurement is not penalised for the absence — see
 * {@see \Netresearch\NrLlm\Service\Routing\CandidateRanker}.
 *
 * @internal
 */
enum RoutingPolicyMode: string
{
    /**
     * The operator's provider priority decides; measured signals do not
     * participate, and cost is weighed only where the criteria asked for it
     * with `preferLowestCost`. Identical to the pre-ADR-142 ordering.
     */
    case PROVIDER_PRIORITY = 'providerPriority';

    /**
     * Quality and reliability count where known, cost where asked for.
     */
    case BALANCED = 'balanced';

    /**
     * Measured quality dominates; cost is weighed only where asked for.
     */
    case QUALITY = 'quality';

    /**
     * Cost dominates, whether or not the criteria set a cost preference.
     */
    case ECONOMY = 'economy';

    public static function fromValue(?string $value): self
    {
        return self::tryFrom(trim((string)$value)) ?? self::PROVIDER_PRIORITY;
    }

    /**
     * Whether measured signals participate at all. False leaves the decision to
     * provider priority and the established tiebreaks.
     */
    public function usesMeasuredSignals(): bool
    {
        return $this !== self::PROVIDER_PRIORITY;
    }

    /**
     * Weight of the measured-quality signal in this mode.
     */
    public function qualityWeight(): float
    {
        return match ($this) {
            self::QUALITY           => 0.7,
            self::BALANCED          => 0.4,
            self::ECONOMY           => 0.1,
            self::PROVIDER_PRIORITY => 0.0,
        };
    }

    /**
     * Weight of the provider-health signal. Reliability matters in every mode
     * that uses signals at all: a cheap provider that fails is not cheap.
     */
    public function healthWeight(): float
    {
        return match ($this) {
            self::QUALITY           => 0.3,
            self::BALANCED          => 0.4,
            self::ECONOMY           => 0.3,
            self::PROVIDER_PRIORITY => 0.0,
        };
    }

    /**
     * Weight of the cost signal.
     *
     * In BALANCED and QUALITY this applies only when the criteria set
     * `preferLowestCost`; ECONOMY applies it regardless, which is what
     * distinguishes the mode.
     */
    public function costWeight(): float
    {
        return match ($this) {
            self::ECONOMY           => 0.6,
            self::BALANCED          => 0.2,
            self::QUALITY           => 0.0,
            self::PROVIDER_PRIORITY => 0.0,
        };
    }

    /**
     * Whether cost is weighed even when the criteria did not ask for it.
     */
    public function alwaysWeighsCost(): bool
    {
        return $this === self::ECONOMY;
    }

    /**
     * The label an operator reads instead of the wire value (ADR-148).
     *
     * Named `get…` because Fluid reaches a method only through the
     * get/is/has convention: `{mode.labelKey}` resolves `getLabelKey()`,
     * and a plain `labelKey()` silently yields null — which reaches
     * `f:translate` as an empty key and throws at render time. Same shape as
     * {@see \Netresearch\NrLlm\Service\Governance\GovernanceProfile::getLabelKey()}.
     */
    public function getLabelKey(): string
    {
        return 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:routing.mode.' . $this->name;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }
}
