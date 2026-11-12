<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server\Handler;

use Closure;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aMessage;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;

/**
 * A domain handler's decision on one message: run it, refuse it before the
 * run starts (the task is REJECTED, A2A-SV10), or answer with a message and
 * create no task (the TCK fixture only).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aHandlerDecision
{
    /**
     * @param (Closure(string): void)|null $afterCommit called with the run's uuid once the acceptance committed
     */
    private function __construct(
        public ?AgentRunRequest $request,
        public bool $rejected,
        public ?A2aMessage $reply,
        public ?Closure $afterCommit,
    ) {}

    /**
     * @param (Closure(string): void)|null $afterCommit
     */
    public static function run(AgentRunRequest $request, ?Closure $afterCommit = null): self
    {
        return new self($request, false, null, $afterCommit);
    }

    public static function reject(AgentRunRequest $request): self
    {
        return new self($request, true, null, null);
    }

    public static function reply(A2aMessage $message): self
    {
        return new self(null, false, $message, null);
    }
}
