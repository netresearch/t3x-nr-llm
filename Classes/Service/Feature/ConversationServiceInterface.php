<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Feature;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\AiSession;
use Netresearch\NrLlm\Exception\AccessDeniedException;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Option\ChatOptions;

/**
 * Public surface of the stateful conversation service (ADR-083).
 *
 * Wraps stateless completion with a persisted message history: a session is
 * opened once, and each {@see self::send()} replays the prior turns to the
 * provider and stores the new user turn and the assistant reply. Consumers
 * depend on this interface so the implementation can be substituted.
 *
 * Every entry point takes an explicit {@see AiActorContext}. A session belongs
 * to a backend user, and knowing its uuid is not authorisation — the actor is
 * checked against the owner on every turn. CLI, scheduler and queue callers
 * identify themselves as a service account instead of borrowing whichever
 * backend user happens to be logged in.
 */
interface ConversationServiceInterface
{
    /**
     * Open a new conversation session owned by the given actor.
     *
     * The configuration is bound to the session: every later turn runs against
     * it, not against whatever the installation default happens to be.
     *
     * @throws AccessDeniedException when the actor is not authenticated
     */
    public function startSession(AiActorContext $actor, string $title = '', ?LlmConfiguration $configuration = null): AiSession;

    /**
     * Send a user message into an existing session and return the assistant's
     * reply, persisting both turns.
     *
     * @throws InvalidArgumentException when the session uuid is unknown
     * @throws AccessDeniedException    when the actor neither owns the session nor is entitled to it, or when the session's configuration is no longer usable
     */
    public function send(AiActorContext $actor, string $sessionUuid, string $userMessage, ?ChatOptions $options = null): CompletionResponse;
}
