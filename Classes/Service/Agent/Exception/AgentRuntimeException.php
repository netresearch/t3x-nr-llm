<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Exception;

use RuntimeException;

/**
 * Base of the AgentRuntime request-validation exceptions (ADR-101).
 *
 * Thrown by {@see \Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface::approve()}
 * BEFORE any execution when the request itself is invalid — an unknown or
 * non-suspended run, a deleted configuration, unreadable state, or a lost
 * concurrency claim. Run OUTCOMES (completion, suspension, guardrail block,
 * failure) are never exceptions; they come back as a settled
 * {@see \Netresearch\NrLlm\Service\Agent\AgentRunResult}.
 */
abstract class AgentRuntimeException extends RuntimeException
{
    public function __construct(
        public readonly string $runUuid,
        string $message,
    ) {
        parent::__construct($message);
    }
}
