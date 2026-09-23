<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Web;

use GuzzleHttp\Psr7\Uri;
use Netresearch\NrLlm\Domain\Enum\ToolEgressScope;
use Netresearch\NrLlm\Service\Tool\EgressPolicyService;
use Throwable;

/**
 * The SSRF gate for URLs a model asks to fetch from the internet (ADR-202).
 *
 * Every check runs for the first URL and again for every redirect target, in
 * this order, and the first failing one refuses:
 *
 * 1. The tool group's declared egress scope must be
 *    {@see ToolEgressScope::EXTERNAL_FILTERED} ({@see EgressPolicyService}).
 * 2. Scheme http or https, a host, no userinfo.
 * 3. The host is normalised (lower case, no trailing dot) and must then
 *    consist of ASCII hostname characters or be an IP literal.
 * 4. The host must not be one of this installation's own site hosts, base
 *    variants included — pages of the installation are `probe_url`'s.
 * 5. The operator's denylist (`tools.fetchExternalUrl.deniedHosts`).
 * 6. The operator's allowlist (`tools.fetchExternalUrl.allowedHosts`) — when
 *    it is non-empty, the host must be on it.
 * 7. The port must be the scheme's default unless an allowlist entry names
 *    exactly this host and port.
 * 8. No HTTP proxy may apply ({@see ProxyDetector}) unless the operator
 *    permitted it together with a non-empty allowlist: behind a proxy the
 *    address pin does not reach the connection.
 * 9. A numeric host in a legacy `inet_aton()` form is refused outright.
 * 10. An IP literal must be public; a hostname must resolve through DNS, and
 *     EVERY address it resolves to must be public and not on the denylist. The
 *     addresses travel with the verdict so the request is pinned to them
 *     ({@see ExternalFetchTarget::resolvePin()}).
 *
 * The lists only ever narrow. No entry on either list lets a private,
 * loopback, link-local or metadata address through: step 10 runs for an
 * allow-listed host exactly as for any other.
 */
