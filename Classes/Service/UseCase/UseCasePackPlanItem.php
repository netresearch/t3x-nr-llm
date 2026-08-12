<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\UseCase;

/**
 * One record a pack would create, and whether it is already there (ADR-163).
 *
 * `installed` is the answer to "already installed?" for exactly one record:
 * a row with this identifier exists in the target table. It is deliberately not
 * "exists and still matches what the pack declares" — an operator who edited a
 * pack task owns that record, and re-running the install must leave it alone.
 */
final readonly class UseCasePackPlanItem
{
    public function __construct(
        public string $identifier,
        public string $label,
        public bool $installed,
    ) {}
}
