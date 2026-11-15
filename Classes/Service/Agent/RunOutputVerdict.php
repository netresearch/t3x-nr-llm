<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

/**
 * What the end-of-run check decided (ADR-302): deliver, or stop with a
 * reason. The stored reason names rules and terms, never a passage.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class RunOutputVerdict
{
    private function __construct(
        public bool $blocks,
        public string $reason,
    ) {}

    public static function deliver(): self
    {
        return new self(false, '');
    }

    public static function block(string $reason): self
    {
        return new self(true, $reason);
    }
}
