<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Decision;

use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Exception\GuardrailApprovalRequiredException;
use Netresearch\NrLlm\Exception\GuardrailViolationException;
use Netresearch\NrLlm\Exception\InputContextTrustZoneException;

/**
 * Typed decisions about a subject, by a named profile, from the model of a
 * configuration (ADR-211).
 *
 * The service returns answers and decides nothing: thresholds, consequences
 * and permission checks stay in the caller.
 *
 * @api
 */
interface DecisionServiceInterface
{
    /**
     * @throws DecisionException                  the decision could not be made, for any reason but the ones below
     * @throws BudgetExceededException            the call would exceed a budget
     * @throws GuardrailViolationException        a guardrail refused the subject or, for a chat model, its answer
     * @throws GuardrailApprovalRequiredException a guardrail requires an approval first
     * @throws InputContextTrustZoneException     the configuration's injected context may not reach the serving provider
     */
    public function evaluate(DecisionRequest $request): DecisionResult;

    /**
     * Whether a decision by this profile can be asked on this configuration
     * (the request's, else `decision.configuration`) — without asking it.
     *
     * Checks what needs no subject: the profile, the configuration, a model
     * that can answer, and the trust zone against the profile's data class.
     * Spends nothing; a caller about to run many paid calls whose results
     * only a decision can judge checks this first.
     *
     * @throws DecisionException with the code evaluate() would fail with
     */
    public function assertAvailable(string $profile, ?string $configuration = null): void;
}
