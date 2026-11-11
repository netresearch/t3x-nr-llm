<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Transport;

/**
 * One HTTP request of the A2A client, with the bounds of the record it goes to
 * (spec 051 A2A-C8).
 *
 * `$credential` is an nr-vault identifier, never a secret; '' sends none.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aHttpRequest
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers,
        public string $body,
        public string $credential,
        public int $connectTimeoutSeconds,
        public int $timeoutSeconds,
        public int $maxResponseBytes,
        public string $reason,
    ) {}
}
