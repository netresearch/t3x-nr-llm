<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Governance;

/**
 * Compares the effective governance against a named profile (ADR-145).
 *
 * Compares the rows {@see EffectivePolicyReadout::rows()} produced, handed in
 * by the caller rather than fetched here. The readout already asks each runtime
 * resolver, and taking its output as an argument means this compares exactly
 * what the operator is looking at — there is no way for it to read the
 * resolvers a second time and disagree with the table above it.
 *
 * It never changes anything. ADR-140 declined an apply path for the readout and
 * that reasoning is unchanged — writing extension configuration rewrites the
 * whole merged array and would materialise every shipped default. A deviation
 * is shown with where to change it, and an operator changes it.
 *
 * @internal
 */
final readonly class GovernanceProfileEvaluator
{
    /**
     * Where each key is actually set. Every governance key today is instance-
     * wide extension configuration; the map exists so a key that moves — to
     * TCA, to a group record — can say so without the template guessing.
     */
    private const WHERE = [
        'privacy.level'              => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:governance.where.extensionConfiguration',
        'privacy.retentionDays'      => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:governance.where.extensionConfiguration',
        'tools.dataClassEnforcement' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:governance.where.extensionConfiguration',
        'skills.minTrustLevel'       => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:governance.where.extensionConfiguration',
    ];

    private const WHERE_FALLBACK = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:governance.where.unknown';

    /**
     * Every key where the installation differs from the profile, in the
     * readout's own order so the diff reads alongside the table above it.
     *
     * A key the profile makes no statement about produces nothing, and so does
     * a key the readout does not report — the profile describing a setting this
     * installation does not have is the profile's problem, not a deviation the
     * operator can act on.
     *
     * @param list<EffectivePolicyRow> $rows the readout's rows, handed in rather than fetched: this
     *                                       compares what the table shows, so it cannot compare
     *                                       against a second reading of the same resolvers
     *
     * @return list<GovernanceProfileDeviation>
     */
    public function deviations(array $rows, GovernanceProfile $profile): array
    {
        $expectations = $profile->expectations();
        $deviations   = [];

        foreach ($rows as $row) {
            if (!isset($expectations[$row->key])) {
                continue;
            }

            $expected = $expectations[$row->key];
            if ($row->value === $expected) {
                continue;
            }

            $deviations[] = new GovernanceProfileDeviation(
                $row->key,
                $row->value,
                $expected,
                self::WHERE[$row->key] ?? self::WHERE_FALLBACK,
            );
        }

        return $deviations;
    }

    /**
     * Whether the installation matches the profile in every key the profile
     * speaks about.
     *
     * @param list<EffectivePolicyRow> $rows
     */
    public function isCompliant(array $rows, GovernanceProfile $profile): bool
    {
        return $this->deviations($rows, $profile) === [];
    }
}
