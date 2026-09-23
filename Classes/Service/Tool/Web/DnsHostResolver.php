<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Web;

use Throwable;

/**
 * Resolves through DNS with `dns_get_record()` (ADR-202).
 *
 * DNS only, on purpose: `/etc/hosts`, NSS modules and mDNS are not consulted.
 * A name that exists only there — `db`, `host.docker.internal` — resolves to
 * nothing here and is refused, instead of being handed to curl, whose own
 * getaddrinfo() lookup would find it. The addresses returned here are pinned
 * for the connection, so curl never resolves the name itself.
 */
final readonly class DnsHostResolver implements HostResolverInterface
{
    public function resolve(string $host): array
    {
        try {
            // A failed lookup may raise a warning, which TYPO3's error handler
            // can turn into an exception; either way it is "no address".
            $records = dns_get_record($host, DNS_A | DNS_AAAA);
        } catch (Throwable) {
            return [];
        }

        if (!is_array($records)) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                $addresses[] = strtolower($ip);
            }
        }

        return array_values(array_unique($addresses));
    }
}
