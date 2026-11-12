<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail\Judge;

/**
 * Whether a judge call is running in this request (ADR-288).
 *
 * The judge's own call passes the output guardrails as well. Without this, a
 * judging configuration that names a judge of its own, or itself, would judge
 * the judge's answer, and so on. Shared by the container, so one flag per
 * request.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class JudgeReentry
{
    private bool $active = false;

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * @template T
     *
     * @param callable(): T $judge
     *
     * @return T
     */
    public function during(callable $judge): mixed
    {
        $this->active = true;
        try {
            return $judge();
        } finally {
            $this->active = false;
        }
    }
}
