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
use Netresearch\NrLlm\Domain\ValueObject\ConfigurationIdentifier;
use Netresearch\NrLlm\Domain\ValueObject\ModelResolution;
use Netresearch\NrLlm\Exception\AccessDeniedException;
use Netresearch\NrLlm\Exception\ConfigurationInactiveException;
use Netresearch\NrLlm\Exception\ConfigurationNotFoundException;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Context\ContextWindowManagerInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\ModelSelectionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\Prompt\ConfigurationSnippetResolver;
use Netresearch\NrLlm\Service\Session\AiSessionRepositoryInterface;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Skill\SkillInjectionService;
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
 *   continuing on the installation default. Every session HAS one from the
 *   moment it is opened (ADR-188): a caller who names none gets the
 *   installation default resolved at that moment, persisted, and used by turn 1
 *   as well as turn 2. Only a session opened before that rule can still be
 *   unbound, and it binds itself once on its next turn.
 *
 * @api
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
        // Composes the same skill block LlmServiceManager injects after this
        // service has already fitted the transcript, so its size can be counted
        // against the budget. Optional for the same reason as the manager
        // above; absent it the block is unaccounted for, exactly as before.
        private ?SkillComposer $skillComposer = null,
        // Only reader: the effective system prompt handed to the context
        // window below. The manager composes the configuration's tag-selected
        // snippets (ADR-031) into the prompt it sends, so the estimate has to
        // know about them or it budgets for a smaller message than it sends.
        private ?ConfigurationSnippetResolver $snippetResolver = null,
        // Answers which model this turn will run on, so the fit below budgets
        // against that model's window instead of the 8192-token fallback a
        // criteria-mode configuration otherwise gets (#922). Optional like the
        // three above; absent it the fit behaves exactly as it did before.
        private ?ModelSelectionServiceInterface $modelSelection = null,
    ) {}

    public function startSession(AiActorContext $actor, string $title = '', ?LlmConfiguration $configuration = null): AiSession
    {
        if (!$actor->isAuthenticated()) {
            throw new AccessDeniedException(
                'A conversation session cannot be opened for an unauthenticated caller.',
                1784600004,
            );
        }

        // ADR-188: a session is bound to a configuration at the moment it is
        // opened, never later. Everything computed for turn 1 — the
        // context-window fit above all, and the skill block it budgets for — is
        // computed against THIS configuration, and turn 2 uses the same one
        // because the identifier is persisted rather than re-resolved.
        $configuration ??= $this->configurationResolver->resolveDefaultForActor($actor);
        if (!$configuration instanceof LlmConfiguration) {
            throw new ConfigurationNotFoundException(
                sprintf(
                    'A conversation cannot be opened without a configuration: none was given, and %s has no usable '
                    . 'installation default (there is none, it carries no model, or its access restrictions exclude '
                    . 'this caller).',
                    $actor->describe(),
                ),
                1788600001,
            );
        }

        $uuid = Uuid::v4()->toRfc4122();
        $this->sessions->startSession($uuid, $actor->backendUserUid, $configuration->getIdentifier(), $title);

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
        // One routing decision per turn, taken here and carried to both the
        // fit below and the dispatch further down (#922).
        $resolution   = $configuration instanceof LlmConfiguration ? $this->resolveTurnModel($configuration) : null;
        $droppedTurns = null;
        if ($this->contextWindow instanceof ContextWindowManagerInterface && $configuration instanceof LlmConfiguration) {
            // lastUsage is null: each turn is a fresh assembly, so the
            // manager's calibration starts from its seed rather than carrying a
            // ratio over from an unrelated turn.
            //
            // The skill block is composed ONCE here and passed as payload the
            // send carries outside this list: LlmServiceManager injects it into
            // the first user message AFTER this fit (#625). The injection stays
            // there deliberately — the first user turn is the never-droppable
            // HEAD, so injecting before the fit would spend the whole history
            // on making room and still overflow.
            //
            // The effective system prompt only matters when this turn carries
            // no system message of its own; then the manager prepends the
            // configuration's prompt WITH its composed snippets, and that is
            // what has to be counted.
            $fit = $this->contextWindow->fit(
                $messages,
                $configuration,
                $options,
                null,
                [],
                $this->skillBlockFor($configuration),
                $this->snippetResolver?->appendTo($configuration->getSystemPrompt(), $configuration),
                $resolution?->model,
            );
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

        $response = $this->dispatch($messages, $configuration, $options, $resolution);

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
     * Bind a session opened before every session carried a configuration
     * (ADR-188), once, on its next turn.
     *
     * No upgrade wizard: the identifier a wizard would write is the
     * installation default at the moment it RUNS, which for a conversation
     * nobody continues is a decision made for nothing, and for one that is
     * continued is the same decision this makes — one turn later and with the
     * actor in hand, so an access-restricted default is evaluated rather than
     * guessed at.
     *
     * Returns null when the installation still has no usable default. That
     * leaves the session on the pre-ADR-188 generic path rather than ending a
     * conversation someone is in the middle of: the binding is an improvement
     * to an existing row, and an improvement that cannot be made is not a
     * reason to refuse the turn.
     *
     * Returns the identifier that is ON THE ROW afterwards, which is not
     * necessarily the one this turn resolved. The write is conditional on the
     * row still being unbound, so a concurrent turn may have bound it first —
     * and if the installation default changed between the two reads, that turn
     * bound a different configuration. The row is the binding; a turn that lost
     * the race must fit and dispatch against what is persisted rather than
     * against what it happened to resolve, or ADR-188's "one configuration per
     * session for its whole life" would hold for the row and not for the run.
     */
    private function bindLegacySession(AiSession $session, AiActorContext $actor): ?string
    {
        $configuration = $this->configurationResolver->resolveDefaultForActor($actor);
        // A blank identifier is a malformed row -- the TCA marks the field
        // required, so this arrives only through an import or a direct write.
        // Binding it would write the very sentinel that means "unbound" onto
        // the row, so the session would re-bind on every turn and the value
        // would then be handed to ConfigurationIdentifier, which refuses it
        // (#893). Treated as "no default to bind to", which is what it is.
        if (!$configuration instanceof LlmConfiguration || $configuration->getIdentifier() === '') {
            return null;
        }

        $this->sessions->bindConfiguration($session->uid, $configuration->getIdentifier());

        $bound = $this->sessions->findByUuid($session->uuid);

        return $bound instanceof AiSession && $bound->configurationIdentifier !== ''
            ? $bound->configurationIdentifier
            : $configuration->getIdentifier();
    }

    /**
     * The one routing decision this turn is allowed to take.
     *
     * `fit()` reads `$resolvedModel ?? $configuration->getLlmModel()`. A
     * criteria-mode configuration carries no model by design, so passing
     * nothing made the fit fall back to UNKNOWN_WINDOW_FALLBACK -- 8192
     * tokens -- while the send resolved a concrete model from the criteria
     * afterwards and fitted again against ITS window. Where the resolved model
     * had the larger window, the first fit had already dropped history the
     * second would have kept, and `droppedTurns` recorded that on the user row
     * as if it had been necessary (#922, ADR-121).
     *
     * This is the AUTHORITATIVE resolution -- the same `resolveModelForCall()`
     * the manager's terminal would otherwise run, with its observed-capability
     * report and its refusal of an unservable operation. The result travels
     * with the dispatch so the terminal records its routing summary and does
     * not evaluate a second time. Two evaluations moments apart can disagree
     * about which model the turn runs on, which is the hazard
     * {@see \Netresearch\NrLlm\Service\ConfigurationCallPlanner::resolveModel()}
     * names in its own docblock; one decision per turn is the only shape that
     * cannot.
     *
     * Nothing is caught here. An unservable configuration now fails before the
     * user row is written rather than one step after it, which is the more
     * honest ordering: the row records a turn that was sent, and this one
     * never could be.
     *
     * Null only where the service is absent, which is the lean wiring the
     * three optional collaborators above already describe; the fit then
     * behaves exactly as it did before.
     */
    private function resolveTurnModel(LlmConfiguration $configuration): ?ModelResolution
    {
        return $this->modelSelection?->resolveModelForCall($configuration, ProviderOperation::Chat);
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
    private function dispatch(array $messages, ?LlmConfiguration $configuration, ChatOptions $options, ?ModelResolution $resolution): CompletionResponse
    {
        if (!$configuration instanceof LlmConfiguration) {
            return $this->llmManager->chat($messages, $options);
        }

        return $this->llmManager->chatForConfiguration($messages, $configuration, $options, $resolution);
    }

    /**
     * The skill block this turn's send will carry, composed once.
     *
     * {@see \Netresearch\NrLlm\Service\LlmServiceManager::chatForConfiguration()}
     * prepends it to the first user message after this service has fitted the
     * transcript, so the fit has to know its size or the budget binds a list
     * that is never sent that way (#625). Composition is deterministic over the
     * configuration's skills, so the manager's own composition of the same set
     * yields the same block.
     *
     * Known limit: the null-configuration branch of {@see self::dispatch()} is
     * not covered. There the manager resolves the installation default itself
     * and injects that configuration's skills — this service never learns which
     * configuration that is, so it cannot account for its block.
     */
    private function skillBlockFor(LlmConfiguration $configuration): string
    {
        if (!$this->skillComposer instanceof SkillComposer) {
            return '';
        }

        return $this->skillComposer->composeBlock(
            SkillInjectionService::toList($configuration->getSkills()),
            [],
        )->block;
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
        $identifier = $session->configurationIdentifier === ''
            ? $this->bindLegacySession($session, $actor)
            : $session->configurationIdentifier;

        if ($identifier === null) {
            return null;
        }

        try {
            return $this->configurationResolver->getActiveByIdentifierForActor(
                new ConfigurationIdentifier($identifier),
                $actor,
            );
        } catch (ConfigurationNotFoundException|ConfigurationInactiveException $unusable) {
            // Not found or deactivated: the conversation was opened against a
            // configuration that no longer exists or was switched off. Silently
            // continuing on the installation default would run the session on a
            // different model, budget and guardrail set than it started with.
            throw new AccessDeniedException(
                sprintf(
                    'The configuration "%s" this session was opened with is no longer usable: %s',
                    $identifier,
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
