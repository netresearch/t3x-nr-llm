<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Web;

use GuzzleHttp\Handler\ProxyEnvironment;
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
     * @param array<string, string>|null $environment        test seam for getenv($name), the SAPI view first; null reads it
     * @param array<string, string>|null $processEnvironment test seam for getenv($name, true), the process view; null
     *                                                       reads it, or repeats $environment when that is given
     */
    public function __construct(
        private ?array $environment = null,
        private ?string $sapi = null,
        private ?array $processEnvironment = null,
    ) {}

    public function appliesTo(string $scheme, string $host): bool
    {
        $scheme = strtolower($scheme);

        $confVars   = is_array($GLOBALS['TYPO3_CONF_VARS'] ?? null) ? $GLOBALS['TYPO3_CONF_VARS'] : [];
        $http       = is_array($confVars['HTTP'] ?? null) ? $confVars['HTTP'] : [];
        $configured = $http['proxy'] ?? null;

        // An empty value counts as unset, as nr-vault's `!empty()` reads it.
        if (empty($configured)) {
            return $this->environmentProxyApplies($scheme, $host);
        }

        if (is_string($configured)) {
            return true;
        }

        // nr-vault drops a value that is neither a string nor an array with a
        // string `http`, `https` or `no` entry, and then Guzzle's own client
        // defaults read the environment by rules of their own. Not a reason
        // to guess no.
        if (!is_array($configured) || !$this->narrowable($configured)) {
            return true;
        }

        $proxy = $configured[$scheme] ?? null;
        if ($proxy === null) {
            // No entry for this scheme: the option decides nothing, and the
            // transport falls back to the environment.
            return $this->environmentProxyApplies($scheme, $host);
        }

        // A malformed entry is not a reason to guess no either.
        if (!is_string($proxy) || $proxy === '' || !$this->excluded($host, $configured['no'] ?? [])) {
            return true;
        }

        // Excluded by the `no` list. Guzzle 7.12 and later, and Guzzle 8,
        // treat that as final; before 7.12 the curl handler only unset
        // CURLOPT_PROXY, and libcurl then read the environment.
        return $this->guzzleResolvesTheEnvironment() ? false : $this->environmentProxyApplies($scheme, $host);
    }

    /**
     * The environment as any layer under nr-vault's client reads it.
     *
     * nr-vault turns `HTTPS_PROXY`/`https_proxy`, and on the CLI
     * `HTTP_PROXY`/`http_proxy`, into the proxy option. When that option
     * decides nothing for the scheme, the transport reads the environment
     * itself (Guzzle 8's `ProxyEnv`, Guzzle 7.12+'s `ProxyEnvironment`,
     * libcurl before that): lowercase `http_proxy` under every SAPI, then
     * `all_proxy` and `ALL_PROXY`. Uppercase `HTTP_PROXY` outside the CLI is
     * read by none of them (a web server fills it from the `Proxy:` request
     * header).
     */
    private function environmentProxyApplies(string $scheme, string $host): bool
    {
        $candidates = $scheme === 'https'
            ? ['HTTPS_PROXY', 'https_proxy']
            : [...(($this->sapi ?? PHP_SAPI) === 'cli' ? ['HTTP_PROXY'] : []), 'http_proxy'];

        $proxied = false;
        foreach ([...$candidates, 'all_proxy', 'ALL_PROXY'] as $name) {
            $proxied = $proxied || $this->env($name) !== [];
        }

        if (!$proxied) {
            return false;
        }

        // The transport's own fallback reads the process environment only,
        // so a no-proxy list that the SAPI alone sets never reaches it; without
        // a list there the proxy applies. Where lists are set, nr-vault reads
        // NO_PROXY first and the transport no_proxy first, so a host counts as
        // excluded only when every set list, in either view, excludes it.
        if ($this->processEnv('NO_PROXY') === null && $this->processEnv('no_proxy') === null) {
            return true;
        }

        $lists = [...$this->env('NO_PROXY'), ...$this->env('no_proxy')];

        foreach ($lists as $list) {
            if (!$this->excluded($host, explode(',', $list))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether nr-vault's narrowProxy() keeps the configured array.
     *
     * @param array<mixed> $configured
     */
    private function narrowable(array $configured): bool
    {
        if (is_string($configured['http'] ?? null) || is_string($configured['https'] ?? null)) {
            return true;
        }

        $no = $configured['no'] ?? null;

        return is_string($no) || (is_array($no) && array_filter($no, is_string(...)) !== []);
    }

    /**
     * Guzzle 7.12 added its own environment lookup (`ProxyEnvironment`),
     * Guzzle 8 renamed it (`ProxyEnv`) and moved the matcher to `ProxyOptions`.
     */
    private function guzzleResolvesTheEnvironment(): bool
    {
        return class_exists(ProxyOptions::class) || class_exists(ProxyEnvironment::class);
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

    /**
     * Every non-empty value of a variable in either view. nr-vault reads
     * getenv($name), which asks the SAPI first (fastcgi_param, SetEnv); Guzzle
     * 7.12+ and 8 read getenv($name, true), the process environment only.
     *
     * @return list<string>
     */
    private function env(string $name): array
    {
        $sapiView = $this->environment !== null ? ($this->environment[$name] ?? false) : getenv($name);

        return array_values(array_unique(array_filter(
            [$sapiView, $this->processEnv($name)],
            static fn(mixed $value): bool => is_string($value) && $value !== '',
        )));
    }

    /**
     * The non-empty value of a variable in the process environment, as the transport reads it.
     */
    private function processEnv(string $name): ?string
    {
        $value = match (true) {
            $this->processEnvironment !== null => $this->processEnvironment[$name] ?? false,
            $this->environment !== null        => $this->environment[$name] ?? false,
            default                            => getenv($name, true),
        };

        return is_string($value) && $value !== '' ? $value : null;
    }
}
