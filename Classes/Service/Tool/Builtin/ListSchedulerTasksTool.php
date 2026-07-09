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
use Throwable;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * List the scheduler task records (ADR-048).
 *
 * uid, description/type, next execution (UTC), disabled flag and a
 * last-run-failed indicator per task — enough to answer "why did the nightly
 * import not run".
 *
 * Security contract (see {@see ToolInterface}): admin-only. EXT:scheduler is
 * optional: an absent `tx_scheduler_task` table degrades to a plain message.
 * The `serialized_task_object` blob is NEVER unserialized (unserializing a
 * DB-supplied object graph is an object-injection primitive) — only the plain
 * columns are read, and only the columns that exist in the installed
 * scheduler version (the column set changed between 13.4 and 14).
 */
final readonly class ListSchedulerTasksTool implements ToolInterface
{
    use SafeCastTrait;

    private const NOT_INSTALLED = 'Scheduler is not installed.';

    private const TABLE = 'tx_scheduler_task';

    /** Columns rendered when the installed scheduler version provides them. */
    private const OPTIONAL_COLUMNS = ['tasktype', 'description', 'nextexecution', 'lastexecution_time', 'lastexecution_failure', 'disable'];

    private const MAX_TASKS = 50;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'list_scheduler_tasks',
            'List the scheduler tasks: uid, description/type, next execution time, disabled flag and '
            . 'whether the last run failed. Degrades gracefully when EXT:scheduler is not installed.',
            [
                'type'       => 'object',
                'properties' => [],
            ],
        );
    }

    public function execute(array $arguments): string
    {
        try {
            $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
            $available  = array_keys($connection->createSchemaManager()->listTableColumns(self::TABLE));
        } catch (Throwable) {
            return self::NOT_INSTALLED;
        }
        if ($available === []) {
            return self::NOT_INSTALLED;
        }

        $select = array_values(array_intersect(
            array_merge(['uid'], self::OPTIONAL_COLUMNS),
            array_map(static fn(string|int $c): string => strtolower((string)$c), $available),
        ));

        try {
            $queryBuilder = $connection->createQueryBuilder();
            $queryBuilder->getRestrictions()->removeAll();
            $rows = $queryBuilder
                ->select(...$select)
                ->from(self::TABLE)
                ->orderBy('uid')
                ->setMaxResults(self::MAX_TASKS)
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (Throwable) {
            return self::NOT_INSTALLED;
        }

        if ($rows === []) {
            return 'No scheduler tasks configured.';
        }

        $lines = [];
        foreach ($rows as $row) {
            $label = self::toStr($row['description'] ?? '');
            if ($label === '') {
                $label = self::toStr($row['tasktype'] ?? '') ?: '(no description)';
            }
            $label = mb_strimwidth(trim((string)preg_replace('/\s+/', ' ', $label)), 0, 100, '…');

            $next = self::toInt($row['nextexecution'] ?? 0);
            // The failure column carries an error string; '' / '0' / NULL all
            // mean "no failure" (the DB default is numeric on some versions).
            $failure = trim(self::toStr($row['lastexecution_failure'] ?? ''));
            $failed  = $failure !== '' && $failure !== '0';
            $flags   = [];
            $flags[] = $next > 0 ? 'next ' . gmdate('Y-m-d H:i', $next) . ' UTC' : 'no next execution';
            $lastRun = self::toInt($row['lastexecution_time'] ?? 0);
            if ($lastRun > 0) {
                $flags[] = 'last run ' . gmdate('Y-m-d H:i', $lastRun) . ' UTC';
            }
            if (self::toInt($row['disable'] ?? 0) === 1) {
                $flags[] = 'DISABLED';
            }
            if ($failed) {
                $flags[] = 'LAST RUN FAILED';
            }

            $lines[] = sprintf('- [%d] %s (%s)', self::toInt($row['uid'] ?? 0), $label, implode(', ', $flags));
        }

        return sprintf("Scheduler tasks (%d, capped at %d):\n", count($lines), self::MAX_TASKS)
            . implode("\n", $lines);
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function requiresAdmin(): bool
    {
        // Admin-only: task inventory reveals automation internals.
        return true;
    }

    public function getGroup(): string
    {
        return 'system';
    }
}
