<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Feature;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\AiSession;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Option\ChatOptions;

/**
 * Public surface of the stateful conversation service (ADR-083).
 *
 * Wraps stateless completion with a persisted message history: a session is
 * opened once, and each {@see self::send()} replays the prior turns to the
 * provider and stores the new user turn and the assistant reply. Consumers
 * depend on this interface so the implementation can be substituted.
 */
interface ConversationServiceInterface
{
    /**
     * Open a new conversation session owned by the current backend user.
     */
    public function startSession(string $title = '', ?LlmConfiguration $configuration = null): AiSession;

    /**
     * Send a user message into an existing session and return the assistant's
     * reply, persisting both turns.
     *
     * @throws InvalidArgumentException when the session uuid is unknown
     */
    public function send(string $sessionUuid, string $userMessage, ?ChatOptions $options = null): CompletionResponse;
}
