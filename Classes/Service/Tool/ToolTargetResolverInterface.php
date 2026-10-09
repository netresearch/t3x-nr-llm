<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolInvocationTarget;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Returns null for tools whose target this resolver does not understand.
 *
 * @api
 */
#[AutoconfigureTag(self::TAG_NAME)]
interface ToolTargetResolverInterface
{
    public const TAG_NAME = 'nr_llm.tool_target_resolver';

    public function resolve(
        ToolCall $call,
        ToolExecutionContext $context,
    ): ?ToolInvocationTarget;
}
