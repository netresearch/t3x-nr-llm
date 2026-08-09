<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Governance;

/**
 * One line of the read-only effective-policy readout (ADR-140): a governance
 * key, the value the runtime resolver actually returned, and the FQCN of the
 * resolver that returned it.
 *
 * A null $value means the resolver could not be asked. The row then reads
 * "unknown" — never a value, and never a guess reconstructed from the raw
 * setting, because a value shown here must be one the runtime would apply.
 *
 * $noteKey carries the case where the effective value is right but incomplete:
 * a setting the runtime applies to most, not all, of what the key names. The
 * note is a translation key rather than prose so the view stays the only place
 * that renders language.
 *
 * There is deliberately no provenance ("shipped default" vs "explicitly set"):
 * no resolver exposes it, and TYPO3's own
 * ExtensionConfiguration::synchronizeExtConfTemplateWithLocalConfigurationOfAllExtensions()
 * merges the shipped template defaults into settings.php, so the distinction is
 * not reconstructable from the stored configuration either.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class EffectivePolicyRow
{
    /**
     * @param string      $key     The configuration key, e.g. `privacy.level`
     * @param string|null $value   The effective value, or null when unknown
     * @param string      $reader  FQCN of the resolver the runtime reads through
     * @param string|null $noteKey `LLL:` key of a qualification the value alone
     *                             would misstate, or null when the value stands
     *                             on its own. Rendered by Governance.html.
     */
    public function __construct(
        public string $key,
        public ?string $value,
        public string $reader,
        public ?string $noteKey = null,
    ) {}

    /**
     * Whether the resolver answered. False renders as "unknown".
     */
    public function isKnown(): bool
    {
        return $this->value !== null;
    }
}
