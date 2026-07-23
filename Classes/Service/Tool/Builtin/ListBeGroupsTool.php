<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ToolDataClassInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * List the backend user groups (`be_groups`): uid and title only.
 *
 * TYPO3 has no separate "roles" table — backend roles ARE backend groups, so
 * this is the tool to answer "what roles exist?".
 *
 * Security contract (see {@see ToolInterface}): only the uid and the
 * human-readable title are read. No permission masks, allowed-table lists,
 * TSconfig or any other authorization-bearing column is surfaced. Soft-deleted
 * groups are excluded and the row count is hard-capped at {@see self::HARD_LIMIT}.
 */
final readonly class ListBeGroupsTool implements ToolInterface, ToolDataClassInterface
{
    use SafeCastTrait;

    private const TABLE = 'be_groups';

    private const HARD_LIMIT = 200;

    public function __construct(
        protected ConnectionPool $connectionPool,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'list_be_groups',
            'List the backend user groups (uid, title). Backend roles ARE backend groups in TYPO3; '
            . 'there is no separate roles table. No permission masks or other authorization columns are returned.',
            [
                'type'       => 'object',
                'properties' => [],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('uid', 'title')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('title', 'ASC')
            ->setMaxResults(self::HARD_LIMIT)
            ->executeQuery()
            ->fetchAllAssociative();

        if ($rows === []) {
            return ToolResult::text('No backend groups.');
        }

        $lines = [sprintf('Backend groups (%d):', count($rows))];
        foreach ($rows as $row) {
            $lines[] = sprintf('[%d] %s', self::toInt($row['uid'] ?? 0), self::toStr($row['title'] ?? ''));
        }

        return ToolResult::text(implode("\n", $lines));
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function requiresAdmin(): bool
    {
        // Admin-only: exposes system / host / cross-user data a non-admin must never reach.
        return true;
    }

    public function getGroup(): string
    {
        return 'accounts';
    }

    /**
     * Backend group structure exposes the installation permission model.
     */
    public function getDataClass(): ToolDataClass
    {
        return ToolDataClass::SECRET_ADJACENT;
    }
}
