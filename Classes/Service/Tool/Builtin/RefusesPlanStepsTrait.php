<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Closure;
use Netresearch\NrLlm\Service\Tool\Exception\PlanRefusedException;

/**
 * Lets a plan with many input checks stop at a check by throwing its refusal,
 * instead of returning it from every check.
 *
 * A writing tool's `plan()` answers with the resolved write or with the refusal
 * text (see {@see PlansOneEditorialWriteTrait::plan()}). A plan that checks ten
 * arguments in turn returned from ten places. With this trait the checks throw
 * a {@see PlanRefusedException}, and `planOrRefusal()` turns it back into the
 * refusal text at the one place the plan is entered. The checks keep their
 * order and every refusal its text: the first check that fails still names the
 * reason.
 *
 * Guard clauses that return stay the norm. The trait is for a method that
 * validates more than about eight inputs in sequence, see `Classes/AGENTS.md`.
 */
trait RefusesPlanStepsTrait
{
    /**
     * Run the plan, and answer a refusal one of its checks threw as its text.
     *
     * @template TPlan of array<mixed>
     *
     * @param Closure(): TPlan $plan
     *
     * @return TPlan|string
     */
    private static function planOrRefusal(Closure $plan): array|string
    {
        try {
            return $plan();
        } catch (PlanRefusedException $refused) {
            return $refused->getMessage();
        }
    }

    /**
     * Stop the plan with this refusal.
     *
     * @throws PlanRefusedException
     */
    private static function refuse(string $refusal): never
    {
        throw new PlanRefusedException($refusal, 1848440017);
    }

    /**
     * Stop the plan when a check answered with a refusal; null lets it go on.
     *
     * @throws PlanRefusedException
     */
    private static function refuseIf(?string $refusal): void
    {
        if ($refusal !== null) {
            throw new PlanRefusedException($refusal, 9745851034);
        }
    }

    /**
     * The value of a check that answers with its value or with a refusal text.
     *
     * @template TValue of array<mixed>|object
     *
     * @param TValue|string $result
     *
     * @return TValue
     *
     * @throws PlanRefusedException
     */
    private static function unlessRefused(array|object|string $result): array|object
    {
        if (is_string($result)) {
            throw new PlanRefusedException($result, 2372365467);
        }

        return $result;
    }
}
