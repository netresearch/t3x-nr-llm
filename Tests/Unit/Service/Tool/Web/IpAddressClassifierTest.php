<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Web;

use Netresearch\NrLlm\Service\Tool\Web\IpAddressClassifier;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The address ranges fetch_external_url may never reach (ADR-202).
 */
#[CoversClass(IpAddressClassifier::class)]
final class IpAddressClassifierTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function publicAddresses(): array
    {
        return [
            'ipv4 dns resolver'      => ['8.8.8.8'],
            'ipv4 cloudflare'        => ['1.1.1.1'],
            'ipv4 just below cgnat'  => ['100.63.255.255'],
            'ipv4 just above cgnat'  => ['100.128.0.0'],
            'ipv4 172.32 is public'  => ['172.32.0.1'],
            'ipv4 192.0.1 is public' => ['192.0.1.1'],
            'ipv6 cloudflare'        => ['2606:4700:4700::1111'],
            'ipv6 google'            => ['2a00:1450:4001:82a::200e'],
            'ipv4-mapped public'     => ['::ffff:8.8.8.8'],
            '6to4 of a public ipv4'  => ['2002:0808:0808::1'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedAddresses(): array
    {
        return [
            'this network'                 => ['0.0.0.0'],
            'rfc1918 10/8'                 => ['10.1.2.3'],
            'cgnat'                        => ['100.64.0.1'],
            'alibaba metadata in cgnat'    => ['100.100.100.200'],
            'loopback'                     => ['127.0.0.1'],
            'loopback range'               => ['127.255.255.254'],
            'link-local'                   => ['169.254.1.1'],
            'cloud metadata'               => ['169.254.169.254'],
            'rfc1918 172.16/12'            => ['172.16.0.1'],
            'rfc1918 172.31'               => ['172.31.255.255'],
            'ietf protocol assignments'    => ['192.0.0.8'],
            'test-net-1'                   => ['192.0.2.1'],
            'rfc1918 192.168/16'           => ['192.168.1.1'],
            'benchmarking'                 => ['198.18.0.1'],
            'test-net-2'                   => ['198.51.100.7'],
            'test-net-3'                   => ['203.0.113.9'],
            'multicast'                    => ['224.0.0.1'],
            'reserved class e'             => ['240.0.0.1'],
            'broadcast'                    => ['255.255.255.255'],
            'ipv6 unspecified'             => ['::'],
            'ipv6 loopback'                => ['::1'],
            'ipv6 ula'                     => ['fc00::1'],
            'aws ipv6 metadata'            => ['fd00:ec2::254'],
            'ipv6 link-local'              => ['fe80::1'],
            'ipv6 site-local'              => ['fec0::1'],
            'ipv6 multicast'               => ['ff02::1'],
            'ipv6 discard'                 => ['100::1'],
            'ipv6 documentation'           => ['2001:db8::1'],
            'ipv4-mapped loopback'         => ['::ffff:127.0.0.1'],
            'ipv4-mapped loopback hex'     => ['::ffff:7f00:1'],
            'ipv4-mapped metadata'         => ['::ffff:169.254.169.254'],
            'ipv4-mapped rfc1918'          => ['::ffff:10.0.0.1'],
            'ipv4-compatible loopback'     => ['::127.0.0.1'],
            'nat64 of metadata'            => ['64:ff9b::a9fe:a9fe'],
            'local-use nat64 of metadata'  => ['64:ff9b:1::a9fe:a9fe'],
            '6to4 of loopback'             => ['2002:7f00:0001::1'],
            'teredo server rfc1918'        => ['2001:0:0a00:0001::1'],
            'teredo client loopback'       => ['2001:0:4136:e378:8000:63bf:80ff:fffe'],
            'not an ip'                    => ['example.org'],
            'empty'                        => [''],
            'bracketed ipv6'               => ['[::1]'],
            'decimal loopback'             => ['2130706433'],
            'octal loopback'               => ['0177.0.0.1'],
        ];
    }

    #[Test]
    #[DataProvider('publicAddresses')]
    public function aPublicAddressIsPublic(string $ip): void
    {
        self::assertTrue((new IpAddressClassifier())->isPublic($ip));
    }

    #[Test]
    #[DataProvider('refusedAddresses')]
    public function aNonPublicAddressIsRefused(string $ip): void
    {
        self::assertFalse((new IpAddressClassifier())->isPublic($ip));
    }

    /**
     * The ranges are restated from nr-vault (ADR-202). One direction is pinned:
     * whatever nr-vault's guard refuses, this classifier refuses too. Extra
     * ranges on this side are allowed; a range nr-vault adds later and this
     * class lacks turns the test red.
     */
    #[Test]
    public function everyAddressNrVaultRefusesIsRefusedHereToo(): void
    {
        $previous = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS'] = ['HTTP' => []];

        try {
            $vault      = new SecureHttpClientFactory();
            $classifier = new IpAddressClassifier();
            $checked    = 0;

            foreach ([...self::publicAddresses(), ...self::refusedAddresses()] as [$ip]) {
                if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                    continue;
                }

                ++$checked;
                if (!$vault->isHostAllowed($ip)) {
                    self::assertFalse($classifier->isPublic($ip), $ip . ' is refused by nr-vault but public here.');
                }
            }

            self::assertGreaterThan(40, $checked);
        } finally {
            $GLOBALS['TYPO3_CONF_VARS'] = $previous;
        }
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function numericHosts(): array
    {
        return [
            'decimal dword'          => ['2130706433', true],
            'hex dword'              => ['0x7f000001', true],
            'octal dotted'           => ['0177.0.0.1', true],
            'hex dotted'             => ['0x7f.0.0.1', true],
            'short dotted'           => ['127.1', true],
            'octal dword'            => ['017700000001', true],
            'trailing dot'           => ['2130706433.', true],
            'canonical dotted-quad'  => ['127.0.0.1', false],
            'canonical ipv6'         => ['::1', false],
            'hostname'               => ['example.org', false],
            'numeric-looking domain' => ['163.com', false],
            'empty'                  => ['', false],
        ];
    }

    #[Test]
    #[DataProvider('numericHosts')]
    public function legacyNumericFormsAreRecognised(string $host, bool $ambiguous): void
    {
        self::assertSame($ambiguous, (new IpAddressClassifier())->isAmbiguousNumericHost($host));
    }
}
