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
     * Operational failures include configuration lookup, routing and budget-subject resolution.
     * Engine Errors propagate without wrapping.
     *
     * @throws DecisionException                  the decision could not be made, except for policy denials below
     * @throws BudgetExceededException            the call would exceed a budget
     * @throws GuardrailViolationException        a guardrail refused the subject or, for a chat model, its answer
     * @throws GuardrailApprovalRequiredException a guardrail requires an approval first
     * @throws InputContextTrustZoneException     the injected context may not reach the serving provider
     */
    public function evaluate(DecisionRequest $request): DecisionResult;

    /**
     * Check the profile, configuration, model and trust zone without a provider call.
     * Uses the request's configuration or decision.configuration. Policy denials
     * preserve their types; engine Errors propagate as in evaluate().
     *
     * @throws DecisionException                  with the code evaluate() would fail with
     * @throws BudgetExceededException
     * @throws GuardrailViolationException
     * @throws GuardrailApprovalRequiredException
     * @throws InputContextTrustZoneException
     */
    public function assertAvailable(string $profile, ?string $configuration = null): void;
}
