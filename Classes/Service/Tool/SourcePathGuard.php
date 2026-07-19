<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use TYPO3\CMS\Core\Core\Environment;

/**
 * Fail-closed path gate for the source-reading tools (ADR-044).
 *
 * Model-chosen paths are attacker-influenceable, so every read the
 * error-analysis tools perform funnels through this guard:
 *
 * - the resolved real path must stay inside the project root (realpath
 *   containment defeats `../` traversal AND symlink escapes);
 * - no path segment may start with a dot (`.env`, `.git`, `.ddev`, …);
 * - `var/*` is denied except `var/log` (sessions and caches can carry
 *   serialized credentials, the logs are exactly what debugging needs);
 * - `config/system/*` and any `settings.php` / `additional.php` are denied
 *   (DB credentials), as are key-material extensions and any path
 *   mentioning "credential";
 * - reads are line-ranged and secret-redacting — a matching key on a line
 *   has its value replaced, never the raw secret egressed.
 */
final readonly class SourcePathGuard
{
    /**
     * File extensions the code-walking tools consider source. Everything
     * else (binaries, images, archives, sqlite files, …) is skipped.
     */
    public const SOURCE_EXTENSIONS = [
        'php', 'html', 'typoscript', 'tsconfig', 'yaml', 'yml',
        'xml', 'xlf', 'json', 'md',
    ];

    /** Directory names never descended into when walking the tree. */
    public const SKIPPED_DIRECTORIES = ['vendor', 'node_modules', 'var'];

    private const DENIED_EXTENSIONS = ['key', 'pem', 'crt', 'p12', 'pfx'];

    // settings/additional.php (modern config/system) AND the legacy
    // LocalConfiguration.php / AdditionalConfiguration.php (typo3conf, still valid
    // on upgraded/non-composer v13.4 instances) hold TYPO3 secrets; auth.json
    // holds Composer registry/OAuth credentials — none may be read by read_source.
    private const DENIED_BASENAME_PATTERN
        = '/^(?:settings|additional)\.php$|^(?:local|additional)configuration\.php$|^auth\.json$/i';

    /** Lines whose key looks credential-bearing get their value replaced. */
    private const SECRET_LINE_PATTERN
        = '/^(?<lead>.*?(?:password|passwd|pwd|secret|token|salt|api[_-]?key|access[_-]?key|encryption[_-]?key|license[_-]?key|credential|private[_-]?key|authorization|dsn)[\'"\s\]]*\s*(?:=>?|:)\s*)(?<value>.+)$/i';

    public function __construct(
        private ?string $projectPath = null,
    ) {}

    /**
     * Resolve a model-supplied (relative or absolute) path to a real path
     * inside the project, or null when any gate denies it.
     */
    public function resolve(string $path): ?string
    {
        $root = $this->rootPath();
        if ($root === null || trim($path) === '') {
            return null;
        }

        $candidate = str_starts_with($path, '/')
            ? $path
            : $root . '/' . $path;

        $real = realpath($candidate);
        if ($real === false || !is_file($real)) {
            return null;
        }

        // Containment check defeats `../` traversal and symlink escapes in
        // one step: whatever the input, the RESOLVED file must live under
        // the resolved project root.
        if ($real !== $root && !str_starts_with($real, $root . '/')) {
            return null;
        }

        return $this->isDeniedRelativePath(substr($real, strlen($root) + 1)) ? null : $real;
    }

    /**
     * Whether a project-relative path hits any denylist (dot segments,
     * var/* except var/log, config/system, settings/additional.php, key
     * material, "credential" mentions).
     */
    public function isDeniedRelativePath(string $relativePath): bool
    {
        if (stripos($relativePath, 'credential') !== false) {
            return true;
        }

        $segments = explode('/', $relativePath);
        foreach ($segments as $segment) {
            if ($segment !== '' && str_starts_with($segment, '.')) {
                return true;
            }
        }

        if ($segments[0] === 'var' && ($segments[1] ?? '') !== 'log') {
            return true;
        }

        if ($segments[0] === 'config' && ($segments[1] ?? '') === 'system') {
            return true;
        }

        $basename = $segments[count($segments) - 1];
        if (preg_match(self::DENIED_BASENAME_PATTERN, $basename) === 1) {
            return true;
        }

        $extension = strtolower(pathinfo($basename, PATHINFO_EXTENSION));

        return in_array($extension, self::DENIED_EXTENSIONS, true);
    }

    /**
     * Read a line range from a guarded file, secret-redacted and
     * 1-indexed. Null when the path is denied or unreadable.
     *
     * @return array{lines: array<int, string>, total: int}|null keyed by line number
     */
    public function readLines(string $path, int $fromLine, int $count): ?array
    {
        $real = $this->resolve($path);
        if ($real === null) {
            return null;
        }

        if (!is_readable($real)) {
            return null;
        }
        $content = file_get_contents($real, false, null, 0, 2_000_000);
        if ($content === false) {
            return null;
        }

        $all      = explode("\n", $content);
        $total    = count($all);
        $fromLine = max(1, $fromLine);
        $count    = max(1, $count);

        $slice = array_slice($all, $fromLine - 1, $count, true);
        $lines = [];
        foreach ($slice as $index => $line) {
            $lines[$index + 1] = $this->redactSecretLine($line);
        }

        return ['lines' => $lines, 'total' => $total];
    }

    /**
     * Replace the value part of a credential-looking assignment line.
     */
    public function redactSecretLine(string $line): string
    {
        $out  = preg_replace(self::SECRET_LINE_PATTERN, '$1[redacted]', $line);
        $line = is_string($out) ? $out : $line;
        // Also mask token-shaped secrets that carry NO credential key — the shape
        // logs emit (var/log is readable): JSON `"Authorization":"Bearer …"`
        // headers, bearer / sk- tokens, and connection-string URLs in free-form or
        // structured lines that the key-based pattern above never matches.
        $out = preg_replace(
            [
                '/\bBearer\s+[A-Za-z0-9._~+\/\-]+=*/i',
                '/\bsk-[A-Za-z0-9_\-]{16,}/',
                '~(\b[a-z][a-z0-9+.\-]*://[^:/?#\s@]*):[^@/?#\s]+@~i',
            ],
            ['Bearer [redacted]', 'sk-[redacted]', '$1:***@'],
            $line,
        );

        return is_string($out) ? $out : $line;
    }

    /**
     * The resolved project root (injectable for tests).
     */
    public function rootPath(): ?string
    {
        $root = $this->projectPath ?? Environment::getProjectPath();
        $real = realpath($root);

        return $real === false ? null : $real;
    }
}
