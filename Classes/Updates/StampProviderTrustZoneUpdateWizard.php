<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Updates;

use Netresearch\NrLlm\Domain\Enum\TrustZone;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

/**
 * Stamp an explicit trust zone on providers that predate the declaration
 * (ADR-094).
 *
 * A new provider defaults to the strictest zone, which is the right default for
 * something nobody has judged yet. Applying that retroactively to an existing
 * installation would be an outage in slow motion: every diagnostics, code and
 * configuration tool would silently disappear from runs that used them
 * yesterday.
 *
 * So existing rows are stamped once, from the only signal available: an
 * `ollama` adapter runs locally by construction, everything else is treated as
 * an external global service until an operator says otherwise. After this
 * wizard the stored column is the single source of truth — nothing derives a
 * zone from the adapter type at runtime, because that would make the operator's
 * declaration optional.
 */
#[UpgradeWizard('nrLlm_stampProviderTrustZone')]
final readonly class StampProviderTrustZoneUpdateWizard implements UpgradeWizardInterface
{
    private const TABLE = 'tx_nrllm_provider';

    /** Adapters that run on the operator's own machine or network. */
    private const LOCAL_ADAPTERS = ['ollama'];

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function getTitle(): string
    {
        return 'Declare a trust zone for existing LLM providers';
    }

    public function getDescription(): string
    {
        return 'Tool output is now capped by the trust zone of the provider it would be sent to. '
            . 'Providers created before this setting existed have no zone, which resolves to the '
            . 'strictest one and would remove diagnostics, code and configuration tools from runs '
            . 'that previously used them. This wizard stamps every un-declared provider: Ollama '
            . 'providers as "local", all others as "external, global". Review the setting on each '
            . 'provider afterwards — the extension cannot verify where an endpoint actually runs.';
    }

    public function executeUpdate(): bool
    {
        $localZone    = TrustZone::LOCAL->value;
        $externalZone = TrustZone::EXTERNAL_GLOBAL->value;

        $localBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $localBuilder->getRestrictions()->removeAll();
        $localBuilder
            ->update(self::TABLE)
            ->set('trust_zone', $localZone)
            ->where(
                $localBuilder->expr()->eq('trust_zone', $localBuilder->createNamedParameter('')),
                $localBuilder->expr()->in(
                    'adapter_type',
                    $localBuilder->createNamedParameter(self::LOCAL_ADAPTERS, Connection::PARAM_STR_ARRAY),
                ),
            )
            ->executeStatement();

        $externalBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $externalBuilder->getRestrictions()->removeAll();
        $externalBuilder
            ->update(self::TABLE)
            ->set('trust_zone', $externalZone)
            ->where(
                $externalBuilder->expr()->eq('trust_zone', $externalBuilder->createNamedParameter('')),
            )
            ->executeStatement();

        return true;
    }

    public function updateNecessary(): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        // Include hidden and deleted rows so a later un-delete stays consistent.
        $queryBuilder->getRestrictions()->removeAll();
        $count = $queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('trust_zone', $queryBuilder->createNamedParameter('')))
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) && (int)$count > 0;
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
}
