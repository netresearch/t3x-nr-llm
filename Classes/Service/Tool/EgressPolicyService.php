<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolEgressScope;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use Psr\Http\Message\UriInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Central, declarative network-egress gate keyed by tool group (ADR-061).
 *
 * Egress is governed per tool *group* (ADR-043) and is fail-closed: a group
 * with no entry in {@see GROUP_SCOPES} resolves to {@see ToolEgressScope::NONE}
 * and may make no outbound request at all.
 *
 * Two positive scopes exist. {@see ToolEgressScope::OWN_SITE} resolves the
 * instance's own configured site hosts through {@see SiteFinder} — the
 * allow-listing `probe_url` previously hard-coded, lifted to the group boundary.
 * {@see ToolEgressScope::CONFIGURED_ENDPOINT} covers an operator-declared
 * service host that is not a site base, which is what the `rag` group's search
 * backend actually talks to.
 *
 * What this is and is not (ADR-093): an audit and consistency gate, not a new
 * confidentiality boundary. The endpoints it validates are supplied by the site
 * configuration, never by the model. Its value is that the invariant this class
 * documents is now checkable in code instead of merely asserted — until ADR-093
 * the `rag` group was listed here as egress-free while its search backend was
 * issuing HTTP requests.
 */
final readonly class EgressPolicyService
{
    use SafeCastTrait;

    /**
     * Declared egress scope per tool group. Absent group => NONE (fail-closed).
     *
     * `system` carries `probe_url`, the one built-in that fetches over the
     * network, and is limited to the instance's own sites. `rag` reaches the
     * search backend the site configuration declares. Every other group
     * (`content`, `structure`, `configuration`, `code`, `files`, `accounts`)
     * reads local state only and is denied egress.
     *
     * @var array<string, ToolEgressScope>
     */
    private const GROUP_SCOPES = [
        'system' => ToolEgressScope::OWN_SITE,
        'rag'    => ToolEgressScope::CONFIGURED_ENDPOINT,
    ];

    public function __construct(
        private SiteFinder $siteFinder,
    ) {}

    /**
     * The declared egress scope for a group (fail-closed to NONE when absent).
     */
    public function scopeFor(string $group): ToolEgressScope
    {
        return self::GROUP_SCOPES[$group] ?? ToolEgressScope::NONE;
    }

    /**
     * Resolve a model-supplied URL/path to an absolute URL a tool of `$group`
     * is permitted to request, or null when any gate denies it.
     *
     * Fail-closed layering:
     * 1. The group's scope must permit egress at all (NONE => always null).
     * 2. For OWN_SITE: a leading-slash path resolves against the first site
     *    base; an absolute URL must be http(s), carry no userinfo, and match an
     *    own-site host:port exactly (scheme-defaulted ports on both sides, so a
     *    rogue port on the right host is rejected).
     */
    public function resolveAllowedUrl(string $group, string $input): ?string
    {
        if (!$this->scopeFor($group)->permitsEgress()) {
            return null;
        }

        // Only OWN_SITE is a positive scope today; guard explicitly so a future
        // scope cannot fall through to the own-site logic by accident.
        if ($this->scopeFor($group) !== ToolEgressScope::OWN_SITE) {
            return null;
        }

        if (str_starts_with($input, '/') && !str_starts_with($input, '//')) {
            $base = $this->firstSiteBase();
            if ($base === null) {
                return null;
            }

            return rtrim($base, '/') . $input;
        }

        $parts = parse_url($input);
        if (!is_array($parts)) {
            return null;
        }
        // Reject userinfo (user:pass@host): the URL may be echoed back into the
        // tool output, so credentials must never reach the LLM or the logs.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $scheme = strtolower(self::toStr($parts['scheme'] ?? ''));
        $host   = strtolower(self::toStr($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        // Exact host:port match with scheme-defaulted ports on BOTH sides — a
        // bare-host match would let http://localhost:6379/ (Redis, …) through.
        $port     = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
        $hostPort = $host . ':' . $port;

        return in_array($hostPort, $this->allowedHosts(), true) ? $input : null;
    }

    /**
     * Resolve a URL a tool of `$group` may send to an operator-declared service
     * endpoint, or null when any gate denies it.
     *
     * The declared hosts come from the site configuration (the search backend's
     * configured host and port), so this validates the extension's own request
     * assembly rather than model input. It applies exactly the checks
     * {@see self::resolveAllowedUrl()} applies to an own-site URL: http(s) only,
     * no userinfo — the URL can be echoed into tool output or a log — and an
     * exact host:port match with scheme-defaulted ports on both sides.
     *
     * @param list<string> $declaredHosts host[:port] values the operator configured
     */
    public function resolveConfiguredEndpoint(string $group, string $url, array $declaredHosts): ?string
    {
        if ($this->scopeFor($group) !== ToolEgressScope::CONFIGURED_ENDPOINT) {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower(self::toStr($parts['scheme'] ?? ''));
        $host   = strtolower(self::toStr($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $port     = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
        $hostPort = $host . ':' . $port;

        $normalised = [];
        foreach ($declaredHosts as $declared) {
            $normalised[] = $this->normaliseHostPort(strtolower($declared), $scheme);
        }

        return in_array($hostPort, $normalised, true) ? $url : null;
    }

    /**
     * A declared `host` or `host:port` in the same `host:port` shape the request
     * is compared in, defaulting the port from the request's scheme.
     */
    private function normaliseHostPort(string $declared, string $scheme): string
    {
        if (str_contains($declared, ':')) {
            return $declared;
        }

        return $declared . ':' . ($scheme === 'https' ? '443' : '80');
    }

    /**
     * host[:port] values of every site base and base variant, for OWN_SITE
     * matching and for a tool's denial message.
     *
     * @return list<string>
     */
    public function allowedHosts(): array
    {
        $hosts = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            foreach ($this->siteBases($site) as $base) {
                $host = strtolower($base->getHost());
                if ($host === '') {
                    continue;
                }
                $scheme  = strtolower($base->getScheme() ?: 'http');
                $port    = $base->getPort() ?? ($scheme === 'https' ? 443 : 80);
                $hosts[] = $host . ':' . $port;
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * @return list<UriInterface>
     */
    private function siteBases(Site $site): array
    {
        // Only the site's ACTIVE resolved base is a legitimate probe_url target.
        // baseVariants are context/condition-resolved (a local/staging variant may
        // point at an internal host, e.g. http://web:80); folding them all into the
        // egress allowlist unconditionally would widen it to hosts the instance
        // does not actually serve.
        return [$site->getBase()];
    }

    private function firstSiteBase(): ?string
    {
        foreach ($this->siteFinder->getAllSites() as $site) {
            $base = (string)$site->getBase();
            if ($base !== '' && $site->getBase()->getHost() !== '') {
                return $base;
            }
        }

        return null;
    }
}
