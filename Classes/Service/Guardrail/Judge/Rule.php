<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail\Judge;

/**
 * One line of a rule-set snippet (ADR-288): what a hint or a block quotes.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class Rule
{
    public function __construct(
        public int $number,
        public string $text,
        public string $snippetName,
    ) {}
}
