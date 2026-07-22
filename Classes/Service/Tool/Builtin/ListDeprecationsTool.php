<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Core\Environment;

/**
 * Tail the TYPO3 deprecation log (ADR-048).
 *
 * The newest distinct deprecation messages from the file-based deprecation
 * channel (`var/log/typo3_deprecations_*.log`) — the work list for an
 * upgrade. Identical messages are deduplicated with a ×count suffix.
 *
 * Security contract (see {@see ToolInterface}): admin-only; absolute project
 * paths inside messages are rewritten to project-relative form before the
 * egress, output is bounded (never the raw file), and a disabled channel /
 * absent file degrades to a plain message.
 */
final readonly class ListDeprecationsTool implements ToolInterface
{
    use SafeCastTrait;

    private const NO_LOG = 'No deprecation log found (the deprecation channel may be disabled).';

    private const DEFAULT_LIMIT = 15;

    private const MAX_LIMIT = 40;

    /** Read at most this many bytes from the file tail. */
    private const TAIL_BYTES = 262144;

    private const MESSAGE_WIDTH = 300;

    public function __construct(
        private ?string $logDirectory = null,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'list_deprecations',
            'List the newest distinct messages from the TYPO3 deprecation log — the upgrade work list. '
            . 'Duplicates are collapsed with a ×count suffix.',
            [
                'type'       => 'object',
                'properties' => [
                    'limit' => [
                        'type'        => 'integer',
                        'description' => 'Maximum distinct messages (default 15, capped at 40).',
                    ],
                ],
            ],
        );
    }

    public function execute(array $arguments): ToolResult
    {
        $limit = self::toInt($arguments['limit'] ?? self::DEFAULT_LIMIT);
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $file = $this->newestLogFile();
        if ($file === null) {
            return ToolResult::text(self::NO_LOG);
        }

        $tail = $this->tail($file);
        if ($tail === '') {
            return ToolResult::text(self::NO_LOG);
        }

        // Newest last in the file → iterate reversed so "newest first" wins.
        $counts = [];
        foreach (array_reverse(explode("\n", $tail)) as $line) {
            $message = $this->normalizeLine($line);
            if ($message === '') {
                continue;
            }
            $counts[$message] = ($counts[$message] ?? 0) + 1;
        }
        if ($counts === []) {
            return ToolResult::text(self::NO_LOG);
        }

        $total  = count($counts);
        $counts = array_slice($counts, 0, $limit, true);

        $lines = [];
        foreach ($counts as $message => $count) {
            $lines[] = sprintf('- %s%s', $message, $count > 1 ? sprintf(' (×%d)', $count) : '');
        }

        return ToolResult::text(sprintf(
            "Deprecations (%d distinct%s, newest first):\n",
            $total,
            $total > $limit ? sprintf(', showing %d', $limit) : '',
        ) . implode("\n", $lines));
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function requiresAdmin(): bool
    {
        // Admin-only: log content reveals code paths and versions.
        return true;
    }

    public function getGroup(): string
    {
        return 'system';
    }

    private function newestLogFile(): ?string
    {
        $dir   = $this->logDirectory ?? Environment::getVarPath() . '/log';
        $files = glob($dir . '/typo3_deprecations_*.log');
        if (!is_array($files) || $files === []) {
            return null;
        }

        usort($files, static fn(string $a, string $b): int => (int)filemtime($b) <=> (int)filemtime($a));

        return $files[0];
    }

    private function tail(string $file): string
    {
        if (!is_file($file) || !is_readable($file)) {
            return '';
        }
        $size = (int)filesize($file);
        if ($size < 1) {
            return '';
        }
        $handle = fopen($file, 'r');
        if ($handle === false) {
            return '';
        }
        try {
            if ($size > self::TAIL_BYTES) {
                fseek($handle, -self::TAIL_BYTES, SEEK_END);
                // Drop the (likely partial) first line of the window.
                fgets($handle);
            }
            $content = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        return is_string($content) ? $content : '';
    }

    /**
     * Strip the timestamp/level prefix, relativize project paths and cap the
     * width, so identical deprecations from different requests deduplicate.
     */
    private function normalizeLine(string $line): string
    {
        $line = trim($line);
        if ($line === '') {
            return '';
        }

        // Typical FileWriter line: "Tue, 08 Jul 2026 12:34:56 +0000 [NOTICE] request="…" component="…": message"
        $position = strpos($line, ': ');
        if ($position !== false && preg_match('/^\S{3}, \d{2} /', $line) === 1) {
            $line = substr($line, $position + 2);
        }

        $line = str_replace(Environment::getProjectPath() . '/', '', $line);
        $line = trim((string)preg_replace('/\s+/', ' ', $line));

        return mb_strimwidth($line, 0, self::MESSAGE_WIDTH, '…');
    }
}
