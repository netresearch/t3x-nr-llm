<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Site configuration inspection (ADR-048).
 *
 * Without an identifier: one line per configured site (identifier, base,
 * root page). With one: the site's configuration flattened to dotted
 * `key: value` lines — the answer to "why does language 1 behave oddly" or
 * "which error handler is configured".
 *
 * Security contract (see {@see ToolInterface}): admin-only. Values whose key
 * segment matches the credential pattern of {@see TableReadAccessService}
 * (site settings routinely carry API keys) render as `[redacted]`; values
 * and the line count are capped, so a huge settings tree cannot flood the
 * egress.
 */
final readonly class GetSiteConfigTool implements ToolInterface
{
    use SafeCastTrait;

    private const MAX_LINES = 200;

    private const VALUE_WIDTH = 120;

    private const REDACTED = '[redacted]';

    public function __construct(
        private SiteFinder $siteFinder,
        private TableReadAccessService $tableAccess,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'get_site_config',
            'Show the site configuration: without arguments list all sites (identifier, base, root page); '
            . 'with "identifier" return that site\'s configuration as flat key: value lines '
            . '(credential-like values are redacted).',
            [
                'type'       => 'object',
                'properties' => [
                    'identifier' => [
                        'type'        => 'string',
                        'description' => 'Optional site identifier (from the identifier-less listing).',
                    ],
                ],
            ],
        );
    }

    public function execute(array $arguments): string
    {
        $identifier = trim(self::toStr($arguments['identifier'] ?? ''));

        if ($identifier === '') {
            $lines = [];
            foreach ($this->siteFinder->getAllSites() as $site) {
                $lines[] = sprintf(
                    '- %s (base %s, root page %d)',
                    $site->getIdentifier(),
                    (string)$site->getBase(),
                    $site->getRootPageId(),
                );
            }
            if ($lines === []) {
                return 'No sites configured.';
            }
            sort($lines);

            return sprintf(
                "Configured sites (%d) — pass \"identifier\" for a site's configuration:\n",
                count($lines),
            ) . implode("\n", $lines);
        }

        $sites = $this->siteFinder->getAllSites();
        $site  = $sites[$identifier] ?? null;
        if ($site === null) {
            return sprintf('No site "%s". Call without arguments to list the identifiers.', $identifier);
        }

        $lines = [];
        $this->flatten($site->getConfiguration(), '', $lines);

        $total = count($lines);
        if ($total > self::MAX_LINES) {
            $lines   = array_slice($lines, 0, self::MAX_LINES);
            $lines[] = sprintf('… %d more lines not shown', $total - self::MAX_LINES);
        }

        return sprintf("Site \"%s\" configuration (%d entries):\n", $identifier, $total)
            . implode("\n", $lines);
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function requiresAdmin(): bool
    {
        // Admin-only: site configuration is instance configuration.
        return true;
    }

    public function getGroup(): string
    {
        return 'configuration';
    }

    /**
     * Flatten the configuration array to dotted `key: value` lines; any key
     * SEGMENT matching the credential pattern redacts the whole subtree value.
     *
     * @param array<array-key, mixed> $config
     * @param list<string>            $lines
     */
    private function flatten(array $config, string $prefix, array &$lines): void
    {
        foreach ($config as $key => $value) {
            $keyName = (string)$key;
            $path    = $prefix === '' ? $keyName : $prefix . '.' . $keyName;

            // Normalize camelCase (apiKey → api_Key) AND acronym→word boundaries
            // (APIKey → API_Key) first — the credential pattern is snake_case-segment
            // based, site settings are often camelCase. Null-guard the split so a
            // preg failure falls back to the raw key (still checked) rather than an
            // empty string, which would bypass the credential check.
            $normalizedKey = preg_replace(
                ['/(?<=[a-z0-9])(?=[A-Z])/', '/(?<=[A-Z])(?=[A-Z][a-z])/'],
                '_',
                $keyName,
            );
            if ($this->tableAccess->isSensitiveField(is_string($normalizedKey) ? $normalizedKey : $keyName)) {
                $lines[] = sprintf('%s: %s', $path, self::REDACTED);
                continue;
            }

            if (is_array($value)) {
                if ($value === []) {
                    $lines[] = sprintf('%s: []', $path);
                    continue;
                }
                $this->flatten($value, $path, $lines);
                continue;
            }

            $lines[] = sprintf('%s: %s', $path, $this->renderValue($value));
        }
    }

    private function renderValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }

        $text = trim((string)preg_replace('/\s+/', ' ', self::toStr($value)));
        // Mask an inline connection-string password even under a benign key name
        // (e.g. a DSN whose key spelling escapes the credential-name match).
        $masked = preg_replace('~(\b[a-z][a-z0-9+.\-]*://[^:/?#\s@]*):[^@/?#\s]+@~i', '$1:***@', $text);
        if (is_string($masked)) {
            $text = $masked;
        }

        return mb_strimwidth($text, 0, self::VALUE_WIDTH, '…');
    }
}
