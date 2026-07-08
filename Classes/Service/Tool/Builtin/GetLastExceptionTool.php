<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\LogExceptionEntry;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\LogExceptionReader;
use Netresearch\NrLlm\Service\Tool\SourcePathGuard;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\ErrorMessageSanitizerTrait;
use Netresearch\NrLlm\Utility\SafeCastTrait;

/**
 * The "why did that 500 / how do I fix this PHP error" tool (ADR-044).
 *
 * Surfaces the newest exception/error entries from TYPO3's file logs with
 * the parsed stack trace, and inlines ±{@see self::CONTEXT_LINES} lines of
 * source around each project-local frame so the model sees the failing
 * code without a second tool call. Vendor/core frames are listed but not
 * expanded (unless no project frame exists at all).
 *
 * Security contract: admin-only; source context flows through
 * {@see SourcePathGuard} (containment + denylists + secret-line
 * redaction); messages run through the URL-credential sanitizer; output is
 * line-capped.
 */
final readonly class GetLastExceptionTool implements ToolInterface
{
    use ErrorMessageSanitizerTrait;
    use SafeCastTrait;

    private const CONTEXT_LINES = 6;

    private const MAX_EXPANDED_FRAMES = 3;

    private const MAX_OUTPUT_LINES = 200;

    public function __construct(
        private LogExceptionReader $reader,
        private SourcePathGuard $guard,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'get_last_exception',
            'Return the most recent exception/error from the TYPO3 file logs with its parsed stack trace '
            . 'and the surrounding source code of the project frames. Use index to step back through '
            . 'older errors, search to filter by message/class/component.',
            [
                'type'       => 'object',
                'properties' => [
                    'index' => [
                        'type'        => 'integer',
                        'description' => 'Which error to show, 0 = newest (default), 1 = the one before, …',
                    ],
                    'search' => [
                        'type'        => 'string',
                        'description' => 'Optional case-insensitive filter on message, exception class or component.',
                    ],
                ],
            ],
        );
    }

    public function execute(array $arguments): string
    {
        $index  = max(0, self::toInt($arguments['index'] ?? 0));
        $search = trim(self::toStr($arguments['search'] ?? ''));

        $entries = $this->reader->read($index + 1, $search !== '' ? $search : null);
        $entry   = $entries[$index] ?? null;

        if (!$entry instanceof LogExceptionEntry) {
            return $entries === []
                ? 'No error-level entries found in the TYPO3 file logs. '
                  . 'For database-logged events try the fetch_logs tool (sys_log).'
                : sprintf('Only %d matching error(s) available — index %d is out of range.', count($entries), $index);
        }

        $lines   = [];
        $lines[] = sprintf(
            'Exception #%d (0 = newest)%s:',
            $index,
            $search !== '' ? sprintf(', filtered by "%s"', $search) : '',
        );
        $lines[] = sprintf(
            '[%s] %s %s',
            gmdate('Y-m-d H:i:s', $entry->timestamp) . ' UTC',
            $entry->level,
            $entry->component,
        );
        if ($entry->exceptionClass !== null) {
            $lines[] = 'Exception: ' . $entry->exceptionClass;
        }
        $lines[] = 'Message: ' . $this->sanitizeErrorMessage($entry->message);
        $lines[] = '';

        if ($entry->frames === []) {
            $lines[] = 'No stack trace recorded for this entry.';

            return implode("\n", $lines);
        }

        $lines[]  = 'Stack trace:';
        $expanded = 0;
        foreach ($entry->frames as $i => $frame) {
            $lines[] = sprintf('#%d %s:%d — %s', $i, $frame['file'], $frame['line'], $frame['call']);

            if ($expanded >= self::MAX_EXPANDED_FRAMES || !$this->isProjectFrame($frame['file'])) {
                continue;
            }

            $context = $this->sourceContext($frame['file'], $frame['line']);
            if ($context === []) {
                continue;
            }
            $expanded++;
            foreach ($context as $contextLine) {
                $lines[] = $contextLine;
            }

            if (count($lines) >= self::MAX_OUTPUT_LINES) {
                $lines[] = sprintf('… [output truncated at %d lines]', self::MAX_OUTPUT_LINES);
                break;
            }
        }

        // Fallback: nothing was project-local — expand the throw site anyway
        // so the model at least sees where it blew up.
        if ($expanded === 0 && isset($entry->frames[0])) {
            $context = $this->sourceContext($entry->frames[0]['file'], $entry->frames[0]['line']);
            foreach ($context as $contextLine) {
                $lines[] = $contextLine;
            }
        }

        return implode("\n", array_slice($lines, 0, self::MAX_OUTPUT_LINES));
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function requiresAdmin(): bool
    {
        // Admin-only: exposes host paths, source code and error internals.
        return true;
    }

    public function getGroup(): string
    {
        return 'code';
    }

    /**
     * A frame counts as project-local when it is inside the project root
     * but not under vendor/ (core and packages are rarely the fix site).
     */
    private function isProjectFrame(string $file): bool
    {
        $root = $this->guard->rootPath();
        if ($root === null || !str_starts_with($file, $root . '/')) {
            return false;
        }

        $relative = substr($file, strlen($root) + 1);

        return !str_starts_with($relative, 'vendor/');
    }

    /**
     * ±CONTEXT_LINES of guarded, secret-redacted source around a line.
     *
     * @return list<string>
     */
    private function sourceContext(string $file, int $line): array
    {
        $from = max(1, $line - self::CONTEXT_LINES);
        $read = $this->guard->readLines($file, $from, self::CONTEXT_LINES * 2 + 1);
        if ($read === null) {
            return [];
        }

        $out = [];
        foreach ($read['lines'] as $number => $content) {
            $out[] = sprintf('  %s%5d | %s', $number === $line ? '>' : ' ', $number, rtrim($content));
        }

        return $out;
    }
}
