<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Client;

use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendIntent;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aRemoteError;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTaskState;

/**
 * How a stream of {@see A2aClient::sendStreamingMessage()} or
 * {@see A2aClient::subscribeToTask()} ended (spec 051 A2A-SSE6, A2A-C6).
 *
 * `$intent` is the journal row: for a send its state is the outcome, as for
 * `SendMessage`; `$sent` is false when the intent already existed and nothing
 * was sent (A2A-C5). `$events` counts the events handed to the caller.
 * `$endReason` is '' for a stream the remote closed and the transport's or
 * the client's reason for any other end. `$lastState` is the last task state
 * the stream carried, '' when it carried none.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aStreamOutcome
{
    public function __construct(
        public A2aSendIntent $intent,
        public bool $sent,
        public int $events = 0,
        public string $endReason = '',
        public string $lastState = '',
        public ?A2aRemoteError $remoteError = null,
    ) {}

    /**
     * Whether the caller has to continue by polling (`GetTask`): the remote
     * task is known and the stream ended before a terminal or interrupted
     * state, by a disconnect, a time limit or the remote's own close.
     */
    public function fallsBackToPolling(): bool
    {
        if ($this->intent->remoteTaskId === '') {
            return false;
        }

        $state = A2aTaskState::tryFrom($this->lastState);

        return !$state instanceof A2aTaskState || (!$state->isTerminal() && !$state->isInterrupted());
    }
}
