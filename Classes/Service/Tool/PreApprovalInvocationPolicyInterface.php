<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\ToolInvocationDecision;

/**
 * An invocation policy that can answer, before an approval card is shown,
 * whether it would refuse the call (ADR-293).
 *
 * Only the rules that opted in through {@see PreApprovalInvocationRuleInterface}
 * are asked; the answer is advisory for the card, never the gate. The gate is
 * {@see ToolInvocationPolicyInterface::decide()}, asked again immediately
 * before the call runs.
 *
 * @api
 */
interface PreApprovalInvocationPolicyInterface
{
    public function decideBeforeApproval(
        ToolInvocationContext $context,
    ): ToolInvocationDecision;
}
