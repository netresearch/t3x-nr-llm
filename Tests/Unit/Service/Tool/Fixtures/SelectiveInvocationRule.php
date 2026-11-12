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

/**
 * A rule that opted in to being asked before the card, refuses one tool and
 * counts the calls it is asked about.
 */
final class SelectiveInvocationRule implements ToolInvocationRuleInterface, PreApprovalInvocationRuleInterface
{
    /** @var list<string> */
    public array $asked = [];

    public function __construct(
        private readonly string $refusedTool,
        private readonly string $explanation,
    ) {}

    public function identifier(): string
    {
        return 'selective';
    }

    public function requiresCompleteHistory(): bool
    {
        return false;
    }

    public function decide(ToolInvocationContext $context): ToolInvocationDecision
    {
        $this->asked[] = $context->toolName;

        return $context->toolName === $this->refusedTool
            ? ToolInvocationDecision::deny('refused_on_purpose', $this->explanation)
            : ToolInvocationDecision::allow();
    }
}
