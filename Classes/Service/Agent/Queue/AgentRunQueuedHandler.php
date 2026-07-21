<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Queue;

use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Executes a queued agent run when its message arrives (ADR-102).
 *
 * On the default SyncTransport this runs in-process during enqueue(); with the
 * doctrine transport routed by the operator it runs inside
 * ``bin/typo3 messenger:consume``. Either way the handler is a thin adapter:
 * {@see AgentRuntimeInterface::runQueued()} owns the atomic claim, the
 * rehydration and the whole fail-closed lifecycle, and never throws for a run
 * outcome — so a handled message is never redelivered for a run that merely
 * failed (the run row carries the outcome). A null result (claim lost: another
 * worker won, or the run was cancelled while queued) is a non-event.
 */
#[AsMessageHandler]
final readonly class AgentRunQueuedHandler
{
    public function __construct(
        private AgentRuntimeInterface $agentRuntime,
        private ?LoggerInterface $logger = null,
    ) {}

    public function __invoke(AgentRunQueuedMessage $message): void
    {
        if ($this->agentRuntime->runQueued($message->runUuid) === null) {
            $this->logger?->info('Queued agent run was not claimable (already claimed, cancelled, or unknown)', [
                'run' => $message->runUuid,
            ]);
        }
    }
}
