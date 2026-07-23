<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\SourcePathGuard;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;

/**
 * Line-ranged source-file read for debugging (ADR-044).
 *
 * Every access funnels through {@see SourcePathGuard}: the resolved real
 * path must stay inside the project root (defeats `../` and symlink
 * escapes), dotfiles, `var/*` (except `var/log`), `config/system/*`,
 * `settings.php`/`additional.php`, key material and "credential" paths are
 * denied outright, and credential-looking assignment lines have their value
 * redacted. Admin-only on top.
 */
final readonly class ReadSourceTool implements ToolInterface
{
    use SafeCastTrait;

    private const DEFAULT_LINES = 60;

    private const MAX_LINES = 200;

    public function __construct(
        private SourcePathGuard $guard,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'read_source',
            'Read a line range of a source file inside the TYPO3 project (path relative to the project '
            . 'root). Output is line-numbered. Dotfiles, settings/credentials and key material are '
            . 'not readable.',
            [
                'type'       => 'object',
                'properties' => [
                    'path' => [
                        'type'        => 'string',
                        'description' => 'File path relative to the project root, e.g. "vendor/acme/ext/Classes/Service/Foo.php".',
                    ],
                    'from_line' => [
                        'type'        => 'integer',
                        'description' => 'First line to return, 1-based (default 1).',
                    ],
                    'lines' => [
                        'type'        => 'integer',
                        'description' => 'How many lines to return (default 60, max 200).',
                    ],
                ],
                'required' => ['path'],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $path = trim(self::toStr($arguments['path'] ?? ''));
        if ($path === '') {
            return ToolResult::text('Error: "path" is required.');
        }

        $fromLine = max(1, self::toInt($arguments['from_line'] ?? 1));
        $lines    = self::toInt($arguments['lines'] ?? self::DEFAULT_LINES);
        if ($lines < 1) {
            $lines = self::DEFAULT_LINES;
        }
        $lines = min($lines, self::MAX_LINES);

        $read = $this->guard->readLines($path, $fromLine, $lines);
        if ($read === null) {
            return ToolResult::text(sprintf(
                'Denied or not found: "%s". Paths must resolve inside the project root; dotfiles, '
                . 'var/* (except var/log), config/system, settings/additional.php, key material and '
                . 'credential paths are not readable.',
                $path,
            ));
        }

        $out   = [];
        $out[] = sprintf('%s (lines %d-%d of %d):', $path, $fromLine, min($fromLine + $lines - 1, $read['total']), $read['total']);
        foreach ($read['lines'] as $number => $content) {
            $out[] = sprintf('%5d | %s', $number, rtrim($content));
        }

        if ($read['lines'] === []) {
            $out[] = '(range is beyond the end of the file)';
        }

        return ToolResult::text(implode("\n", $out));
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function requiresAdmin(): bool
    {
        // Admin-only: reads host source code.
        return true;
    }

    public function getGroup(): string
    {
        return 'code';
    }
}
