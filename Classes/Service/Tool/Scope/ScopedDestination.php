<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Scope;

/**
 * A page a write puts a row on, and the table of the row (ADR-295). Page 0 is
 * the root of the installation, which no scope of pages contains.
 *
 * @api
 */
final readonly class ScopedDestination
{
    public function __construct(
        public string $table,
        public int $pid,
    ) {}
}
