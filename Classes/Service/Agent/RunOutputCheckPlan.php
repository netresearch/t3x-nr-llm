<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

/**
 * Whether a run's answer is checked after its loop, and whether the run only
 * checks a text it was given (ADR-302).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class RunOutputCheckPlan
{
    private function __construct(
        public bool $applies,
        public bool $checkOnly,
    ) {}

    public static function none(): self
    {
        return new self(false, false);
    }

    /**
     * @param bool $checkOnly the submitted text is the answer: no generator call, no tool call
     */
    public static function check(bool $checkOnly = false): self
    {
        return new self(true, $checkOnly);
    }
}
