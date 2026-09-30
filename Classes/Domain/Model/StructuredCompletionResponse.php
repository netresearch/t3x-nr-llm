<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

/**
 * A schema-valid structured completion together with what it took (ADR-211).
 *
 * `$response` is the attempt whose content passed validation — its model and
 * provider name what actually answered. `$usage` covers every attempt,
 * the rejected first answer of a repair round-trip included, because both
 * were paid for.
 *
 * @api
 */
final readonly class StructuredCompletionResponse
{
    /**
     * @param array<string, mixed> $data     the decoded, schema-valid JSON payload
     * @param int<1, 2>            $attempts 1, or 2 after a repair round-trip
     */
    public function __construct(
        public array $data,
        public CompletionResponse $response,
        public UsageStatistics $usage,
        public int $attempts,
    ) {}
}
