<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * One executed tool call recorded during a {@see ToolLoopService} run.
 *
 * Captures the model-requested tool name, the arguments it supplied, the
 * string the PHP tool returned, whether that turn was an error (unknown tool or
 * a caught, sanitised exception), and any run-only structured artifacts the
 * tool attached. The collected list of invocations forms the
 * {@see ToolLoopResult::$trace} audit trail.
 */
final readonly class ToolInvocation
{
    /**
     * @param array<string, mixed> $arguments The arguments the model supplied for the call.
     * @param list<ToolArtifact>   $artifacts Run-only structured artifacts (NEVER provider-facing).
     */
    public function __construct(
        public string $name,
        public array $arguments,
        public string $result,
        public bool $isError,
        public array $artifacts = [],
    ) {}
}
