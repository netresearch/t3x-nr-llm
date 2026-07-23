<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use FilesystemIterator;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\SourcePathGuard;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Grep over the project's source files (ADR-044).
 *
 * Pure-PHP directory walk (no shell-out) under the project root, restricted
 * to source extensions and pruned by {@see SourcePathGuard}'s denylists —
 * vendor/, node_modules/, var/ and dot-directories are never descended
 * into, and denied files (settings.php, key material, …) are skipped. The
 * pattern is a LITERAL substring unless `regex` is set; matched lines are
 * secret-redacted. A hard file/time budget bounds a model-steered call.
 */
final readonly class SearchCodeTool implements ToolInterface
{
    use SafeCastTrait;

    private const DEFAULT_RESULTS = 30;

    private const MAX_RESULTS = 100;

    private const MAX_FILES = 20000;

    private const TIME_BUDGET_SECONDS = 5.0;

    private const MAX_LINE_LENGTH = 200;

    public function __construct(
        private SourcePathGuard $guard,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'search_code',
            'Search the project source files for a literal substring (or a regular expression with '
            . 'regex=true). Returns path:line hits. Vendor, var and dot directories are skipped.',
            [
                'type'       => 'object',
                'properties' => [
                    'pattern' => [
                        'type'        => 'string',
                        'description' => 'Literal substring to find (case-sensitive). With regex=true: a PCRE pattern body without delimiters.',
                    ],
                    'regex' => [
                        'type'        => 'boolean',
                        'description' => 'Treat pattern as a regular expression (default false = literal substring).',
                    ],
                    'path' => [
                        'type'        => 'string',
                        'description' => 'Optional subdirectory (relative to the project root) to restrict the search to.',
                    ],
                    'max_results' => [
                        'type'        => 'integer',
                        'description' => 'Maximum hits to return (default 30, cap 100).',
                    ],
                ],
                'required' => ['pattern'],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $pattern = self::toStr($arguments['pattern'] ?? '');
        if ($pattern === '') {
            return ToolResult::text('Error: "pattern" is required.');
        }

        $isRegex = (bool)($arguments['regex'] ?? false);
        $regex   = null;
        if ($isRegex) {
            $regex = '~' . str_replace('~', '\~', $pattern) . '~';
            // preg_match() signals an invalid pattern with a warning + false;
            // a scoped no-op handler keeps the probe silent without "@".
            set_error_handler(static fn(): bool => true);
            try {
                $patternIsValid = preg_match($regex, '') !== false;
            } finally {
                restore_error_handler();
            }
            if (!$patternIsValid) {
                return ToolResult::text(sprintf('Error: invalid regular expression "%s".', $pattern));
            }
        }

        $maxResults = self::toInt($arguments['max_results'] ?? self::DEFAULT_RESULTS);
        if ($maxResults < 1) {
            $maxResults = self::DEFAULT_RESULTS;
        }
        $maxResults = min($maxResults, self::MAX_RESULTS);

        $root = $this->guard->rootPath();
        if ($root === null) {
            return ToolResult::text('Error: project root not resolvable.');
        }

        $base    = $root;
        $subPath = trim(self::toStr($arguments['path'] ?? ''), '/');
        if ($subPath !== '') {
            $candidate = realpath($root . '/' . $subPath);
            if ($candidate === false || !is_dir($candidate) || !str_starts_with($candidate . '/', $root . '/')) {
                return ToolResult::text(sprintf('Denied or not found: search path "%s".', $subPath));
            }
            if ($this->guard->isDeniedRelativePath(substr($candidate, strlen($root) + 1) . '/x')) {
                return ToolResult::text(sprintf('Denied: search path "%s".', $subPath));
            }
            $base = $candidate;
        }

        $started      = microtime(true);
        $filesVisited = 0;
        $hits         = [];
        $truncated    = '';

        foreach ($this->sourceFiles($base) as $file) {
            $filesVisited++;
            if ($filesVisited > self::MAX_FILES || microtime(true) - $started > self::TIME_BUDGET_SECONDS) {
                $truncated = sprintf(
                    '… search stopped early (budget: %d files / %.0fs) — narrow the path to search further.',
                    self::MAX_FILES,
                    self::TIME_BUDGET_SECONDS,
                );
                break;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            if ($this->guard->isDeniedRelativePath($relative)) {
                continue;
            }

            if (!$file->isReadable()) {
                continue;
            }
            $content = file_get_contents($file->getPathname(), false, null, 0, 2_000_000);
            if ($content === false) {
                continue;
            }
            if ($regex === null && !str_contains($content, $pattern)) {
                continue;
            }

            foreach (explode("\n", $content) as $lineNumber => $line) {
                $matches = $regex !== null
                    ? preg_match($regex, $line) === 1
                    : str_contains($line, $pattern);
                if (!$matches) {
                    continue;
                }

                $trimmed = trim($this->guard->redactSecretLine($line));
                if (mb_strlen($trimmed) > self::MAX_LINE_LENGTH) {
                    $trimmed = mb_substr($trimmed, 0, self::MAX_LINE_LENGTH) . '…';
                }
                $hits[] = sprintf('%s:%d: %s', $relative, $lineNumber + 1, $trimmed);

                if (count($hits) >= $maxResults) {
                    break 2;
                }
            }
        }

        if ($hits === []) {
            return ToolResult::text($truncated !== ''
                ? "No matches found before the budget ran out.\n" . $truncated
                : sprintf('No matches for "%s".', $pattern));
        }

        $header = sprintf('%d match(es) for "%s"%s:', count($hits), $pattern, count($hits) >= $maxResults ? ' (capped)' : '');
        $out    = array_merge([$header], $hits);
        if ($truncated !== '') {
            $out[] = $truncated;
        }

        return ToolResult::text(implode("\n", $out));
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function requiresAdmin(): bool
    {
        // Admin-only: exposes host source code.
        return true;
    }

    public function getGroup(): string
    {
        return 'code';
    }

    /**
     * Source files under $base, pruned by directory denylist and extension
     * allowlist.
     *
     * @return iterable<SplFileInfo>
     */
    private function sourceFiles(string $base): iterable
    {
        $directoryIterator = new RecursiveDirectoryIterator(
            $base,
            FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO,
        );

        $filtered = new RecursiveCallbackFilterIterator(
            $directoryIterator,
            static function (SplFileInfo $current): bool {
                $name = $current->getFilename();
                if (str_starts_with($name, '.')) {
                    return false;
                }
                if ($current->isDir()) {
                    return !in_array($name, SourcePathGuard::SKIPPED_DIRECTORIES, true);
                }

                return in_array(strtolower($current->getExtension()), SourcePathGuard::SOURCE_EXTENSIONS, true);
            },
        );

        /** @var iterable<SplFileInfo> */
        return new RecursiveIteratorIterator($filtered);
    }
}
