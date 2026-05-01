<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Task;

use InvalidArgumentException;
use Netresearch\NrLlm\Domain\Model\Task;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Resolves a `Task`'s configured input source into the input string
 * passed to the LLM.
 *
 * Owns the dispatch over `Task::INPUT_*` plus the per-source
 * formatting (timestamp + type-label localisation for syslog rows,
 * "no table configured" / "read failed" placeholders for the table
 * source). Delegates the actual data fetching to the slice-13a
 * reader services so the resolver itself stays free of
 * `ConnectionPool` / filesystem coupling.
 *
 * Behaviour matches the pre-13b `TaskController::getInputData()`
 * private helper exactly with one deliberate exception: REC #11b
 * (audit 2026-04-30) replaced the `$e->getMessage()` interpolation
 * in the two read-error arms with a generic "see system log"
 * message and a `LoggerInterface::warning()` call carrying the full
 * exception. The previous behaviour leaked DBAL error text (table
 * names, column hints, sometimes SQL fragments) into the LLM input
 * string and onward to the model and the user-visible task output;
 * the new behaviour preserves the operational signal in `sys_log`
 * where it belongs.
 */
final readonly class TaskInputResolver implements TaskInputResolverInterface
{
    private const SYSLOG_DEFAULT_LIMIT = 50;
    private const TABLE_DEFAULT_LIMIT = 50;

    public function __construct(
        private SystemLogReaderInterface $systemLogReader,
        private DeprecationLogReaderInterface $deprecationLogReader,
        private RecordTableReaderInterface $recordTableReader,
        private LoggerInterface $logger,
    ) {}

    public function resolve(Task $task): string
    {
        return match ($task->getInputType()) {
            Task::INPUT_SYSLOG          => $this->resolveSyslog($task),
            Task::INPUT_DEPRECATION_LOG => $this->deprecationLogReader->readTail(),
            Task::INPUT_TABLE           => $this->resolveTable($task),
            default                     => '',
        };
    }

    private function resolveSyslog(Task $task): string
    {
        $config    = $task->getInputSourceArray();
        $limit     = isset($config['limit']) && is_numeric($config['limit']) ? (int)$config['limit'] : self::SYSLOG_DEFAULT_LIMIT;
        $errorOnly = isset($config['error_only']) ? (bool)$config['error_only'] : true;

        try {
            $rows = $this->systemLogReader->readRecent($limit, $errorOnly);
        } catch (Throwable $e) {
            $this->logger->warning('Task syslog input: sys_log read failed', [
                'exception' => $e,
                'taskUid'   => $task->getUid(),
                'limit'     => $limit,
                'errorOnly' => $errorOnly,
            ]);

            return $this->translate(
                'task.syslog.readError',
                'Error reading sys_log. See system log for details.',
            );
        }

        $output = [];
        foreach ($rows as $row) {
            $tstamp     = isset($row['tstamp']) && is_numeric($row['tstamp']) ? (int)$row['tstamp'] : 0;
            $typeValue  = isset($row['type']) && is_numeric($row['type']) ? (int)$row['type'] : 0;
            $errorValue = isset($row['error']) && is_numeric($row['error']) ? (int)$row['error'] : 0;
            $details    = isset($row['details']) && is_scalar($row['details']) ? (string)$row['details'] : '';

            $time  = date('Y-m-d H:i:s', $tstamp);
            $type  = $this->translateSyslogType($typeValue);
            $error = $errorValue > 0
                ? $this->translate('task.syslog.errorMarker', '[ERROR]')
                : '';

            $output[] = "[{$time}] [{$type}] {$error} {$details}";
        }

        return implode("\n", $output);
    }

    private function translateSyslogType(int $typeValue): string
    {
        $key = match ($typeValue) {
            1       => 'task.syslog.type.db',
            2       => 'task.syslog.type.file',
            3       => 'task.syslog.type.cache',
            4       => 'task.syslog.type.extension',
            5       => 'task.syslog.type.error',
            254     => 'task.syslog.type.setting',
            255     => 'task.syslog.type.login',
            default => 'task.syslog.type.other',
        };

        $fallback = match ($typeValue) {
            1       => 'DB',
            2       => 'FILE',
            3       => 'CACHE',
            4       => 'EXTENSION',
            5       => 'ERROR',
            254     => 'SETTING',
            255     => 'LOGIN',
            default => 'OTHER',
        };

        return $this->translate($key, $fallback);
    }

    private function resolveTable(Task $task): string
    {
        $config = $task->getInputSourceArray();
        $table  = isset($config['table']) && is_scalar($config['table']) ? (string)$config['table'] : '';
        $limit  = isset($config['limit']) && is_numeric($config['limit']) ? (int)$config['limit'] : self::TABLE_DEFAULT_LIMIT;

        if ($table === '') {
            return $this->translate('task.table.notConfigured', 'No table configured.');
        }

        try {
            $rows = $this->recordTableReader->fetchAll($table, $limit);
        } catch (InvalidArgumentException $e) {
            // Table on the picker exclusion list. The exception text
            // describes the policy ("Table 'xyz' is not allowed for
            // record selection"); it doesn't carry user data and is
            // safe to surface, but we still log so an admin can see
            // the rejection in the system log.
            $this->logger->info('Task table input: table rejected by record-picker policy', [
                'exception' => $e,
                'taskUid'   => $task->getUid(),
                'table'     => $table,
            ]);

            return $this->translate(
                'task.table.readError',
                'Error reading table. See system log for details.',
            );
        } catch (Throwable $e) {
            $this->logger->warning('Task table input: table read failed', [
                'exception' => $e,
                'taskUid'   => $task->getUid(),
                'table'     => $table,
                'limit'     => $limit,
            ]);

            return $this->translate(
                'task.table.readError',
                'Error reading table. See system log for details.',
            );
        }

        return json_encode($rows, JSON_PRETTY_PRINT) ?: '[]';
    }

    /**
     * Translate a locallang key with a hardcoded English fallback.
     *
     * `LocalizationUtility::translate()` is the canonical translator,
     * but it can throw in unit-test contexts where the language
     * service hasn't been bootstrapped. Wrapping it lets callers stay
     * straight-line while preserving the production translation
     * behaviour and giving unit tests a deterministic fallback.
     */
    private function translate(string $key, string $fallback): string
    {
        try {
            $translated = LocalizationUtility::translate(
                'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:' . $key,
                'NrLlm',
            );
        } catch (Throwable) {
            return $fallback;
        }

        return $translated ?? $fallback;
    }
}
