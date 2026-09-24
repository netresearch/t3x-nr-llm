<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Web;

use GuzzleHttp\Utils;

/**
 * Whether a request would leave through an HTTP proxy (ADR-202).
 *
 * Mirrors how nr-vault's client picks its proxy: `$GLOBALS['TYPO3_CONF_VARS']['HTTP']['proxy']`
 * when set (a string for every scheme, or an array keyed by scheme with an
 * optional `no` list), otherwise the environment — `HTTPS_PROXY` always,
 * `HTTP_PROXY` only on the CLI (PHP does not trust it under a web server),
 * with `NO_PROXY` as the exclusion list.
 *
 * Behind a proxy the proxy resolves the host, so the address pin of the guard
 * does not reach the connection that matters. When in doubt this answers yes.
 */
final readonly class ProxyDetector
{
    /**
     * @param array<string, string>|null $environment test seam; null reads getenv()
     */
    public function __construct(
        private ?array $environment = null,
        private ?string $sapi = null,
    ) {}

    public function appliesTo(string $scheme, string $host): bool
    {
        $scheme = strtolower($scheme);

        $confVars   = is_array($GLOBALS['TYPO3_CONF_VARS'] ?? null) ? $GLOBALS['TYPO3_CONF_VARS'] : [];
        $http       = is_array($confVars['HTTP'] ?? null) ? $confVars['HTTP'] : [];
        $configured = $http['proxy'] ?? null;
        if (is_string($configured)) {
            return $configured !== '';
        }

        if (is_array($configured) && $configured !== []) {
            $proxy = $configured[$scheme] ?? null;
            if (!is_string($proxy) || $proxy === '') {
                // An array without a usable entry for this scheme: Guzzle would
                // not proxy, but a malformed entry is not a reason to guess no.
                return $proxy !== null;
            }

            return !$this->excluded($host, $configured['no'] ?? []);
        }

        $proxy = $scheme === 'https'
            ? ($this->env('HTTPS_PROXY') ?? $this->env('https_proxy'))
            : (($this->sapi ?? PHP_SAPI) === 'cli' ? ($this->env('HTTP_PROXY') ?? $this->env('http_proxy')) : null);
        if ($proxy === null) {
            return false;
        }

        $noProxy = $this->env('NO_PROXY') ?? $this->env('no_proxy');

        return !$this->excluded($host, $noProxy === null ? [] : explode(',', $noProxy));
    }

    private function excluded(string $host, mixed $noProxy): bool
    {
        if (!is_array($noProxy)) {
            return false;
        }

        $list = array_values(array_filter(array_map(
            static fn(mixed $entry): string => is_string($entry) ? trim($entry) : '',
            $noProxy,
        ), static fn(string $entry): bool => $entry !== ''));

        return $list !== [] && Utils::isHostInNoProxy($host, $list);
    }

    private function env(string $name): ?string
    {
        $value = $this->environment !== null ? ($this->environment[$name] ?? false) : getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
