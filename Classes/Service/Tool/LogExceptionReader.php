<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\LogExceptionEntry;
use TYPO3\CMS\Core\Core\Environment;

/**
 * Parses error-level entries out of TYPO3's file logs (ADR-044).
 *
 * Shared by `get_last_exception` (browse/inspect) and `probe_url`
 * (correlate a just-probed 5xx with the exception it produced) — one
 * parser, two consumers, no copy-paste.
 *
 * TYPO3's FileWriter emits one line per record:
 *
 *     Tue, 08 Jul 2026 01:13:18 +0200 [ERROR] request="..."
 *     component="Vendor.Ext.Class": message - {"exception":"Class: msg in
 *     /file:12\nStack trace:\n#0 /file(12): call()..."}
 *
 * Only the tail of each log file is read (bounded I/O — log files grow
 * unbounded), newest files first.
 */
final readonly class LogExceptionReader
{
    /**
     * How many bytes of each log file's tail are parsed. Errors older than
     * this window are out of scope for interactive debugging.
     */
    private const TAIL_BYTES = 2_000_000;

    private const ERROR_LEVELS = ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    private const ENTRY_START_PATTERN
        = '/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun), \d{1,2} [A-Z][a-z]{2} \d{4} /';

    private const HEADER_PATTERN
        = '/^(?<date>\w{3}, \d{1,2} \w{3} \d{4} \d{2}:\d{2}:\d{2} [+-]\d{4}) '
        . '\[(?<level>[A-Z]+)\](?: request="[^"]*")?'
        . '(?: component="(?<component>[^"]*)")?: (?<rest>.*)$/s';

    private const FRAME_PATTERN
        = '/#\d+ (?<file>\/[^(:\n]+)\((?<line>\d+)\): (?<call>[^\n]+)/';

    public function __construct(
        private ?string $logDirectory = null,
    ) {}

    /**
     * Error-level entries, newest first.
     *
     * @param string|null $search  case-insensitive substring filter on
     *                             message / component / exception class
     * @param int|null    $sinceTs only entries at/after this timestamp
     * @param int|null    $untilTs only entries at/before this timestamp
     *
     * @return list<LogExceptionEntry>
     */
    public function read(int $limit = 20, ?string $search = null, ?int $sinceTs = null, ?int $untilTs = null): array
    {
        $entries = [];
        foreach ($this->logFiles() as $file) {
            foreach ($this->parseFile($file) as $entry) {
                if ($sinceTs !== null && $entry->timestamp < $sinceTs) {
                    continue;
                }
                if ($untilTs !== null && $entry->timestamp > $untilTs) {
                    continue;
                }
                if ($search !== null && $search !== '' && !$this->matchesSearch($entry, $search)) {
                    continue;
                }
                $entries[] = $entry;
            }
        }

        usort($entries, static fn(LogExceptionEntry $a, LogExceptionEntry $b): int => $b->timestamp <=> $a->timestamp);

        return array_slice($entries, 0, max(1, $limit));
    }

    /**
     * @return list<string> log file paths, newest modification first
     */
    private function logFiles(): array
    {
        $dir   = $this->logDirectory ?? Environment::getVarPath() . '/log';
        $files = glob($dir . '/typo3_*.log');
        if ($files === false || $files === []) {
            return [];
        }

        usort($files, static fn(string $a, string $b): int => (int)filemtime($b) <=> (int)filemtime($a));

        return $files;
    }

    /**
     * @return list<LogExceptionEntry>
     */
    private function parseFile(string $file): array
    {
        if (!is_readable($file)) {
            return [];
        }
        $size   = (int)filesize($file);
        $offset = max(0, $size - self::TAIL_BYTES);
        $raw    = file_get_contents($file, false, null, $offset);
        if ($raw === false || $raw === '') {
            return [];
        }

        // Group physical lines into logical records: a record starts with the
        // RFC-2822-ish date; anything else continues the previous record
        // (messages may carry real newlines).
        $records = [];
        $current = null;
        foreach (explode("\n", $raw) as $line) {
            if (preg_match(self::ENTRY_START_PATTERN, $line) === 1) {
                if ($current !== null) {
                    $records[] = $current;
                }
                $current = $line;
                continue;
            }
            if ($current !== null) {
                $current .= "\n" . $line;
            }
        }
        if ($current !== null) {
            $records[] = $current;
        }

        $entries = [];
        foreach ($records as $record) {
            $entry = $this->parseRecord($record);
            if ($entry instanceof LogExceptionEntry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private function parseRecord(string $record): ?LogExceptionEntry
    {
        if (preg_match(self::HEADER_PATTERN, $record, $m) !== 1) {
            return null;
        }

        $level = $m['level'];
        if (!in_array($level, self::ERROR_LEVELS, true)) {
            return null;
        }

        $timestamp = strtotime($m['date']);
        if ($timestamp === false) {
            return null;
        }

        [$message, $exceptionText] = $this->splitDataSuffix($m['rest']);

        $exceptionClass = null;
        $frames         = [];
        $trace          = $exceptionText ?? $message;

        if (preg_match('/(?<class>[A-Za-z_\\\\][\w\\\\]*(?:Exception|Error)\b[\w\\\\]*)\s*:/', $trace, $cm) === 1) {
            $exceptionClass = $cm['class'];
        }

        // The throw site ("in /file.php:12") leads the frame list so the
        // most relevant source location comes first.
        if (preg_match('/ in (?<file>\/[^\s:]+):(?<line>\d+)/', $trace, $tm) === 1) {
            $frames[] = ['file' => $tm['file'], 'line' => (int)$tm['line'], 'call' => '(throw site)'];
        }

        if (preg_match_all(self::FRAME_PATTERN, $trace, $fm, PREG_SET_ORDER) > 0) {
            foreach ($fm as $frame) {
                $frames[] = [
                    'file' => $frame['file'],
                    'line' => (int)$frame['line'],
                    'call' => trim($frame['call']),
                ];
            }
        }

        return new LogExceptionEntry(
            timestamp: $timestamp,
            level: $level,
            component: $m['component'],
            message: trim($message),
            exceptionClass: $exceptionClass,
            frames: $frames,
        );
    }

    /**
     * Split the FileWriter's trailing ` - {json}` data suffix off the
     * message and surface a decoded `exception` string when present.
     *
     * @return array{0: string, 1: string|null} [message, exception text]
     */
    private function splitDataSuffix(string $rest): array
    {
        $pos = strrpos($rest, ' - {');
        if ($pos === false) {
            return [$rest, null];
        }

        $candidate = substr($rest, $pos + 3);
        $decoded   = json_decode($candidate, true);
        if (!is_array($decoded)) {
            return [$rest, null];
        }

        $exception = $decoded['exception'] ?? null;

        return [substr($rest, 0, $pos), is_string($exception) ? $exception : null];
    }

    private function matchesSearch(LogExceptionEntry $entry, string $search): bool
    {
        return stripos($entry->message, $search) !== false
            || stripos($entry->component, $search) !== false
            || ($entry->exceptionClass !== null && stripos($entry->exceptionClass, $search) !== false);
    }
}
