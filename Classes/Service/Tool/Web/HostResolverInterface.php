<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Web;

/**
 * Resolves a hostname to the addresses a request to it will be pinned to
 * (ADR-202). A seam so the guard's DNS-dependent decisions are testable.
 */
interface HostResolverInterface
{
    /**
     * Every A and AAAA address of the host, as canonical IP literals. An
     * empty list means the name did not resolve; the caller refuses it.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