final readonly class ExternalUrlGuard
{
    public function __construct(
        private EgressPolicyService $egressPolicy,
        private HostResolverInterface $resolver,
        private IpAddressClassifier $classifier,
        private ExternalFetchSettings $settings,
        private ProxyDetector $proxyDetector,
    ) {}

    public function check(string $group, string $url): ExternalFetchTarget
    {
        if ($this->egressPolicy->scopeFor($group) !== ToolEgressScope::EXTERNAL_FILTERED) {
            return ExternalFetchTarget::refused(sprintf('the tool group "%s" is not permitted to reach external hosts', $group));
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return ExternalFetchTarget::refused('the URL cannot be parsed');
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return ExternalFetchTarget::refused('only http and https URLs can be fetched');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return ExternalFetchTarget::refused('URLs with credentials (user:password@) are not fetched');
        }

        $host = $this->normaliseHost($parts['host'] ?? '');
        if ($host === null) {
            return ExternalFetchTarget::refused('the URL has no valid host name');
        }

        if (in_array($host, $this->egressPolicy->siteHostNames(), true)) {
            return ExternalFetchTarget::refused(sprintf('"%s" is a host of this installation; use probe_url or site_rag_query for its pages', $host));
        }

        $defaultPort = $scheme === 'https' ? 443 : 80;
        $port        = $parts['port'] ?? $defaultPort;

        $denied  = $this->settings->deniedHosts();
        $allowed = $this->settings->allowedHosts();
        if ($denied === null || $allowed === null) {
            return ExternalFetchTarget::refused('the host lists of this installation cannot be read');
        }

        foreach ($denied as $entry) {
            if ($entry->matchesHost($host)) {
                return ExternalFetchTarget::refused(sprintf('the host "%s" is on the denylist of this installation', $host));
            }
        }

        if ($allowed !== [] && !$this->isListed($host, $port, $defaultPort, $allowed)) {
            return ExternalFetchTarget::refused(sprintf('the host "%s" is not on the allowlist of this installation', $host));
        }

        if ($port !== $defaultPort && !$this->isListed($host, $port, $defaultPort, $allowed)) {
            return ExternalFetchTarget::refused(sprintf('port %d is not permitted; only the default port of http/https is fetched', $port));
        }

        if ($this->proxyDetector->appliesTo($scheme, $host) && !$this->settings->proxyPermitted()) {
            return ExternalFetchTarget::refused(
                'an HTTP proxy is configured for this request, and the address checks cannot reach past it; '
                . 'an administrator can permit fetching through the proxy together with a host allowlist',
            );
        }

        if ($this->classifier->isAmbiguousNumericHost($host)) {
            return ExternalFetchTarget::refused('numeric host forms other than a dotted-quad or IPv6 address are refused');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (!$this->classifier->isPublic($host)) {
                return ExternalFetchTarget::refused(sprintf('the address %s is private, loopback, link-local or reserved', $host));
            }

            return $this->allowedTarget($url, $host, $port, [$host]);
        }

        $addresses = $this->resolver->resolve($host);
        if ($addresses === []) {
            return ExternalFetchTarget::refused(sprintf('the host "%s" does not resolve in DNS', $host));
        }

        foreach ($addresses as $address) {
            if (!$this->classifier->isPublic($address)) {
                return ExternalFetchTarget::refused(sprintf('the host "%s" resolves to a private, loopback, link-local or reserved address', $host));
            }

            foreach ($denied as $entry) {
                if ($entry->matchesAddress($address)) {
                    return ExternalFetchTarget::refused(sprintf('the host "%s" resolves to an address on the denylist of this installation', $host));
                }
            }
        }

        return $this->allowedTarget($url, $host, $port, $addresses);
    }

    /**
     * The verdict for a URL that passed every check, carrying the URL in the
     * form that will be sent: the checked host written back in its normalised
     * form, the fragment dropped. Guzzle parses the URL on its own; when it
     * reads a different host than the checks did, the URL is refused rather
     * than sent to a host nobody checked.
     *
     * @param list<string> $addresses
     */
    private function allowedTarget(string $url, string $host, int $port, array $addresses): ExternalFetchTarget
    {
        try {
            $uri = new Uri($url);
        } catch (Throwable) {
            return ExternalFetchTarget::refused('the URL cannot be parsed');
        }

        if (rtrim(trim(strtolower($uri->getHost()), '[]'), '.') !== $host) {
            return ExternalFetchTarget::refused('the URL is ambiguous; its host reads differently to different parsers');
        }

        $sent = $uri
            ->withHost(str_contains($host, ':') ? '[' . $host . ']' : $host)
            ->withFragment('');

        return ExternalFetchTarget::allowed((string)$sent, $host, $port, $addresses);
    }

    /**
     * Lower-cased, trailing dot removed, IPv6 brackets removed; null when what
     * is left is neither an IP literal nor a sequence of ASCII hostname
     * characters. The character check keeps a host that PHP's parser and
     * curl's parser would read differently (a backslash, a percent sign, a
     * space) from reaching either. An internationalised name is refused rather
     * than converted: the connection pin is keyed by the host string curl
     * sees, and a converted name would not match it. The model can pass the
     * punycode form (`xn--…`) instead.
     */
    private function normaliseHost(string $host): ?string
    {
        $host = strtolower($host);
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $inner = substr($host, 1, -1);

            return filter_var($inner, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? $inner : null;
        }

        $host = rtrim($host, '.');
        if ($host === '') {
            return null;
        }

        return preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $host) === 1 ? $host : null;
    }

    /**
     * @param list<HostListEntry> $entries
     */
    private function isListed(string $host, int $port, int $defaultPort, array $entries): bool
    {
        foreach ($entries as $entry) {
            if ($entry->matchesHost($host) && ($entry->port ?? $defaultPort) === $port) {
                return true;
            }
        }

        return false;
    }
}
