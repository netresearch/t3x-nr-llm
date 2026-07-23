<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Example tool: return the most recent TYPO3 system log entries.
 *
 * Reads `sys_log` newest-first, bounded by a model-supplied `limit` that is
 * hard-capped at {@see self::HARD_LIMIT}, with an optional PSR `level`
 * filter.
 *
 * Security contract (see {@see ToolInterface}): the formatted result
 * egresses to an external LLM provider, so every personally-identifying or
 * payload field is redacted by omission. Only the timestamp, type/action
 * codes, the level and the `details` *template* are surfaced — the raw
 * client `IP`, the backend `userid` and the serialized `log_data` payload
 * (which may carry usernames or further PII substituted into the template)
 * are never read into the output.
 */
final readonly class FetchLogsTool implements ToolInterface
{
    use SafeCastTrait;

    private const TABLE = 'sys_log';

    /**
     * Maximum rows returned regardless of the requested limit. Bounds the
     * egress volume from a single model-steered call.
     */
    private const HARD_LIMIT = 50;

    private const DEFAULT_LIMIT = 20;

    public function __construct(
        protected ConnectionPool $connectionPool,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'fetch_logs',
            'Return the most recent TYPO3 system log (sys_log) entries, newest first. '
            . 'Personally-identifying fields (client IP, backend user id, payload) are redacted.',
            [
                'type'       => 'object',
                'properties' => [
                    'level' => [
                        'type'        => 'string',
                        'description' => 'Optional PSR log level to filter by (e.g. "info", "error", "warning").',
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'description' => 'Maximum number of entries to return (default 20, hard cap 50).',
                    ],
                ],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $limit = self::toInt($arguments['limit'] ?? self::DEFAULT_LIMIT);
        if ($limit < 1) {
            $limit = self::DEFAULT_LIMIT;
        }
        $limit = min($limit, self::HARD_LIMIT);

        $level = self::toStr($arguments['level'] ?? '');

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder
            ->select('tstamp', 'type', 'action', 'level', 'details')
            ->from(self::TABLE)
            ->orderBy('tstamp', 'DESC')
            ->setMaxResults($limit);

        if ($level !== '') {
            $queryBuilder->where(
                $queryBuilder->expr()->eq(
                    'level',
                    $queryBuilder->createNamedParameter($level),
                ),
            );
        }

        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();

        if ($rows === []) {
            return ToolResult::text('No log entries.');
        }

        $lines = [sprintf('Recent sys_log entries (showing %d):', count($rows))];
        foreach ($rows as $row) {
            $lines[] = sprintf(
                '[%s] %d.%d %s: %s',
                gmdate('Y-m-d H:i', self::toInt($row['tstamp'] ?? 0)),
                self::toInt($row['type'] ?? 0),
                self::toInt($row['action'] ?? 0),
                self::toStr($row['level'] ?? ''),
                self::toStr($row['details'] ?? ''),
            );
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
        return 'system';
    }
}
