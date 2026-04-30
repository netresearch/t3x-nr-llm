<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Task;

use Netresearch\NrLlm\Domain\Model\Task;
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
 * private helper exactly — this slice is a pure refactor.
 */
final readonly class TaskInputResolver implements TaskInputResolverInterface
{
    private const SYSLOG_DEFAULT_LIMIT = 50;
    private const TABLE_DEFAULT_LIMIT = 50;

    public function __construct(
        private SystemLogReaderInterface $systemLogReader,
        private DeprecationLogReaderInterface $deprecationLogReader,
        private RecordTableReaderInterface $recordTableReader,
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

        $rows = $this->systemLogReader->readRecent($limit, $errorOnly);

        $output = [];
        foreach ($rows as $row) {
            $tstamp     = isset($row['tstamp']) && is_numeric($row['tstamp']) ? (int)$row['tstamp'] : 0;
            $typeValue  = isset($row['type']) && is_numeric($row['type']) ? (int)$row['type'] : 0;
            $errorValue = isset($row['error']) && is_numeric($row['error']) ? (int)$row['error'] : 0;
            $details    = isset($row['details']) && is_scalar($row['details']) ? (string)$row['details'] : '';

            $time  = date('Y-m-d H:i:s', $tstamp);
            $type  = $this->translateSyslogType($typeValue);
            $error = $errorValue > 0
                ? (LocalizationUtility::translate('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:task.syslog.errorMarker', 'NrLlm') ?? '[ERROR]')
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

        return LocalizationUtility::translate(
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:' . $key,
            'NrLlm',
        ) ?? $fallback;
    }

    private function resolveTable(Task $task): string
    {
        $config = $task->getInputSourceArray();
        $table  = isset($config['table']) && is_scalar($config['table']) ? (string)$config['table'] : '';
        $limit  = isset($config['limit']) && is_numeric($config['limit']) ? (int)$config['limit'] : self::TABLE_DEFAULT_LIMIT;

        if ($table === '') {
            return LocalizationUtility::translate(
                'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:task.table.notConfigured',
                'NrLlm',
            ) ?? 'No table configured.';
        }

        try {
            $rows = $this->recordTableReader->fetchAll($table, $limit);
        } catch (Throwable $e) {
            return sprintf(
                LocalizationUtility::translate(
                    'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:task.table.readError',
                    'NrLlm',
                ) ?? 'Error reading table: %s',
                $e->getMessage(),
            );
        }

        return json_encode($rows, JSON_PRETTY_PRINT) ?: '[]';
    }
}
