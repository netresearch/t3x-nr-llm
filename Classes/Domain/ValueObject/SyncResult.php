<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\SyncStatus;

final readonly class SyncResult
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        public SyncStatus $status,
        public int $created = 0,
        public int $updated = 0,
        public int $disabledOnChange = 0,
        public int $orphaned = 0,
        public array $errors = [],
        public int $injectionBlocked = 0,
    ) {}
}
