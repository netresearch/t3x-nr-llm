<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Task;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Reads the tail of TYPO3's deprecation log for the Task
 * "deprecation log" input source.
 *
 * Returns localized placeholder strings when the log is missing or
 * unreadable (the existing controller behaviour) so the caller can
 * surface the message verbatim. No exceptions are thrown — the input
 * source is best-effort.
 */
final readonly class DeprecationLogReader implements DeprecationLogReaderInterface
{
    private const DEFAULT_LOG_PATH = 'var/log/typo3_deprecations.log';

    private const TAIL_LINE_COUNT = 100;

    public function readTail(int $maxLines = self::TAIL_LINE_COUNT): string
    {
        $logFile = GeneralUtility::getFileAbsFileName(self::DEFAULT_LOG_PATH);
        if (!file_exists($logFile)) {
            return LocalizationUtility::translate(
                'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:task.deprecationLog.notFound',
                'NrLlm',
            ) ?? 'No deprecation log file found.';
        }

        $content = file_get_contents($logFile);
        if ($content === false) {
            return LocalizationUtility::translate(
                'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:task.deprecationLog.readError',
                'NrLlm',
            ) ?? 'Could not read deprecation log.';
        }

        $lines = explode("\n", $content);
        $lines = array_slice($lines, -$maxLines);

        return implode("\n", $lines);
    }
}
