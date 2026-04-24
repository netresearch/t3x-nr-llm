<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Middleware;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Service\BudgetService;

/**
 * Pre-flight budget check middleware (ADR-025).
 *
 * Reads the target backend user id and the planned cost from the
 * ProviderCallContext metadata and asks BudgetService whether the call
 * is allowed. On denial the call is short-circuited with a typed
 * BudgetExceededException carrying the full BudgetCheckResult so
 * controllers / log sinks can report exactly which bucket tripped.
 *
 * Metadata keys consumed (callers put them on the context):
 *  - `BudgetMiddleware::METADATA_BE_USER_UID` : int, the backend user
 *    whose budget is checked. 0 / absent / non-int means "skip the
 *    check" (CLI, scheduler, unauthenticated callers; see ADR-025 rule 1).
 *  - `BudgetMiddleware::METADATA_PLANNED_COST` : float, the expected
 *    cost of the call in the configured currency. 0.0 / absent means
 *    "I do not know the cost yet — evaluate only the non-cost limits
 *    (request count, token totals from existing usage rows)"; the
 *    cost-per-day bucket still blocks a request when prior usage has
 *    already exceeded the configured ceiling. Real cost for this call
 *    is accounted post-flight by the UsageMiddleware.
 *
 * Pipeline ordering:
 *
 *   BudgetMiddleware         <-- outermost of the retry/usage layers
 *     FallbackMiddleware     <-- may swap the configuration and retry
 *       UsageMiddleware      <-- records what actually ran
 *         <terminal>
 *
 * With Budget outside Fallback the pre-flight check runs exactly once
 * per user-initiated call, not once per fallback attempt. Consumers
 * that want to charge each retry separately should register a custom
 * pipeline order; the default ordering follows this ADR.
 *
 * No side effects. The budget record is not incremented here — that is
 * the job of UsageMiddleware after the call succeeds.
 */
final readonly class BudgetMiddleware implements ProviderMiddlewareInterface
{
    public const METADATA_BE_USER_UID  = 'beUserUid';
    public const METADATA_PLANNED_COST = 'plannedCost';

    public function __construct(
        private BudgetService $budgetService,
    ) {}

    /**
     * @param callable(LlmConfiguration): mixed $next
     *
     * @throws BudgetExceededException when the pre-flight check denies the call
     */
    public function handle(
        ProviderCallContext $context,
        LlmConfiguration $configuration,
        callable $next,
    ): mixed {
        $beUserUid   = $this->readInt($context, self::METADATA_BE_USER_UID);
        $plannedCost = $this->readFloat($context, self::METADATA_PLANNED_COST);

        $result = $this->budgetService->check($beUserUid, $plannedCost);

        if (!$result->allowed) {
            throw new BudgetExceededException($result);
        }

        return $next($configuration);
    }

    private function readInt(ProviderCallContext $context, string $key): int
    {
        $value = $context->metadata[$key] ?? null;

        return \is_int($value) ? $value : 0;
    }

    private function readFloat(ProviderCallContext $context, string $key): float
    {
        $value = $context->metadata[$key] ?? null;

        return (\is_float($value) || \is_int($value)) ? (float)$value : 0.0;
    }
}
