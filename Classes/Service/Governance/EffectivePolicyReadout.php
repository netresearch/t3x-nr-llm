<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Governance;

use Netresearch\NrLlm\Domain\Enum\PrivacyDataCategory;
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
 * Plus one row per ``privacy.retention.<category>`` override that actually
 * deviates from the global window — never the seven that ship as ``0`` and
 * resolve to it.
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
    /**
     * Observe mode is not global: {@see \Netresearch\NrLlm\Service\Tool\ToolCallPolicy::decide()}
     * enforces the ceiling for every {@see \Netresearch\NrLlm\Service\Tool\RemoteToolInterface}
     * tool regardless of the setting (ADR-115). An unqualified `observe` would
     * send an operator whose MCP tool is being dropped looking somewhere else.
     */
    private const NOTE_REMOTE_ALWAYS_ENFORCED = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:governance.note.remoteAlwaysEnforced';

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
        $retention = $this->row(
            'privacy.retentionDays',
            $this->privacyPolicy::class,
            fn(): string => (string)$this->privacyPolicy->retentionDays(),
        );

        return [
            $this->row(
                'privacy.level',
                $this->privacyPolicy::class,
                fn(): string => $this->privacyPolicy->level()->value,
            ),
            $retention,
            ...$this->retentionOverrideRows($retention),
            $this->enforcementRow(),
            $this->row(
                'skills.minTrustLevel',
                $this->skillComposerFactory::class,
                fn(): string => $this->skillComposerFactory->minTrustLevel()->value,
            ),
        ];
    }

    /**
     * The enforcement mode, qualified where the gate does not apply it.
     *
     * `observe` is the mode for builtins only: the gate enforces the trust-zone
     * ceiling for every remote tool whatever the setting says. The unqualified
     * word would therefore be a true value and a false statement.
     */
    private function enforcementRow(): EffectivePolicyRow
    {
        $row = $this->row(
            'tools.dataClassEnforcement',
            $this->enforcementResolver::class,
            fn(): string => $this->enforcementResolver->mode(),
        );

        if ($row->value !== DataClassEnforcementResolver::MODE_OBSERVE) {
            return $row;
        }

        return new EffectivePolicyRow($row->key, $row->value, $row->reader, self::NOTE_REMOTE_ALWAYS_ENFORCED);
    }

    /**
     * One row per `privacy.retention.<category>` override that actually moves a
     * window, and none for the rest.
     *
     * The seven overrides ship as `0` and resolve to the global window, so on a
     * stock install listing them adds seven rows reading "30" and informs
     * nobody. Once one IS set, the opposite holds: `privacy.retentionDays` is
     * then no longer the window that category is purged on, and a single number
     * on a page promising what the install "actually applies" is read as the
     * answer for everything.
     *
     * @return list<EffectivePolicyRow>
     */
    private function retentionOverrideRows(EffectivePolicyRow $globalRetention): array
    {
        if (!$globalRetention->isKnown()) {
            // Nothing to deviate from — the global row already reads "unknown",
            // and a category window without its baseline states nothing.
            return [];
        }

        $rows = [];
        foreach (PrivacyDataCategory::cases() as $category) {
            $row = $this->row(
                'privacy.retention.' . $category->configKey(),
                $this->privacyPolicy::class,
                fn(): string => (string)$this->privacyPolicy->retentionDaysFor($category),
            );

            if ($row->isKnown() && $row->value !== $globalRetention->value) {
                $rows[] = $row;
            }
        }

        return $rows;
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
