<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Queue;

/**
 * "A queued agent run is ready to execute" (ADR-102).
 *
 * Deliberately carries only the run uuid: the full request payload lives on
 * the run row (`queued_request`), where the atomic claim, cancellation and a
 * later requeue can all see it. The message is a wake-up call, not the state —
 * a duplicate or stale message is harmless because the claim decides. Plain
 * scalars only: TYPO3's messenger uses PHP serialization for the doctrine
 * transport, so the message must never hold services or entities.
 */
final readonly class AgentRunQueuedMessage
{
    public function __construct(
        public string $runUuid,
    ) {}
}
