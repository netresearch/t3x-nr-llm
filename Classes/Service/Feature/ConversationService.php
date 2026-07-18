<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Feature;

use Netresearch\NrLlm\Domain\Enum\MessageRole;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\AiSession;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Budget\BackendUserContextResolverInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\Session\AiSessionRepositoryInterface;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * Stateful conversation service (ADR-083).
 *
 * Turns the stateless completion path into a multi-turn conversation: prior
 * turns are loaded from `tx_nrllm_ai_session_message` and replayed to the
 * provider, and the new user turn plus the assistant reply are persisted. The
 * provider call itself is unchanged — this only assembles the message array and
 * records the turns around it.
 */
final readonly class ConversationService implements ConversationServiceInterface
{
    public function __construct(
        private LlmServiceManagerInterface $llmManager,
        private AiSessionRepositoryInterface $sessions,
        private ?BackendUserContextResolverInterface $beUserContextResolver = null,
    ) {}

    public function startSession(string $title = '', ?LlmConfiguration $configuration = null): AiSession
    {
        $uuid   = Uuid::v4()->toRfc4122();
        $beUser = $this->beUserContextResolver?->resolveBeUserUid() ?? 0;

        $this->sessions->startSession($uuid, $beUser, $configuration?->getIdentifier() ?? '', $title);

        $session = $this->sessions->findByUuid($uuid);
        if ($session === null) {
            throw new RuntimeException('The conversation session could not be loaded immediately after creation.', 1784600002);
        }

        return $session;
    }

    public function send(string $sessionUuid, string $userMessage, ?ChatOptions $options = null): CompletionResponse
    {
        $session = $this->sessions->findByUuid($sessionUuid);
        if ($session === null) {
            throw new InvalidArgumentException(sprintf('Unknown AI session "%s".', $sessionUuid), 1784600001);
        }

        $options ??= new ChatOptions();
        $history   = $this->sessions->findMessages($session->uid);

        $messages = [];
        // Prepend the system prompt on every turn: it is never persisted in the
        // session history (only user and assistant turns are), so re-adding it
        // does not duplicate it — and omitting it would drop the system
        // instructions from the second turn onward.
        $systemPrompt = $options->toArray()['system_prompt'] ?? null;
        if (is_string($systemPrompt) && $systemPrompt !== '') {
            $messages[] = ChatMessage::system($systemPrompt);
        }
        foreach ($history as $message) {
            $messages[] = $message->toChatMessage();
        }
        $messages[] = ChatMessage::user($userMessage);

        $nextSequence = $session->messageCount;
        // Persist the user turn before the call: it is a real turn regardless of
        // whether the provider then succeeds. Advance the message count right
        // away so a failed call cannot leave the next turn reusing this sequence.
        $this->sessions->appendMessage($session->uid, $nextSequence, MessageRole::USER->value, $userMessage, '', 0, 0, 0);
        $this->sessions->touch($session->uid, $nextSequence + 1);

        $response = $this->llmManager->chat($messages, $options);

        $this->sessions->appendMessage(
            $session->uid,
            $nextSequence + 1,
            MessageRole::ASSISTANT->value,
            $response->content,
            $response->model,
            $response->usage->promptTokens,
            $response->usage->completionTokens,
            $response->usage->totalTokens,
        );
        $this->sessions->touch($session->uid, $nextSequence + 2);

        return $response;
    }
}
