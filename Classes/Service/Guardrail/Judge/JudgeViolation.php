<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail\Judge;

/**
 * A rule the judge says is broken, with the passage that breaks it, both
 * verified against the rule set and the text (ADR-288).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class JudgeViolation
{
    public function __construct(
        public Rule $rule,
        public string $passage,
    ) {}
}
