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
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\AiSession;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Exception\AccessDeniedException;
use Netresearch\NrLlm\Exception\ConfigurationInactiveException;
use Netresearch\NrLlm\Exception\ConfigurationNotFoundException;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Context\ContextWindowManagerInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\Session\AiSessionRepositoryInterface;
use Psr\Log\LoggerInterface;
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
 *
 * Two invariants hold on every turn:
 * - **Ownership.** The actor must own the session, be an administrator, or be a
 *   service account. A session uuid is an identifier, never an authorisation.
 * - **Configuration binding.** The turn runs against the configuration the
 *   session was opened with, resolved fresh each time so a deactivated or
 *   newly restricted configuration stops the conversation instead of silently
 *   continuing on the installation default.
 */
final readonly class ConversationService implements ConversationServiceInterface
{
    public function __construct(
        private LlmServiceManagerInterface $llmManager,
        private AiSessionRepositoryInterface $sessions,
        private ConfigurationResolver $configurationResolver,
        // Bounds the replayed transcript against the model's context window
        // (ADR-121). Optional so the existing lean test wiring keeps working;
        // absent it a conversation grows unbounded exactly as before.
        private ?ContextWindowManagerInterface $contextWindow = null,
        private ?LoggerInterface $logger = null,
    ) {}

    public function startSession(AiActorContext $actor, string $title = '', ?LlmConfiguration $configuration = null): AiSession
    {
        if (!$actor->isAuthenticated()) {
            throw new AccessDeniedException(
                'A conversation session cannot be opened for an unauthenticated caller.',
                1784600004,
            );
        }

        $uuid = Uuid::v4()->toRfc4122();
        $this->sessions->startSession($uuid, $actor->backendUserUid, $configuration?->getIdentifier() ?? '', $title);

        $session = $this->sessions->findByUuid($uuid);
        if (!$session instanceof AiSession) {
            throw new RuntimeException('The conversation session could not be loaded immediately after creation.', 1784600002);
        }

        return $session;
    }

    public function send(AiActorContext $actor, string $sessionUuid, string $userMessage, ?ChatOptions $options = null): CompletionResponse
    {
        $session = $this->sessions->findByUuid($sessionUuid);
        if (!$session instanceof AiSession) {
            throw new InvalidArgumentException(sprintf('Unknown AI session "%s".', $sessionUuid), 1784600001);
        }

        if (!$actor->mayAccessSession($session)) {
            throw new AccessDeniedException(
                sprintf('%s may not continue this conversation session.', ucfirst($actor->describe())),
                1784600005,
            );
        }

        $options = $this->attributeToActor($options ?? new ChatOptions(), $actor);
        $history = $this->sessions->findMessages($session->uid);

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

        // A conversation replays its whole history on every turn, so it is the
        // one path that grows without bound. Bound it here (ADR-121): the
        // oldest turns are dropped from what is SENT, never from what is
        // stored. The count is persisted on the user row below so a trimmed
        // turn is not merely a log line.
        $configuration = $this->resolveTurnConfiguration($session, $actor);
        $droppedTurns  = null;
        if ($this->contextWindow instanceof ContextWindowManagerInterface && $configuration instanceof LlmConfiguration) {
            // lastUsage is null: each turn is a fresh assembly, so the
            // manager's calibration starts from its seed rather than carrying a
            // ratio over from an unrelated turn.
            $fit          = $this->contextWindow->fit($messages, $configuration, $options, null, []);
            $messages     = $fit->messages;
            $droppedTurns = $fit->droppedTurns;

            if ($fit->overflowAtFloor) {
                // Nothing left to drop and it still does not fit. Send it: the
                // estimate errs high, so this may well succeed, and if it does
                // not the provider's own error is what the caller would have
                // got anyway. Refusing here would end a conversation that the
                // provider might still have answered.
                $this->logger?->warning('Conversation transcript does not fit even at its floor; sending it unpruned', [
                    'session'         => $session->uid,
                    'droppedTurns'    => $fit->droppedTurns,
                    'keptTurns'       => $fit->keptTurns,
                    'estimatedTokens' => $fit->estimatedTokens,
                    'budget'          => $fit->budget,
                ]);
            } elseif ($fit->pruned) {
                $this->logger?->info('Conversation transcript trimmed to fit the context window', [
                    'session'         => $session->uid,
                    'droppedTurns'    => $fit->droppedTurns,
                    'keptTurns'       => $fit->keptTurns,
                    'estimatedTokens' => $fit->estimatedTokens,
                    'budget'          => $fit->budget,
                ]);
            }
        }

        // Persist the user turn before the call: it is a real turn regardless of
        // whether the provider then succeeds. The repository allocates the
        // sequence, so two concurrent turns cannot collide on one slot.
        $userSequence = $this->sessions->appendMessageAtNextSequence(
            $session->uid,
            MessageRole::USER->value,
            $userMessage,
            '',
            0,
            0,
            0,
            $droppedTurns,
        );
        $this->sessions->touch($session->uid, $userSequence + 1);

        $response = $this->dispatch($messages, $configuration, $options);

        $assistantSequence = $this->sessions->appendMessageAtNextSequence(
            $session->uid,
            MessageRole::ASSISTANT->value,
            $response->content,
            $response->model,
            $response->usage->promptTokens,
            $response->usage->completionTokens,
            $response->usage->totalTokens,
        );
        $this->sessions->touch($session->uid, $assistantSequence + 1);

        return $response;
    }

    /**
     * Run the turn against the configuration resolved for it.
     *
     * A null configuration is a session opened without one: it keeps the
     * generic path, which resolves the installation default — the pre-ADR-083
     * behaviour for callers that never chose a configuration.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     */
    private function dispatch(array $messages, ?LlmConfiguration $configuration, ChatOptions $options): CompletionResponse
    {
        if (!$configuration instanceof LlmConfiguration) {
            return $this->llmManager->chat($messages, $options);
        }

        return $this->llmManager->chatForConfiguration($messages, $configuration, $options);
    }

    /**
     * The configuration this turn runs against, or null for a session opened
     * without one (the pre-ADR-083 generic path, which resolves the
     * installation default inside the manager).
     *
     * Resolved once per turn and before the transcript is assembled, because
     * the context bound depends on the model it will actually be sent to.
     *
     * @throws AccessDeniedException when the bound configuration is gone, deactivated, or no longer open to the actor
     */
    private function resolveTurnConfiguration(AiSession $session, AiActorContext $actor): ?LlmConfiguration
    {
        if ($session->configurationIdentifier === '') {
            return null;
        }

        try {
            return $this->configurationResolver->getActiveByIdentifierForActor($session->configurationIdentifier, $actor);
        } catch (ConfigurationNotFoundException|ConfigurationInactiveException $unusable) {
            // Not found or deactivated: the conversation was opened against a
            // configuration that no longer exists or was switched off. Silently
            // continuing on the installation default would run the session on a
            // different model, budget and guardrail set than it started with.
            throw new AccessDeniedException(
                sprintf(
                    'The configuration "%s" this session was opened with is no longer usable: %s',
                    $session->configurationIdentifier,
                    $unusable->getMessage(),
                ),
                1784600006,
                $unusable,
            );
        }
    }

    /**
     * Attribute the turn to the acting backend user unless the caller already
     * set an explicit owner, so per-user budgets apply to conversations exactly
     * as they do to one-shot completions.
     */
    private function attributeToActor(ChatOptions $options, AiActorContext $actor): ChatOptions
    {
        if ($options->getBeUserUid() !== null || $actor->backendUserUid <= 0) {
            return $options;
        }

        return $options->withBeUserUid($actor->backendUserUid);
    }
}
