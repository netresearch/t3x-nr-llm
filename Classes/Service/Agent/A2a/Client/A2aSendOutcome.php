<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Client;

use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendIntent;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendState;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aRemoteError;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aSendMessageResult;

/**
 * What {@see A2aClient::sendMessage()} did (spec 051 A2A-C3 to A2A-C6).
 *
 * `$intent` is the journal row and its state is the outcome. `$sent` is false
 * when the intent already existed: the same owner sent the same request under
 * the same message id before, and nothing was sent this time (A2A-C5). Then
 * `$result` is null; the stored intent says what came of the first send.
 *
 * `$result` is set for `accepted` (a task) and `answered` (a message): the
 * caller has to handle both. `$remoteError` is set for a JSON-RPC error.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aSendOutcome
{
    public function __construct(
        public A2aSendIntent $intent,
        public bool $sent,
        public ?A2aSendMessageResult $result = null,
        public ?A2aRemoteError $remoteError = null,
    ) {}

    public function state(): A2aSendState
    {
        return $this->intent->state;
    }
}
