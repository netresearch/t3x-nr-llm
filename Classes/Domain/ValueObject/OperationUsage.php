<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * How often one operation of a calling extension ran in a period, and how
 * often it failed (ADR-248). The operation is the name the extension passed to
 * `withCallerSource()`; an empty string collects calls that named none.
 *
 * @api
 */
final readonly class OperationUsage
{
    public function __construct(
        public string $operation,
        public int $calls,
        public int $failedCalls,
        /** Unix time of the newest call in the period. */
        public int $lastCallAt,
    ) {}
}
