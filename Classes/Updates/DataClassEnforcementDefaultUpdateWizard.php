<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Updates;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

/**
 * Pin the tool data-class gate to ``observe`` on an existing install (ADR-115).
 *
 * The shipped default of ``tools.dataClassEnforcement`` flips from ``observe`` to
 * ``enforce`` so a NEW install is safe by default (ADR-094/113). For an EXISTING
 * install that never set the value, that flip would silently start removing
 * over-ceiling tools from working runs. This wizard preserves the old behaviour:
 * it writes an explicit ``observe`` so the upgrade changes nothing, and its
 * description tells the operator to review the run log and switch to ``enforce``
 * when ready.
 *
 * A fresh install is distinguished by having no configured providers — you
 * cannot run a tool without one — so it keeps the new ``enforce`` default and the
 * wizard does not fire. An operator who ALREADY chose a mode explicitly (the
 * stored config carries a value) is left untouched: an explicit ``enforce`` is
 * respected, an explicit ``observe`` already matches.
 */
#[UpgradeWizard('nrLlm_dataClassEnforcementObserveForExisting')]
final readonly class DataClassEnforcementDefaultUpdateWizard implements UpgradeWizardInterface
{
    private const EXT_KEY = 'nr_llm';

    private const TABLE_PROVIDER = 'tx_nrllm_provider';

    private const OBSERVE = 'observe';

    public function __construct(
        private ConnectionPool $connectionPool,
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function getTitle(): string
    {
        return 'Preserve "observe" tool data-class enforcement on this existing nr_llm install';
    }

    public function getDescription(): string
    {
        return 'The tool data-class gate now defaults to ENFORCE for new installations, so an '
            . 'over-ceiling tool is removed from a run rather than merely logged. This install '
            . 'relied on the previous OBSERVE default, so this wizard pins it to observe to keep '
            . 'the upgrade from silently stripping tools from working setups. Review the agent run '
            . 'log (observe records what enforcement would do), then set '
            . 'tools.dataClassEnforcement to "enforce" in the extension configuration when ready.';
    }

    public function updateNecessary(): bool
    {
        // Existing install (a provider is configured) that never explicitly chose
        // an enforcement mode -> it relied on the old observe default and the new
        // enforce default would change its behaviour. A fresh install has no
        // providers and keeps enforce.
        return $this->hasProviders() && $this->storedEnforcement() === null;
    }

    public function executeUpdate(): bool
    {
        $config = $this->extensionConfiguration->get(self::EXT_KEY);
        $config = is_array($config) ? $config : [];
        $tools  = is_array($config['tools'] ?? null) ? $config['tools'] : [];

        $tools['dataClassEnforcement'] = self::OBSERVE;
        $config['tools']               = $tools;

        $this->extensionConfiguration->set(self::EXT_KEY, $config);

        return true;
    }

    /**
     * @return array<int, class-string>
     */
    public function getPrerequisites(): array
    {
        return [
            DatabaseUpdatedPrerequisite::class,
        ];
    }

    private function hasProviders(): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_PROVIDER);
        // Hidden/deleted providers still count as prior use of the extension.
        $queryBuilder->getRestrictions()->removeAll();
        $count = $queryBuilder
            ->count('uid')
            ->from(self::TABLE_PROVIDER)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) && (int)$count > 0;
    }

    /**
     * The enforcement mode as EXPLICITLY stored (pre-template-merge), or null when
     * the install never set it and relied on the shipped default. Read from the
     * raw stored configuration rather than {@see ExtensionConfiguration::get()},
     * whose merged result cannot tell "relying on the default" apart from an
     * explicit choice.
     */
    private function storedEnforcement(): ?string
    {
        // Narrowed step by step because the $GLOBALS shape is untyped.
        $confVars   = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $extensions = is_array($confVars) ? ($confVars['EXTENSIONS'] ?? null) : null;
        $extension  = is_array($extensions) ? ($extensions[self::EXT_KEY] ?? null) : null;
        $tools      = is_array($extension) ? ($extension['tools'] ?? null) : null;
        $value      = is_array($tools) ? ($tools['dataClassEnforcement'] ?? null) : null;

        return is_string($value) ? $value : null;
    }
}
