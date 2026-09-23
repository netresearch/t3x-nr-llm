<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Web;

/**
 * Decides whether an IP address is a public unicast address a model-chosen
 * request may reach (ADR-202).
 *
 * The ranges follow nr-vault's `SecureHttpClientFactory`, whose classifier is
 * private to that class. It is restated here rather than reached through
 * nr-vault's public `isHostAllowed()`, because that method answers a different
 * question: a literal entry in `$GLOBALS['TYPO3_CONF_VARS']['HTTP']['allowed_hosts']`
 * opts a private address back in there — an opt-in an operator makes for a
 * credentialed service call, not for a URL a model chose.
 *
 * Refused:
 *
 * - IPv4: 0/8, 10/8, 100.64/10 (CGNAT), 127/8, 169.254/16 (link-local and
 *   the cloud metadata address 169.254.169.254), 172.16/12, 192.0.0/24,
 *   192.0.2/24, 192.168/16, 198.18/15, 198.51.100/24, 203.0.113/24,
 *   224/4 (multicast), 240/4 (reserved, including the broadcast address).
 * - IPv6: ::, ::1, fc00::/7 (ULA, including the AWS metadata address
 *   fd00:ec2::254), fe80::/10, fec0::/10, ff00::/8, 100::/64, 2001:db8::/32,
 *   64:ff9b::/96 and 64:ff9b:1::/48 (NAT64), and every form that embeds an IPv4 address whose
 *   IPv4 is refused: IPv4-mapped ::ffff:0:0/96, IPv4-compatible ::/96,
 *   6to4 2002::/16, Teredo 2001::/32.
 *
 * Anything that is not a canonical IPv4 or IPv6 literal is not public.
 */
final readonly class IpAddressClassifier
{
    public function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $packed = inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        return match (strlen($packed)) {
            4       => $this->isPublicIpv4($packed),
            16      => $this->isPublicIpv6($packed),
            default => false,
        };
    }

    /**
     * Whether a host is a numeric form an `inet_aton()` resolver reads as an IP
     * address although PHP's strict parsers do not: `2130706433`, `0x7f000001`,
     * `0177.0.0.1`, `127.1`. curl connects all of these to 127.0.0.1, so a
     * guard that treats them as hostnames lets them through.
     */
    public function isAmbiguousNumericHost(string $host): bool
    {
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        $part = '(?:0[xX][0-9a-fA-F]*|[0-9]+)';

        return preg_match('/^' . $part . '(?:\.' . $part . '){0,3}\.?$/', $host) === 1;
    }

    private function isPublicIpv4(string $packed): bool
    {
        [$o1, $o2, $o3] = [ord($packed[0]), ord($packed[1]), ord($packed[2])];

        return !(
            $o1 === 0
            || $o1 === 10
            || ($o1 === 100 && ($o2 & 0xC0) === 64)
            || $o1 === 127
            || ($o1 === 169 && $o2 === 254)
            || ($o1 === 172 && ($o2 & 0xF0) === 16)
            || ($o1 === 192 && $o2 === 0 && ($o3 === 0 || $o3 === 2))
            || ($o1 === 192 && $o2 === 168)
            || ($o1 === 198 && ($o2 === 18 || $o2 === 19))
            || ($o1 === 198 && $o2 === 51 && $o3 === 100)
            || ($o1 === 203 && $o2 === 0 && $o3 === 113)
            || $o1 >= 224
        );
    }

    private function isPublicIpv6(string $packed): bool
    {
        $b0 = ord($packed[0]);
        $b1 = ord($packed[1]);

        if (($b0 & 0xFE) === 0xFC                                // fc00::/7 ULA
            || $b0 === 0xFF                                      // ff00::/8 multicast
            || ($b0 === 0xFE && ($b1 & 0xC0) === 0x80)           // fe80::/10 link-local
            || ($b0 === 0xFE && ($b1 & 0xC0) === 0xC0)           // fec0::/10 site-local
        ) {
            return false;
        }

        $zeroes = str_repeat("\x00", 16);
        if (substr($packed, 0, 12) === "\x00\x64\xff\x9b" . str_repeat("\x00", 8)   // 64:ff9b::/96 NAT64
            || str_starts_with($packed, "\x00\x64\xff\x9b\x00\x01")                   // 64:ff9b:1::/48 local-use NAT64
            || substr($packed, 0, 8) === "\x01\x00" . str_repeat("\x00", 6)         // 100::/64 discard
            || str_starts_with($packed, "\x20\x01\x0d\xb8")                         // 2001:db8::/32 documentation
        ) {
            return false;
        }

        // IPv4-mapped ::ffff:a.b.c.d
        if (substr($packed, 0, 12) === substr($zeroes, 0, 10) . "\xff\xff") {
            return $this->isPublicIpv4(substr($packed, 12, 4));
        }

        // ::, ::1 and IPv4-compatible ::a.b.c.d — the embedded 0.0.0.0/0.0.0.1
        // of the first two is refused by the IPv4 check.
        if (substr($packed, 0, 12) === substr($zeroes, 0, 12)) {
            return $this->isPublicIpv4(substr($packed, 12, 4));
        }

        // 6to4 2002:AABB:CCDD:: carries its IPv4 in bytes 2-5.
        if (str_starts_with($packed, "\x20\x02")) {
            return $this->isPublicIpv4(substr($packed, 2, 4));
        }

        // Teredo 2001:0000::/32: server IPv4 in bytes 4-7, client IPv4 in
        // bytes 12-15 XOR 0xFFFFFFFF. Either reaching a refused address is
        // enough to refuse.
        if (str_starts_with($packed, "\x20\x01\x00\x00")) {
            return $this->isPublicIpv4(substr($packed, 4, 4))
                && $this->isPublicIpv4(substr($packed, 12, 4) ^ "\xff\xff\xff\xff");
        }

        return true;
    }
}
