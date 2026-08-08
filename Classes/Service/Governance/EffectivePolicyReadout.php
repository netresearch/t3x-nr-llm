<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Governance;

use Netresearch\NrLlm\Service\Privacy\PrivacyPolicyInterface;
use Netresearch\NrLlm\Service\Skill\SkillComposerFactory;
use Netresearch\NrLlm\Service\Tool\DataClassEnforcementResolver;
use Throwable;

/**
 * The read-only effective-policy readout (ADR-140).
 *
 * Governance is spread over ``ext_conf_template.txt``, several TCA tables,
 * ``be_groups`` and the dashboard widgets; there was no single place showing
 * the *effective* state. This service assembles the four keys that carry an
 * actual decision, each read through the SAME resolver the runtime uses, so the
 * view cannot drift from behaviour:
 *
 * - ``privacy.level`` and ``privacy.retentionDays`` via {@see PrivacyPolicyInterface}
 * - ``tools.dataClassEnforcement`` via {@see DataClassEnforcementResolver}
 * - ``skills.minTrustLevel`` via {@see SkillComposerFactory}
 *
 * Every read is wrapped: a resolver that throws yields an "unknown" row rather
 * than a value. A governance view that guesses is worse than one that admits it
 * does not know, because an operator would act on the guess.
 *
 * There is no apply path — see ADR-140 for why.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class EffectivePolicyReadout
{
    public function __construct(
        private PrivacyPolicyInterface $privacyPolicy,
        private DataClassEnforcementResolver $enforcementResolver,
        private SkillComposerFactory $skillComposerFactory,
    ) {}

    /**
     * @return list<EffectivePolicyRow>
     */
    public function rows(): array
    {
        return [
            $this->row(
                'privacy.level',
                $this->privacyPolicy::class,
                fn(): string => $this->privacyPolicy->level()->value,
            ),
            $this->row(
                'privacy.retentionDays',
                $this->privacyPolicy::class,
                fn(): string => (string)$this->privacyPolicy->retentionDays(),
            ),
            $this->row(
                'tools.dataClassEnforcement',
                $this->enforcementResolver::class,
                fn(): string => $this->enforcementResolver->mode(),
            ),
            $this->row(
                'skills.minTrustLevel',
                $this->skillComposerFactory::class,
                fn(): string => $this->skillComposerFactory->minTrustLevel()->value,
            ),
        ];
    }

    /**
     * Ask one resolver. Anything thrown becomes "unknown" — the readout never
     * substitutes a default for an answer it did not get.
     *
     * @param callable(): string $read
     */
    private function row(string $key, string $reader, callable $read): EffectivePolicyRow
    {
        try {
            $value = $read();
        } catch (Throwable) {
            $value = null;
        }

        return new EffectivePolicyRow($key, $value, $reader);
    }
}
