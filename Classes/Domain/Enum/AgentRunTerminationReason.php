<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * Why an agent run ended (ADR-092).
 *
 * The status says *what* state a run is in; this says *why* it got there. Both
 * are needed: a run that stopped because the budget was exhausted and a run
 * that stopped because it exhausted its iteration cap are both COMPLETED with
 * `truncated = true`, and without a reason an operator cannot tell a cost
 * problem from a prompt problem — nor can a retry policy decide whether
 * retrying is pointless.
 */
enum AgentRunTerminationReason: string
{
    /**
     * The model stopped asking for tools and produced an answer.
     */
    case COMPLETED = 'completed';

    /**
     * The loop hit its iteration cap and a closing answer was synthesised.
     */
    case MAX_ITERATIONS = 'max_iterations';

    /**
     * The budget pre-flight denied a call mid-loop; the partial trace stands.
     */
    case BUDGET_EXHAUSTED = 'budget_exhausted';

    /**
     * A guardrail denied the content outright.
     */
    case POLICY_DENIED = 'policy_denied';

    /**
     * A guardrail or tool required human approval that was refused or never given.
     */
    case APPROVAL_DENIED = 'approval_denied';

    /**
     * The provider failed and every fallback was exhausted, or the run crashed.
     */
    case PROVIDER_FAILED = 'provider_failed';

    /**
     * An operator cancelled the run.
     */
    case CANCELLED = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }

    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom($value);
    }

    /**
     * Whether retrying the same run could plausibly succeed. A provider failure
     * may be transient; an exhausted budget or a policy decision will not fix
     * itself, and retrying only burns money or repeats the denial.
     */
    public function isRetryable(): bool
    {
        return $this === self::PROVIDER_FAILED;
    }
}
