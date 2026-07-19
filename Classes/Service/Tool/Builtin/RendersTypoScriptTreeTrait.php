<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

/**
 * Shared rendering for TypoScript-style nested arrays (`key` values plus
 * `key.` subtrees, as produced by the TypoScript AST and by
 * BackendUtility::getPagesTSconfig()).
 *
 * Three concerns, shared by {@see GetTypoScriptTool} and
 * {@see GetTsConfigTool} (ADR-042):
 *
 * - **path drill-down**: a dotted path walks `key.` children so the model
 *   can request one subtree instead of the whole document;
 * - **output capping**: without a path only the top-level keys are listed,
 *   and any rendered subtree stops at a fixed line cap with an explicit
 *   truncation marker — a full setup dump would flood the provider egress;
 * - **secret redaction**: values whose key looks credential-bearing render
 *   as `[redacted]` for every user (TS constants routinely carry API keys).
 */
trait RendersTypoScriptTreeTrait
{
    /**
     * Credential-ish key segments whose values are redacted. Matched on the
     * last path segment, per camelCase/underscore word.
     */
    private const SECRET_KEY_PATTERN
        = '/(password|passwd|pwd|secret|token|credential|apikey|api_key|accesskey|access_key|encryptionkey|encryption_key|licensekey|privatekey|private_key|authorization|salt|dsn)/i';

    /**
     * Credentials embedded in a connection-string value (scheme://user:pass@host)
     * are masked regardless of the key name — a DSN under a benign key (e.g.
     * `settings.dsn`) would otherwise egress its inline password.
     */
    private const SECRET_URL_PATTERN = '~(\b[a-z][a-z0-9+.\-]*://[^:/?#\s@]*):[^@/?#\s]+@~i';

    /** Maximum rendered lines per reply. */
    private const MAX_LINES = 300;

    /**
     * Walk a dotted path through a TypoScript-style array. Returns
     * [valueAtPath, subtreeAtPath] — either may be null when absent.
     *
     * @param array<array-key, mixed> $tree
     *
     * @return array{0: string|null, 1: array<array-key, mixed>|null}
     */
    private function drillPath(array $tree, string $path): array
    {
        $node     = $tree;
        $segments = explode('.', trim($path, " \t."));

        foreach ($segments as $index => $segment) {
            $isLast  = $index === count($segments) - 1;
            $value   = $node[$segment] ?? null;
            $subtree = $node[$segment . '.'] ?? null;
            $subtree = is_array($subtree) ? $subtree : null;

            if ($isLast) {
                return [
                    is_scalar($value) ? (string)$value : null,
                    $subtree,
                ];
            }

            if ($subtree === null) {
                return [null, null];
            }
            $node = $subtree;
        }

        return [null, null];
    }

    /**
     * Render a TypoScript-style array as indented `key = value` lines,
     * recursing into `key.` subtrees, capped at {@see self::MAX_LINES}.
     *
     * @param array<array-key, mixed> $tree
     * @param list<string>            $lines
     */
    private function renderTree(array $tree, array &$lines, int $level = 0): void
    {
        foreach ($tree as $key => $value) {
            if (count($lines) >= self::MAX_LINES) {
                $lines[] = sprintf('… [output truncated at %d lines]', self::MAX_LINES);

                return;
            }

            $key = (string)$key;
            if (str_ends_with($key, '.')) {
                if (!is_array($value)) {
                    continue;
                }
                $lines[] = str_repeat('  ', $level) . rtrim($key, '.') . ' {';
                $this->renderTree($value, $lines, $level + 1);
                if (end($lines) !== sprintf('… [output truncated at %d lines]', self::MAX_LINES)) {
                    $lines[] = str_repeat('  ', $level) . '}';
                }

                continue;
            }

            if (!is_scalar($value)) {
                continue;
            }

            $lines[] = str_repeat('  ', $level) . sprintf(
                '%s = %s',
                $key,
                $this->redactSecretValue($key, (string)$value),
            );
        }
    }

    /**
     * List only the top-level keys of a tree (the no-path overview), marking
     * branch keys with a child count.
     *
     * @param array<array-key, mixed> $tree
     *
     * @return list<string>
     */
    private function renderTopLevelKeys(array $tree): array
    {
        $lines = [];
        foreach ($tree as $key => $value) {
            if (count($lines) >= self::MAX_LINES) {
                $lines[] = sprintf('… [output truncated at %d lines]', self::MAX_LINES);
                break;
            }

            $key = (string)$key;
            if (str_ends_with($key, '.') && is_array($value)) {
                $lines[] = sprintf('%s (+%d children)', rtrim($key, '.'), count($value));
            } elseif (is_scalar($value)) {
                $lines[] = sprintf('%s = %s', $key, $this->redactSecretValue($key, (string)$value));
            }
        }

        return $lines;
    }

    /**
     * The value, or `[redacted]` when its key looks credential-bearing.
     */
    private function redactSecretValue(string $key, string $value): string
    {
        if ($value === '') {
            return $value;
        }
        if (preg_match(self::SECRET_KEY_PATTERN, $key) === 1) {
            return '[redacted]';
        }
        // Mask an inline connection-string password even under a benign key name.
        $masked = preg_replace(self::SECRET_URL_PATTERN, '$1:***@', $value);

        return is_string($masked) ? $masked : $value;
    }
}
