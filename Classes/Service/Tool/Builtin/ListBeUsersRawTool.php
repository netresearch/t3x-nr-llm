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
 * List the backend users (`be_users`) with ALL non-credential columns.
 *
 * Unlike {@see ListBeUsersTool} this returns the full profile row (TSconfig,
 * group memberships, file mounts, language, …) for deep introspection.
 *
 * Security contract (see {@see ToolInterface}): even here the credential
 * columns are stripped — `password` (a crackable hash) and `mfa` (TOTP secrets
 * and recovery codes) are removed from every row before formatting. Leaking a
 * backend password hash or an MFA seed to an external LLM provider is a real
 * security hole, not "raw data". The remaining columns can still expose the
 * backend account layout, so this tool is {@see isEnabledByDefault()} = false:
 * an admin must deliberately enable it in the Tool Playground module. Values
 * are truncated to {@see self::MAX_VALUE_LENGTH} and rows capped at
 * {@see self::HARD_LIMIT} to bound the egress.
 */
final readonly class ListBeUsersRawTool implements ToolInterface
{
    use SafeCastTrait;

    private const TABLE = 'be_users';

    private const HARD_LIMIT = 100;

    private const MAX_VALUE_LENGTH = 200;

    /**
     * Credential-bearing columns stripped from every row regardless of the
     * "raw" framing — never egress these to the provider.
     *
     * @var list<string>
     */
    private const CREDENTIAL_COLUMNS = ['password', 'mfa'];

    public function __construct(
        protected ConnectionPool $connectionPool,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'list_be_users_raw',
            'List backend users with all non-credential columns (full profile rows). '
            . 'Password hashes and MFA secrets are still excluded. Disabled by default — admin must enable it.',
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
            ->select('*')
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

        $blocks = [];
        foreach ($rows as $row) {
            foreach (self::CREDENTIAL_COLUMNS as $column) {
                unset($row[$column]);
            }

            $pairs = [];
            foreach ($row as $key => $value) {
                $pairs[] = sprintf('  %s: %s', (string)$key, $this->truncate(self::toStr($value)));
            }
            $blocks[] = sprintf('[%d]', self::toInt($row['uid'] ?? 0)) . "\n" . implode("\n", $pairs);
        }

        return sprintf("Backend users (%d):\n", count($rows)) . implode("\n", $blocks);
    }

    public function isEnabledByDefault(): bool
    {
        return false;
    }

    public function requiresAdmin(): bool
    {
        // Admin-only: exposes system / host / cross-user data a non-admin must never reach.
        return true;
    }

    private function truncate(string $value): string
    {
        if (mb_strlen($value) <= self::MAX_VALUE_LENGTH) {
            return $value;
        }

        return mb_substr($value, 0, self::MAX_VALUE_LENGTH) . '…';
    }
}
