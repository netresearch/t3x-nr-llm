<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Exception;

/**
 * Marker interface for a guardrail POLICY outcome (ADR-085 / ADR-086): the
 * provider call itself succeeded, but a guardrail then blocked the response
 * ({@see GuardrailViolationException}) or flagged it for human approval
 * ({@see GuardrailApprovalRequiredException}).
 *
 * It exists so a layer that observes the pipeline can tell a policy block from a
 * genuine provider failure without importing the concrete classes. In
 * particular {@see \Netresearch\NrLlm\Provider\Middleware\TelemetryMiddleware}
 * records a policy exception as a SUCCESSFUL provider run — the provider
 * produced a response; the guardrail simply refused to release it — so guardrail
 * denials never distort the provider failure-rate.
 */
interface GuardrailPolicyException extends NrLlmExceptionInterface {}
