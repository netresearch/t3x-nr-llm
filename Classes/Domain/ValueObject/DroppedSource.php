<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\DroppedSourceReason;

/**
 * One forced source a run asked for and did not get (ADR-179).
 *
 * Carries the uid rather than the record, because there is no record to carry:
 * that is the event. The kind is a plain string ('skill' / 'snippet') so this
 * object does not need to know about either repository.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class DroppedSource
{
    public function __construct(
        public string $kind,
        public int $uid,
        public DroppedSourceReason $reason,
    ) {}
}
