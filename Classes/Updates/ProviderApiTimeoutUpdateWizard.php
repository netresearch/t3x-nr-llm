<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Updates;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

/**
 * Raise persisted provider api_timeout values from the old 30s default to 120s.
 *
 * Until the timeout wiring landed, api_timeout was stored but never applied
 * to any HTTP request, so existing rows at the old TCA default of 30 never
 * had an effect at runtime. With the timeout now enforced, 30 seconds would
 * truncate long generations (local Ollama model loads alone can exceed it).
 *
 * A deliberately-chosen 30 is indistinguishable from the never-applied
 * default, so it is migrated too — operators who want a 30 second timeout
 * must re-set it after the upgrade.
 */
#[UpgradeWizard('nrLlm_providerApiTimeout120')]
final readonly class ProviderApiTimeoutUpdateWizard implements UpgradeWizardInterface
{
    private const TABLE = 'tx_nrllm_provider';
    private const OLD_DEFAULT = 30;
    private const NEW_DEFAULT = 120;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function getTitle(): string
    {
        return 'Raise LLM provider API timeout from the old 30s default to 120s';
    }

    public function getDescription(): string
    {
        return 'The provider api_timeout setting was previously never applied to API requests, '
            . 'so rows still at the old default of 30 seconds had no runtime effect. Now that the '
            . 'timeout is enforced, 30 seconds would truncate long generations. This wizard raises '
            . 'all providers with api_timeout = 30 to the new default of 120 seconds. A deliberately '
            . 'configured value of 30 cannot be distinguished from the old default and is migrated '
            . 'as well — re-set it afterwards if you really want a 30 second timeout.';
    }

    public function executeUpdate(): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder
            ->update(self::TABLE)
            ->set('api_timeout', self::NEW_DEFAULT)
            ->where(
                $queryBuilder->expr()->eq(
                    'api_timeout',
                    $queryBuilder->createNamedParameter(self::OLD_DEFAULT, Connection::PARAM_INT),
                ),
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
            ->where(
                $queryBuilder->expr()->eq(
                    'api_timeout',
                    $queryBuilder->createNamedParameter(self::OLD_DEFAULT, Connection::PARAM_INT),
                ),
            )
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
