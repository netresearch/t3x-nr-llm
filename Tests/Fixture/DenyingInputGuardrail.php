<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixture;

use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;
use Netresearch\NrLlm\Service\Guardrail\InputGuardrailInterface;

/**
 * Input guardrail that denies every prompt — a test double for asserting that a
 * DENY verdict aborts the call before any spend.
 */
final readonly class DenyingInputGuardrail implements InputGuardrailInterface
{
    public function __construct(private string $reason = 'denied by policy') {}

    public function checkInput(string $text): GuardrailResult
    {
        return GuardrailResult::deny($this->reason);
    }
}
