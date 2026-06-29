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
 * ConnectionPool-backed store for the global per-tool enable/disable overrides
 * (`tx_nrllm_tool_state`).
 *
 * A row exists only for a tool whose admin override differs from — or has been
 * explicitly set equal to — its {@see ToolInterface::isEnabledByDefault()}.
 * The absence of a row therefore means "no override; use the tool default".
 * The table carries no `deleted`/`hidden` columns and is never edited via
 * FormEngine, so every query removes the default restrictions (mirroring the
 * builtin tools) to avoid referencing non-existent enable columns.
 */
final readonly class ToolStateRepository
{
    use SafeCastTrait;

    private const TABLE = 'tx_nrllm_tool_state';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * Return the explicit overrides keyed by tool name: `tool_name => enabled`.
     *
     * @return array<string, bool>
     */
    public function overrides(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('tool_name', 'enabled')
            ->from(self::TABLE)
            ->executeQuery()
            ->fetchAllAssociative();

        $overrides = [];
        foreach ($rows as $row) {
            $name = self::toStr($row['tool_name'] ?? '');
            if ($name === '') {
                continue;
            }
            $overrides[$name] = self::toInt($row['enabled'] ?? 0) === 1;
        }

        return $overrides;
    }

    /**
     * Upsert the override for a single tool. A select-then-write keeps the unique
     * `tool_name` intact and avoids the "0 affected rows because the value was
     * unchanged" pitfall of a blind update-or-insert.
     */
    public function setEnabled(string $toolName, bool $enabled): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $existingUid = $queryBuilder
            ->select('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('tool_name', $queryBuilder->createNamedParameter($toolName)),
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
            'pid'       => 0,
            'tool_name' => $toolName,
            'enabled'   => $value,
        ]);
    }
}
