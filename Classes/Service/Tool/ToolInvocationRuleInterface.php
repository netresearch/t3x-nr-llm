<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\ToolInvocationDecision;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * An installation rule that adds restrictions immediately before execution.
 *
 * @api
 */
#[AutoconfigureTag(self::TAG_NAME)]
interface ToolInvocationRuleInterface
{
    public const TAG_NAME = 'nr_llm.tool_invocation_rule';

    public function identifier(): string;

    public function requiresCompleteHistory(): bool;

    public function decide(
        ToolInvocationContext $context,
    ): ToolInvocationDecision;
}
