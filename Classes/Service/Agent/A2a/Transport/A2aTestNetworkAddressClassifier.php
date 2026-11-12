<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Transport;

use Netresearch\NrLlm\Service\Tool\Web\AddressClassifierInterface;

/**
 * Treats the addresses of named test networks as public; everything else is
 * decided by the real classifier (spec 051 A2A-SEC10).
 *
 * The interop job reaches its `a2a-python` partner on a private Docker
 * network. {@see A2aUrlGuardFactory} builds the guard with this classifier
 * only in the Testing application context and only for networks listed in
 * `$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['nr_llm']['a2aTestPublicNetworks']`.
 * In every other context the real classifier decides, which a functional test
 * asserts.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aTestNetworkAddressClassifier implements AddressClassifierInterface
{
    /**
     * @param list<string> $networks CIDR blocks, e.g. `172.16.0.0/12`
     */
    public function __construct(
        private AddressClassifierInterface $inner,
        private array $networks,
    ) {}

    public function isPublic(string $ip): bool
    {
        foreach ($this->networks as $network) {
            if ($this->contains($network, $ip)) {
                return true;
            }
        }

        return $this->inner->isPublic($ip);
    }

    public function isAmbiguousNumericHost(string $host): bool
    {
        return $this->inner->isAmbiguousNumericHost($host);
    }

    private function contains(string $network, string $ip): bool
    {
        [$base, $bits] = array_pad(explode('/', $network, 2), 2, '');
        if (filter_var($ip, FILTER_VALIDATE_IP) === false || filter_var($base, FILTER_VALIDATE_IP) === false || !ctype_digit($bits)) {
            return false;
        }

        $address = inet_pton($ip);
        $prefix  = inet_pton($base);
        if ($address === false || $prefix === false || strlen($address) !== strlen($prefix)) {
            return false;
        }

        $bits = (int) $bits;
        if ($bits > strlen($address) * 8) {
            return false;
        }

        $whole = intdiv($bits, 8);
        if (substr($address, 0, $whole) !== substr($prefix, 0, $whole)) {
            return false;
        }

        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($address[$whole]) & $mask) === (ord($prefix[$whole]) & $mask);
    }
}
