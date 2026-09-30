<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Web;

use GuzzleHttp\ProxyOptions;
use GuzzleHttp\Utils;

/**
 * Whether a request would leave through an HTTP proxy (ADR-202).
 *
 * Mirrors how nr-vault's client and the Guzzle transport under it pick a
 * proxy: `$GLOBALS['TYPO3_CONF_VARS']['HTTP']['proxy']` when it decides (a
 * string for every scheme, or an array keyed by scheme with an optional `no`
 * list), otherwise the environment as described at
 * {@see self::environmentProxyApplies()}.
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
        // An empty string counts as unset, as nr-vault's `!empty()` reads it.
        if (is_string($configured) && $configured !== '') {
            return true;
        }

        if (is_array($configured) && $configured !== []) {
            $proxy = $configured[$scheme] ?? null;
            if ($proxy !== null) {
                // A malformed entry is not a reason to guess no.
                return !is_string($proxy) || $proxy === '' || !$this->excluded($host, $configured['no'] ?? []);
            }

            // No entry for this scheme: the option decides nothing, and the
            // transport falls back to the environment below.
        }

        return $this->environmentProxyApplies($scheme, $host);
    }

    /**
     * The environment as any layer under nr-vault's client reads it.
     *
     * nr-vault turns `HTTPS_PROXY`/`https_proxy`, and on the CLI
     * `HTTP_PROXY`/`http_proxy`, into the proxy option. When that option
     * decides nothing for the scheme, the transport reads the environment
     * itself: Guzzle 8 (`GuzzleHttp\Handler\ProxyEnv`) and libcurl under
     * Guzzle 7 take lowercase `http_proxy` under every SAPI, then `all_proxy`
     * and `ALL_PROXY`. Uppercase `HTTP_PROXY` outside the CLI is read by none
     * of them (a web server fills it from the `Proxy:` request header).
     */
    private function environmentProxyApplies(string $scheme, string $host): bool
    {
        $candidates = $scheme === 'https'
            ? ['HTTPS_PROXY', 'https_proxy']
            : [...(($this->sapi ?? PHP_SAPI) === 'cli' ? ['HTTP_PROXY'] : []), 'http_proxy'];

        $proxy = null;
        foreach ([...$candidates, 'all_proxy', 'ALL_PROXY'] as $name) {
            $proxy ??= $this->env($name);
        }

        if ($proxy === null) {
            return false;
        }

        // nr-vault reads NO_PROXY first, the transport no_proxy first; a host
        // counts as excluded only when every list that is set excludes it.
        $lists = array_filter([$this->env('NO_PROXY'), $this->env('no_proxy')], static fn(?string $list): bool => $list !== null);
        if ($lists === []) {
            return true;
        }

        foreach ($lists as $list) {
            if (!$this->excluded($host, explode(',', $list))) {
                return true;
            }
        }

        return false;
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

        if ($list === []) {
            return false;
        }

        // Guzzle 8 moved the matcher from `Utils` to `ProxyOptions`, same
        // signature; the one that exists is the one the transport uses.
        // PHPStan sees only the installed major, so one of the two calls is
        // unknown to it; Build/phpstan/phpstan.neon lets exactly that pass.
        if (class_exists(ProxyOptions::class)) {
            return ProxyOptions::isHostInNoProxy($host, $list) === true;
        }

        return Utils::isHostInNoProxy($host, $list) === true;
    }

    private function env(string $name): ?string
    {
        $value = $this->environment !== null ? ($this->environment[$name] ?? false) : getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
