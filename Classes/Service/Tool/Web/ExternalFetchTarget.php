<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Web;

/**
 * The verdict of {@see ExternalUrlGuard} on one URL (ADR-202): either a
 * refusal with a reason the model can act on, or a target whose addresses
 * were resolved and checked and are the only ones the request may connect to.
 */
final readonly class ExternalFetchTarget
{
    /**
     * @param list<string> $addresses
     */
    private function __construct(
        public bool $allowed,
        public string $reason,
        public string $url,
        public string $host,
        public int $port,
        public array $addresses,
    ) {}

    public static function refused(string $reason): self
    {
        return new self(false, $reason, '', '', 0, []);
    }

    /**
     * @param list<string> $addresses
     */
    public static function allowed(string $url, string $host, int $port, array $addresses): self
    {
        return new self(true, '', $url, $host, $port, $addresses);
    }

    /**
     * The `CURLOPT_RESOLVE` entry that pins the connection to the checked
     * addresses: `host:port:addr1,addr2`, IPv6 addresses in brackets. One
     * entry with every address, because curl keeps only the last entry per
     * host:port. Null for an IP literal, which curl does not resolve.
     */
    public function resolvePin(): ?string
    {
        if (!$this->allowed || filter_var($this->host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $addresses = array_map(
            static fn(string $ip): string => str_contains($ip, ':') ? '[' . $ip . ']' : $ip,
            $this->addresses,
        );

        return sprintf('%s:%d:%s', $this->host, $this->port, implode(',', $addresses));
    }
}
