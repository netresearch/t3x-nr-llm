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
 *    "precheck request count only"; real cost can still be accounted
 *    for post-call by the UsageMiddleware.
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

        if (\is_float($value)) {
            return $value;
        }
        if (\is_int($value)) {
            return (float)$value;
        }

        return 0.0;
    }
}
