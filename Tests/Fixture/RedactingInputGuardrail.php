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
 * Input guardrail that redacts a fixed needle out of a prompt — a test double
 * for asserting that a REDACT verdict rewrites the text that is sent.
 */
final readonly class RedactingInputGuardrail implements InputGuardrailInterface
{
    public function __construct(
        private string $needle,
        private string $replacement,
    ) {}

    public function checkInput(string $text): GuardrailResult
    {
        if (!str_contains($text, $this->needle)) {
            return GuardrailResult::allow();
        }

        return GuardrailResult::redact(str_replace($this->needle, $this->replacement, $text), 'redacted');
    }
}
