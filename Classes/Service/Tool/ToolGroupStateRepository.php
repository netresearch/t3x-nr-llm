<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * ConnectionPool-backed store for the global per-GROUP enable/disable
 * overrides (`tx_nrllm_tool_group_state`), mirroring {@see ToolStateRepository}.
 *
 * A row exists only for a group an admin has toggled; the absence of a row
 * means "group enabled". Because the state is keyed by group NAME (not by the
 * currently registered tools), a disabled group also covers tools of that
 * group that are installed later — the cascade in
 * {@see ToolAvailabilityService} stays fail-closed.
 */
final readonly class ToolGroupStateRepository
{
    use SafeCastTrait;

    private const TABLE = 'tx_nrllm_tool_group_state';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * Return the explicit overrides keyed by group name: `group => enabled`.
     *
     * @return array<string, bool>
     */
    public function overrides(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('group_name', 'enabled')
            ->from(self::TABLE)
            ->executeQuery()
            ->fetchAllAssociative();

        $overrides = [];
        foreach ($rows as $row) {
            $name = self::toStr($row['group_name'] ?? '');
            if ($name === '') {
                continue;
            }
            $overrides[$name] = self::toInt($row['enabled'] ?? 0) === 1;
        }

        return $overrides;
    }

    /**
     * Upsert the override for a single group (select-then-write, mirroring
     * {@see ToolStateRepository::setEnabled()}).
     */
    public function setEnabled(string $groupName, bool $enabled): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $existingUid = $queryBuilder
            ->select('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('group_name', $queryBuilder->createNamedParameter($groupName)),
            )
            ->executeQuery()
            ->fetchOne();

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $value      = $enabled ? 1 : 0;
        if ($existingUid !== false) {
            $connection->update(self::TABLE, ['enabled' => $value], ['uid' => self::toInt($existingUid)]);

            return;
        }

        $connection->insert(self::TABLE, [
            'pid'        => 0,
            'group_name' => $groupName,
            'enabled'    => $value,
        ]);
    }
}
