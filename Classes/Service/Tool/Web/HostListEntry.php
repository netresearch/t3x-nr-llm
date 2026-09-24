<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Web;

/**
 * One entry of the operator's allow- or denylist for `fetch_external_url`
 * (ADR-202).
 *
 * Accepted forms: `example.org`, `*.example.org` (subdomains, not the apex),
 * `example.org:8443`, an IPv4 address with or without `:port`, an IPv6
 * address bare (`2001:db8::1`) or in brackets (`[2001:db8::1]`,
 * `[2001:db8::1]:8443`).
 *
 * A name entry matches the host NAME of a URL. An address entry matches an IP
 * literal in the URL and — compared as a packed address, so `::ffff:a.b.c.d`
 * and other spellings of one address are the same — an address a host name
 * resolves to. The two kinds do not stand in for each other: a name entry says
 * nothing about a URL that names the same server by its address.
 */
final readonly class HostListEntry
{
    private function __construct(
        public string $pattern,
        public ?int $port,
        private ?string $packed,
    ) {}

    public static function parse(string $raw): ?self
    {
        $entry = strtolower(trim($raw));
        if ($entry === '') {
            return null;
        }

        if (preg_match('/^\[([0-9a-f:.]+)\](?::(\d{1,5}))?$/', $entry, $m) === 1) {
            return self::address($m[1], isset($m[2]) ? (int)$m[2] : null);
        }

        if (substr_count($entry, ':') >= 2) {
            return self::address($entry, null);
        }

        $port = null;
        if (preg_match('/^(.+):(\d{1,5})$/', $entry, $m) === 1) {
            $entry = $m[1];
            $port  = (int)$m[2];
        }

        if (filter_var($entry, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return self::address($entry, $port);
        }

        $entry = rtrim($entry, '.');

        return $entry === '' ? null : new self($entry, $port, null);
    }

    public function isAddress(): bool
    {
        return $this->packed !== null;
    }

    /**
     * Whether the host of a URL — a name, or an IP literal without brackets —
     * is this entry.
     */
    public function matchesHost(string $host): bool
    {
        if ($this->packed !== null) {
            return $this->matchesAddress($host);
        }

        if (str_starts_with($this->pattern, '*.')) {
            return str_ends_with($host, substr($this->pattern, 1));
        }

        return $host === $this->pattern;
    }

    /**
     * Whether an IP address is the address of this entry.
     */
    public function matchesAddress(string $ip): bool
    {
        if ($this->packed === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return self::canonical((string)inet_pton($ip)) === $this->packed;
    }

    private static function address(string $ip, ?int $port): ?self
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return new self($ip, $port, self::canonical((string)inet_pton($ip)));
    }

    /**
     * An IPv4-mapped IPv6 address and its IPv4 form are one address.
     */
    private static function canonical(string $packed): string
    {
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\x00", 10) . "\xff\xff")) {
            return substr($packed, 12);
        }

        return $packed;
    }
}
