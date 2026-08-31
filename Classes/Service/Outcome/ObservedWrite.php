<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Outcome;

use Netresearch\NrLlm\Domain\ValueObject\RecordReference;

/**
 * One persisted tool write, as the observed-outcome derivation needs it
 * (ADR-185).
 *
 * Four values, and no more: the record that was written, when it was written,
 * and the correlation id the outcome row is keyed by. Everything else about the
 * run — who started it, what it cost, which model served it — is on rows this
 * derivation has no reason to read.
 *
 * `$writtenAt` is the timestamp of the `tool_write` EVENT, not of the record.
 * It is what separates the write's own history row from the ones that came
 * after it, which is the difference between `ACCEPTED_UNCHANGED` and `EDITED`.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class ObservedWrite
{
    public function __construct(
        public string $correlationId,
        public RecordReference $record,
        public int $writtenAt,
    ) {}
}
