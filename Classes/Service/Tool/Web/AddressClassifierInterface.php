<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Web;

/**
 * What {@see ExternalUrlGuard} asks about an address (ADR-202).
 *
 * {@see IpAddressClassifier} is the implementation every context uses. The
 * interface exists for one replacement: the A2A interop job reaches its
 * partner on a private Docker network, and only the Testing context may
 * classify that network as public (spec 051 A2A-SEC10).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
interface AddressClassifierInterface
{
    /**
     * Whether the address is a public unicast address a request may reach.
     */
    public function isPublic(string $ip): bool;

    /**
     * Whether the host is a numeric form other than a dotted quad or IPv6.
     */
    public function isAmbiguousNumericHost(string $host): bool;
}
