<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;

/**
 * Return a curated, non-sensitive subset of the PHP runtime configuration.
 *
 * Security contract (see {@see ToolInterface}): a deliberate allow-list — the
 * PHP version, the loaded extension names and a handful of harmless ini
 * settings ({@see self::SAFE_INI_KEYS}). It does NOT dump full phpinfo(),
 * `$_SERVER` or `$_ENV`, all of which can carry secrets; the unbounded variant
 * is the separate, default-disabled {@see GetPhpInfoRawTool}.
 */
final readonly class GetPhpInfoTool implements ToolInterface
{
    use SafeCastTrait;

    /**
     * Non-sensitive ini settings safe to surface to the provider.
     *
     * @var list<string>
     */
    private const SAFE_INI_KEYS = [
        'memory_limit',
        'max_execution_time',
        'post_max_size',
        'upload_max_filesize',
        'date.timezone',
    ];

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'get_php_info',
            'Return a curated, non-sensitive subset of the PHP runtime: version, loaded extensions and a few '
            . 'harmless ini settings (memory_limit, max_execution_time, post_max_size, upload_max_filesize, date.timezone).',
            [
                'type'       => 'object',
                'properties' => [],
            ],
        );
    }

    public function execute(array $arguments): string
    {
        $extensions = get_loaded_extensions();
        sort($extensions);

        $lines   = [];
        $lines[] = 'PHP version: ' . PHP_VERSION;
        $lines[] = sprintf('Loaded extensions (%d): %s', count($extensions), implode(', ', $extensions));
        foreach (self::SAFE_INI_KEYS as $key) {
            $lines[] = $key . ' = ' . self::toStr(ini_get($key));
        }

        return implode("\n", $lines);
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function requiresAdmin(): bool
    {
        // Admin-only: exposes system / host / cross-user data a non-admin must never reach.
        return true;
    }

    public function getGroup(): string
    {
        return 'system';
    }
}
