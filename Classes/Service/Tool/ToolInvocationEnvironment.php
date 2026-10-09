<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\SkillToolAllowList;

/**
 * @internal Run-local invocation context and policy collaborators; never persisted.
 */
final readonly class ToolInvocationEnvironment
{
    public function __construct(
        public LlmConfiguration $configuration,
        public SkillToolAllowList $skillAllowList,
        public ToolExecutionContext $execution,
        public ?RunTrace $trace,
    ) {}
}
