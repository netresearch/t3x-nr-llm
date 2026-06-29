<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * List the backend users (`be_users`) with non-credential profile columns.
 *
 * Security contract (see {@see ToolInterface}): the column list is an explicit
 * allow-list — uid, username, realName, email, admin flag, disable flag and
 * lastlogin. The `password` column (a crackable credential hash, not
 * information) and the `mfa` column (TOTP secrets / recovery codes) are NEVER
 * selected, so they cannot egress to the external provider. Soft-deleted users
 * are excluded and the row count is hard-capped at {@see self::HARD_LIMIT}.
 */
final readonly class ListBeUsersTool implements ToolInterface
{
    use SafeCastTrait;

    private const TABLE = 'be_users';

    private const HARD_LIMIT = 200;

    public function __construct(
        protected ConnectionPool $connectionPool,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'list_be_users',
            'List backend users (uid, username, realName, email, admin flag, disabled flag, last login). '
            . 'Password hashes and MFA secrets are never included.',
            [
                'type'       => 'object',
                'properties' => [],
            ],
        );
    }

    public function execute(array $arguments): string
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            // Explicit allow-list — the password and mfa columns are never read.
            ->select('uid', 'username', 'realName', 'email', 'admin', 'disable', 'lastlogin')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('uid', 'ASC')
            ->setMaxResults(self::HARD_LIMIT)
            ->executeQuery()
            ->fetchAllAssociative();

        if ($rows === []) {
            return 'No backend users.';
        }

        $lines = [sprintf('Backend users (%d):', count($rows))];
        foreach ($rows as $row) {
            $lastlogin = self::toInt($row['lastlogin'] ?? 0);
            $lines[]   = sprintf(
                '[%d] %s | %s <%s> | admin=%d disabled=%d | lastlogin=%s',
                self::toInt($row['uid'] ?? 0),
                self::toStr($row['username'] ?? ''),
                self::toStr($row['realName'] ?? ''),
                self::toStr($row['email'] ?? ''),
                self::toInt($row['admin'] ?? 0),
                self::toInt($row['disable'] ?? 0),
                $lastlogin > 0 ? gmdate('Y-m-d H:i', $lastlogin) : 'never',
            );
        }

        return implode("\n", $lines);
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }
}
