<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Budget;

use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;

/**
 * The budget pre-flight of a call whose request was already counted.
 *
 * {@see \Netresearch\NrLlm\Service\BudgetServiceInterface::check()} counts the
 * incoming call as one more request. A call that belongs to an operation whose
 * request row was written BEFORE it — the revision of a DeepL translation
 * (ADR-233) — would be counted twice: at N−1 of N requests the translation
 * passes and the revision is refused. This check leaves the request out and
 * keeps the token and cost limits.
 *
 * A separate interface rather than a parameter of `check()`, because
 * `BudgetServiceInterface` is `@api` and other implementations exist; the
 * BudgetMiddleware falls back to `check()` for a service that does not
 * implement this one.
 *
 * @internal
 */
interface CountedRequestBudgetCheckInterface
{
    public function checkWithoutNewRequest(int $beUserUid, float $plannedCost = 0.0, ?LlmConfiguration $configuration = null): BudgetCheckResult;
}
