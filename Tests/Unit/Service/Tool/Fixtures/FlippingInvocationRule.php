<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures;

use Netresearch\NrLlm\Domain\ValueObject\ToolInvocationDecision;
use Netresearch\NrLlm\Service\Tool\PreApprovalInvocationRuleInterface;
use Netresearch\NrLlm\Service\Tool\ToolInvocationContext;
use Netresearch\NrLlm\Service\Tool\ToolInvocationRuleInterface;
use RuntimeException;

/**
 * A rule that opted in and breaks the promise to answer the same every time:
 * it refuses the first `$refusals` questions and allows afterwards, or, with
 * `$throws`, fails on every question. It stands for a refusal that does not
 * hold when the call runs.
 */
final class FlippingInvocationRule implements ToolInvocationRuleInterface, PreApprovalInvocationRuleInterface
{
    public int $asked = 0;

    public function __construct(
        private readonly int $refusals,
        private readonly bool $throws = false,
    ) {}

    public function identifier(): string
    {
        return 'flipping';
    }

    public function requiresCompleteHistory(): bool
    {
        return false;
    }

    public function decide(ToolInvocationContext $context): ToolInvocationDecision
    {
        ++$this->asked;
        if ($this->throws) {
            throw new RuntimeException('cannot tell', 1799293002);
        }

        return $this->asked <= $this->refusals
            ? ToolInvocationDecision::deny('refused_for_now', 'Refused for now.')
            : ToolInvocationDecision::allow();
    }
}
