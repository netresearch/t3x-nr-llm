<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Feature;

use Netresearch\NrLlm\Domain\Enum\RoutingPolicyMode;
use Netresearch\NrLlm\Domain\Enum\ServiceAccountScope;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\AiSessionMessage;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ContextBudgetBreakdown;
use Netresearch\NrLlm\Domain\ValueObject\ContextFitResult;
use Netresearch\NrLlm\Domain\ValueObject\RoutingDecision;
use Netresearch\NrLlm\Domain\ValueObject\RoutingReadout;
use Netresearch\NrLlm\Exception\AccessDeniedException;
use Netresearch\NrLlm\Exception\ConfigurationNotFoundException;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Context\ContextWindowManagerInterface;
use Netresearch\NrLlm\Service\Feature\ConversationService;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\ModelSelectionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\Prompt\ConfigurationSnippetResolver;
use Netresearch\NrLlm\Service\Prompt\PromptSnippetComposer;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Tests\Unit\Service\Session\Fixtures\RecordingAiSessionRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(ConversationService::class)]
final class ConversationServiceTest extends TestCase
{
    private const OWNER = 42;

    private const DEFAULT_IDENTIFIER = 'installation-default';

    #[Test]
    public function sendPersistsBothTurnsAndReturnsTheReply(): void
    {
        $repository = new RecordingAiSessionRepository();
        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->expects(self::once())->method('chatForConfiguration')->willReturn($this->response('Hello there'));
        $service = $this->service($llmManager, $repository);

        $actor    = $this->owner();
        $session  = $service->startSession($actor, 'greeting');
        $response = $service->send($actor, $session->uuid, 'Hi');

        self::assertSame('Hello there', $response->content);

        $messages = $repository->findMessages($session->uid);
        self::assertCount(2, $messages);
        self::assertSame('user', $messages[0]->role);
        self::assertSame('Hi', $messages[0]->content);
        self::assertSame('assistant', $messages[1]->role);
        self::assertSame('Hello there', $messages[1]->content);

        // The session's activity summary advanced by both turns.
        $reloaded = $repository->findByUuid($session->uuid);
        self::assertNotNull($reloaded);
        self::assertSame(2, $reloaded->messageCount);
    }

    #[Test]
    public function aForeignBackendUserCannotContinueSomebodyElsesSession(): void
    {
        $repository = new RecordingAiSessionRepository();
        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->expects(self::never())->method('chat');
        $llmManager->expects(self::never())->method('chatForConfiguration');
        $service = $this->service($llmManager, $repository);

        $session = $service->startSession($this->owner());

        // Knowing the uuid is not authorisation.
        $this->expectException(AccessDeniedException::class);
        $service->send(AiActorContext::backendUser(43), $session->uuid, 'let me in');
    }

    #[Test]
    public function anAdministratorMayContinueAnySession(): void
    {
        $repository = new RecordingAiSessionRepository();
        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatForConfiguration')->willReturn($this->response('ok'));
        $service = $this->service($llmManager, $repository);

        $session  = $service->startSession($this->owner());
        $response = $service->send(AiActorContext::backendUser(1, isAdmin: true), $session->uuid, 'audit');

        self::assertSame('ok', $response->content);
    }

    #[Test]
    public function aServiceAccountWithTheConversationScopeMayContinueASessionOnTheSystemsBehalf(): void
    {
        $repository = new RecordingAiSessionRepository();
        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatForConfiguration')->willReturn($this->response('ok'));
        $service = $this->service($llmManager, $repository);

        $worker   = AiActorContext::serviceAccount('nrllm-worker', [ServiceAccountScope::CONVERSATION_ACCESS]);
        $session  = $service->startSession($this->owner());
        $response = $service->send($worker, $session->uuid, 'resume');

        self::assertSame('ok', $response->content);
    }

    #[Test]
    public function aServiceAccountWithoutTheConversationScopeMayNotContinueASession(): void
    {
        $repository = new RecordingAiSessionRepository();
        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->expects(self::never())->method('chat');
        $llmManager->expects(self::never())->method('chatForConfiguration');
        $service = $this->service($llmManager, $repository);

        $session = $service->startSession($this->owner());

        $this->expectException(AccessDeniedException::class);
        // A scopeless service account is fail-closed (ADR-110): knowing the
        // session uuid is not enough to continue somebody else's conversation.
        $service->send(AiActorContext::serviceAccount('nrllm-worker'), $session->uuid, 'resume');
    }

    #[Test]
    public function anAnonymousCallerCanNeitherOpenNorContinueASession(): void
    {
        $repository = new RecordingAiSessionRepository();
        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->expects(self::never())->method('chat');
        $llmManager->expects(self::never())->method('chatForConfiguration');
        $service = $this->service($llmManager, $repository);

        $session = $service->startSession($this->owner());

        try {
            $service->startSession(AiActorContext::anonymous());
            self::fail('An unauthenticated caller must not open a session.');
        } catch (AccessDeniedException) {
            // expected
        }

        $this->expectException(AccessDeniedException::class);
        $service->send(AiActorContext::anonymous(), $session->uuid, 'hi');
    }

    #[Test]
    public function aTurnRunsAgainstTheConfigurationTheSessionWasOpenedWith(): void
    {
        $repository    = new RecordingAiSessionRepository();
        $configuration = $this->configuration('editorial');

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->expects(self::never())->method('chat');
        $llmManager->expects(self::once())
            ->method('chatForConfiguration')
            ->with(self::anything(), self::identicalTo($configuration), self::anything())
            ->willReturn($this->response('bound'));

        $service = new ConversationService($llmManager, $repository, $this->resolverReturning($configuration));
        $actor   = $this->owner();

        $session  = $service->startSession($actor, '', $configuration);
        $response = $service->send($actor, $session->uuid, 'write a teaser');

        self::assertSame('bound', $response->content);
    }

    /**
     * ADR-188: omitting the configuration does not mean "unbound", it means
     * "the installation default, resolved now". The identifier is on the row
     * from the moment it is written.
     */
    #[Test]
    public function aSessionOpenedWithoutAConfigurationIsBoundToTheInstallationDefault(): void
    {
        $repository = new RecordingAiSessionRepository();
        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatForConfiguration')->willReturn($this->response('ok'));

        $session = $this->service($llmManager, $repository)->startSession($this->owner());

        self::assertSame(self::DEFAULT_IDENTIFIER, $repository->sessions[$session->uid]['configId']);
    }

    /**
     * The alternative would be a row with an empty identifier, which is the
     * state ADR-188 exists to remove — and the caller would learn about the
     * missing default one turn later, from a different error.
     */
    #[Test]
    public function aSessionCannotBeOpenedWhenTheInstallationHasNoUsableDefault(): void
    {
        $repository = new RecordingAiSessionRepository();
        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->expects(self::never())->method('chat');
        $llmManager->expects(self::never())->method('chatForConfiguration');

        $service = new ConversationService($llmManager, $repository, $this->resolverWithDefault(null));

        try {
            $service->startSession($this->owner());
            self::fail('Expected the session to be refused for want of a configuration.');
        } catch (ConfigurationNotFoundException $e) {
            self::assertSame(1788600001, $e->getCode());
        }

        self::assertSame([], $repository->sessions, 'No row may be written for a session that cannot be bound.');
    }

    /**
     * The point of persisting the identifier rather than re-resolving it: an
     * operator switching the installation default must not silently move
     * running conversations onto another model, budget and guardrail set.
     */
    #[Test]
    public function changingTheInstallationDefaultLeavesAnExistingConversationWhereItIs(): void
    {
        $repository = new RecordingAiSessionRepository();
        $llmManager = self::createStub(LlmServiceManagerInterface::class);

        $original = $this->defaultConfiguration();
        $service  = new ConversationService($llmManager, $repository, $this->resolverWithDefault($original));
        $actor    = $this->owner();
        $session  = $service->startSession($actor);

        // The operator switches the default. A service built now sees the new
        // one — and the session opened against the old one keeps it.
        $replacement = $this->configuration('the-new-default');
        $replacement->setIsActive(true);
        $replacement->setLlmModel(new Model());

        $afterwards = $this->createMock(LlmServiceManagerInterface::class);
        $afterwards->expects(self::once())
            ->method('chatForConfiguration')
            ->with(self::anything(), self::identicalTo($original), self::anything())
            ->willReturn($this->response('still the original'));

        $repositoryStub = self::createStub(LlmConfigurationRepository::class);
        $repositoryStub->method('findDefault')->willReturn($replacement);
        $repositoryStub->method('findOneByIdentifier')->willReturnCallback(
            static fn(string $identifier): ?LlmConfiguration => match ($identifier) {
                self::DEFAULT_IDENTIFIER => $original,
                'the-new-default'        => $replacement,
                default                  => null,
            },
        );

        $continued = new ConversationService($afterwards, $repository, new ConfigurationResolver($repositoryStub));

        self::assertSame('still the original', $continued->send($actor, $session->uuid, 'carry on')->content);
    }

    /**
     * A row written before ADR-188 binds itself on its next turn, once, and
     * with the actor in hand — which is why no upgrade wizard does it.
     */
    #[Test]
    public function aLegacySessionWithoutAConfigurationBindsItselfOnItsNextTurn(): void
    {
        $repository = new RecordingAiSessionRepository();
        $uid        = $repository->startSession('legacy-uuid', self::OWNER, '', 'opened before ADR-188');

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatForConfiguration')->willReturn($this->response('bound now'));

        $actor   = $this->owner();
        $service = $this->service($llmManager, $repository);

        self::assertSame('bound now', $service->send($actor, 'legacy-uuid', 'continue')->content);
        self::assertSame(self::DEFAULT_IDENTIFIER, $repository->sessions[$uid]['configId']);

        // And once: the second turn resolves the identifier that is now on the
        // row and does not bind again.
        $service->send($actor, 'legacy-uuid', 'and again');
        self::assertCount(1, $repository->bindCalls);
    }

    /**
     * The bind is conditional on the row still being unbound, so a concurrent
     * turn can win it — and if the installation default moved between the two
     * reads, it bound something else. The turn that lost must then fit and
     * dispatch against what is PERSISTED, not against what it happened to
     * resolve, or ADR-188 would hold for the row and not for the run.
     */
    #[Test]
    public function aLegacyBindThatLosesTheRaceFollowsThePersistedConfiguration(): void
    {
        $repository = new RecordingAiSessionRepository();
        $uid        = $repository->startSession('legacy-uuid', self::OWNER, '', 'opened before ADR-188');

        // The concurrent turn wins the conditional write: the row is still
        // empty when this turn reads it, and bound by the time this turn's own
        // write reaches the database.
        $repository->bindRaceWinner = 'bound-by-the-other-turn';

        $winner = $this->configuration('bound-by-the-other-turn');
        $winner->setIsActive(true);
        $winner->setLlmModel(new Model());

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->expects(self::once())
            ->method('chatForConfiguration')
            ->with(self::anything(), self::identicalTo($winner), self::anything())
            ->willReturn($this->response('the other turn decided'));

        $stub = self::createStub(LlmConfigurationRepository::class);
        $stub->method('findDefault')->willReturn($this->defaultConfiguration());
        $stub->method('findOneByIdentifier')->willReturnCallback(
            static fn(string $identifier): ?LlmConfiguration => $identifier === 'bound-by-the-other-turn' ? $winner : null,
        );

        $service = new ConversationService($llmManager, $repository, new ConfigurationResolver($stub));

        self::assertSame(
            'the other turn decided',
            $service->send($this->owner(), 'legacy-uuid', 'continue')->content,
        );

        // And the row still carries the winner: this turn's own write was the
        // no-op it is supposed to be.
        self::assertSame('bound-by-the-other-turn', $repository->sessions[$uid]['configId']);
    }

    /**
     * The gap ADR-188 closes, measured where it hurt: turn 1's fit ran against
     * no configuration at all, so the transcript it sent was budgeted against
     * nothing. Both turns now report the same one.
     */
    #[Test]
    public function turnOneIsFittedAgainstTheSameConfigurationAsTurnTwo(): void
    {
        $repository = new RecordingAiSessionRepository();
        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatForConfiguration')->willReturn($this->response('ok'));

        $fitted       = [];
        $contextWindow = self::createStub(ContextWindowManagerInterface::class);
        $contextWindow->method('fit')->willReturnCallback(
            function (array $messages, LlmConfiguration $configuration) use (&$fitted): ContextFitResult {
                $fitted[] = $configuration->getIdentifier();

                /** @var list<ChatMessage|array<string, mixed>> $sent */
                $sent = array_values($messages);

                return new ContextFitResult($sent, false, 0, count($sent), 10, 100, false, 1.0, ContextBudgetBreakdown::none());
            },
        );

        $actor   = $this->owner();
        $service = new ConversationService(
            $llmManager,
            $repository,
            $this->resolverWithDefault($this->defaultConfiguration()),
            $contextWindow,
        );

        $session = $service->startSession($actor);
        $service->send($actor, $session->uuid, 'first');
        $service->send($actor, $session->uuid, 'second');

        self::assertSame([self::DEFAULT_IDENTIFIER, self::DEFAULT_IDENTIFIER], $fitted);
    }

    #[Test]
    public function aSessionWhoseConfigurationWasDeactivatedStopsInsteadOfFallingBackToTheDefault(): void
    {
        $repository    = new RecordingAiSessionRepository();
        $configuration = $this->configuration('editorial');

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->expects(self::never())->method('chat');
        $llmManager->expects(self::never())->method('chatForConfiguration');

        $configuration->setIsActive(false);
        $service = new ConversationService($llmManager, $repository, $this->resolverReturning($configuration));
        $actor   = $this->owner();
        $session = $service->startSession($actor, '', $configuration);

        $this->expectException(AccessDeniedException::class);
        $service->send($actor, $session->uuid, 'continue');
    }

    #[Test]
    public function theTurnIsAttributedToTheActorSoPerUserBudgetsApply(): void
    {
        $repository = new RecordingAiSessionRepository();
        $captured   = null;
        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatForConfiguration')->willReturnCallback(function (array $messages, LlmConfiguration $configuration, ?ChatOptions $options) use (&$captured): CompletionResponse {
            $captured = $options;

            return $this->response('ok');
        });
        $service = $this->service($llmManager, $repository);

        $actor   = $this->owner();
        $session = $service->startSession($actor);
        $service->send($actor, $session->uuid, 'hi');

        self::assertInstanceOf(ChatOptions::class, $captured);
        self::assertSame(self::OWNER, $captured->getBeUserUid());
    }

    #[Test]
    public function anExplicitBeUserUidOnTheOptionsIsNotOverwritten(): void
    {
        $repository = new RecordingAiSessionRepository();
        $captured   = null;
        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatForConfiguration')->willReturnCallback(function (array $messages, LlmConfiguration $configuration, ?ChatOptions $options) use (&$captured): CompletionResponse {
            $captured = $options;

            return $this->response('ok');
        });
        $service = $this->service($llmManager, $repository);

        $actor   = $this->owner();
        $session = $service->startSession($actor);
        $service->send($actor, $session->uuid, 'hi', (new ChatOptions())->withBeUserUid(7));

        self::assertInstanceOf(ChatOptions::class, $captured);
        self::assertSame(7, $captured->getBeUserUid());
    }

    #[Test]
    public function sendReplaysPriorTurnsToTheProvider(): void
    {
        $repository = new RecordingAiSessionRepository();
        $captured   = [];
        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatForConfiguration')->willReturnCallback(function (array $messages, LlmConfiguration $configuration) use (&$captured): CompletionResponse {
            $captured[] = $messages;

            return $this->response('reply');
        });
        $service = $this->service($llmManager, $repository);

        $actor   = $this->owner();
        $session = $service->startSession($actor);
        $service->send($actor, $session->uuid, 'first');
        $service->send($actor, $session->uuid, 'second');

        // The second call replays the prior turns before the new user message:
        // user 'first', assistant 'reply', then user 'second'.
        self::assertCount(2, $captured);
        $secondCall = $captured[1];
        self::assertCount(3, $secondCall);
        self::assertContainsOnlyInstancesOf(ChatMessage::class, $secondCall);
        self::assertSame('first', $secondCall[0]->content);
        self::assertSame('reply', $secondCall[1]->content);
        self::assertSame('second', $secondCall[2]->content);
    }

    #[Test]
    public function sendPrependsTheSystemPromptOnEveryTurn(): void
    {
        $repository = new RecordingAiSessionRepository();
        $captured   = [];
        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatForConfiguration')->willReturnCallback(function (array $messages, LlmConfiguration $configuration) use (&$captured): CompletionResponse {
            $captured[] = $messages;

            return $this->response('reply');
        });
        $service = $this->service($llmManager, $repository);
        $options = (new ChatOptions())->withSystemPrompt('You are terse.');

        $actor   = $this->owner();
        $session = $service->startSession($actor);
        $service->send($actor, $session->uuid, 'first', $options);
        $service->send($actor, $session->uuid, 'second', $options);

        // Both turns lead with the system prompt — it is never persisted in the
        // history, so it must be re-added every turn or it is lost from turn 2.
        self::assertCount(2, $captured);

        $turn1System = $captured[0][0];
        self::assertInstanceOf(ChatMessage::class, $turn1System);
        self::assertSame('system', $turn1System->getRole()->value);
        self::assertSame('You are terse.', $turn1System->content);

        $turn2System = $captured[1][0];
        self::assertInstanceOf(ChatMessage::class, $turn2System);
        self::assertSame('system', $turn2System->getRole()->value);

        // Second turn: system, user 'first', assistant 'reply', user 'second'.
        $turn2Last = $captured[1][3];
        self::assertInstanceOf(ChatMessage::class, $turn2Last);
        self::assertSame('second', $turn2Last->content);
    }

    #[Test]
    public function aFailedTurnStillAdvancesTheSequenceSoTheNextTurnDoesNotCollide(): void
    {
        $repository = new RecordingAiSessionRepository();
        $calls      = 0;
        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatForConfiguration')->willReturnCallback(function () use (&$calls): CompletionResponse {
            ++$calls;
            if ($calls === 1) {
                throw new RuntimeException('provider down', 4844402126);
            }

            return $this->response('ok');
        });
        $service = $this->service($llmManager, $repository);
        $actor   = $this->owner();
        $session = $service->startSession($actor);

        try {
            $service->send($actor, $session->uuid, 'first');
        } catch (Throwable) {
            // The first provider call fails; the user turn is already recorded.
        }

        $service->send($actor, $session->uuid, 'second');

        // No two turns share a sequence number, even across the failed attempt.
        $sequences = array_map(static fn(AiSessionMessage $m): int => $m->sequence, $repository->findMessages($session->uid));
        self::assertSame($sequences, array_values(array_unique($sequences)));
    }

    #[Test]
    public function sendThrowsForAnUnknownSession(): void
    {
        $repository = new RecordingAiSessionRepository();
        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->expects(self::never())->method('chat');
        $llmManager->expects(self::never())->method('chatForConfiguration');
        $service = $this->service($llmManager, $repository);

        $this->expectException(InvalidArgumentException::class);
        $service->send($this->owner(), '00000000-0000-0000-0000-000000000000', 'hi');
    }

    #[Test]
    public function startSessionStampsTitleAndTheActorAsOwner(): void
    {
        $service = $this->service(self::createStub(LlmServiceManagerInterface::class), new RecordingAiSessionRepository());

        $session = $service->startSession($this->owner(), 'my chat');

        self::assertSame('my chat', $session->title);
        self::assertSame(self::OWNER, $session->beUser);
        self::assertNotSame('', $session->uuid);
    }

    private function owner(): AiActorContext
    {
        return AiActorContext::backendUser(self::OWNER);
    }

    private function service(LlmServiceManagerInterface $llmManager, RecordingAiSessionRepository $repository): ConversationService
    {
        // A resolver that HAS an installation default, because since ADR-188 a
        // session cannot be opened without one. These cases are about ownership
        // and turn assembly; the default is scenery for them, and its absence
        // is its own case below.
        return new ConversationService($llmManager, $repository, $this->resolverWithDefault($this->defaultConfiguration()));
    }

    /**
     * A resolver whose installation default is $configuration, or which has
     * none when null.
     */
    private function resolverWithDefault(?LlmConfiguration $configuration): ConfigurationResolver
    {
        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findDefault')->willReturn($configuration);
        // Also findable BY identifier: since ADR-188 the session is bound to
        // this configuration, so every later turn resolves it by the identifier
        // that was persisted. A stub that only answers findDefault() would open
        // sessions the next turn cannot continue.
        $repository->method('findOneByIdentifier')->willReturnCallback(
            static fn(string $identifier): ?LlmConfiguration => $identifier === self::DEFAULT_IDENTIFIER ? $configuration : null,
        );

        return new ConfigurationResolver($repository);
    }

    /**
     * The installation default these cases fall back to: usable, unrestricted,
     * and distinguishable from a caller-chosen configuration by its identifier.
     */
    private function defaultConfiguration(): LlmConfiguration
    {
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier(self::DEFAULT_IDENTIFIER);
        $configuration->setLlmModel(new Model());
        $configuration->setIsActive(true);

        return $configuration;
    }

    /**
     * A real resolver over a stubbed repository, so the access and activity
     * guards under test are the production ones.
     */
    private function resolverReturning(LlmConfiguration $configuration): ConfigurationResolver
    {
        $repository = $this->createMock(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturn($configuration);

        return new ConfigurationResolver($repository);
    }

    #[Test]
    public function aTrimmedTurnRecordsHowManyOlderTurnsTheModelDidNotSee(): void
    {
        // The persisted history is the audit record and is never shortened. What
        // the model actually saw is a different fact, and without it a reader
        // cannot tell why an answer ignored an earlier turn (ADR-121).
        $repository    = new RecordingAiSessionRepository();
        $configuration = $this->configuration('editorial');

        $sent    = null;
        $trimmed = [ChatMessage::user('only the newest turn survived')];

        $contextWindow = $this->createMock(ContextWindowManagerInterface::class);
        $contextWindow->method('fit')->willReturn(
            new ContextFitResult($trimmed, true, 4, 1, 900, 1000, false, 1.15, ContextBudgetBreakdown::none()),
        );

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatForConfiguration')->willReturnCallback(
            function (array $messages) use (&$sent): CompletionResponse {
                $sent = $messages;

                return $this->response('answered');
            },
        );

        $service = new ConversationService($llmManager, $repository, $this->resolverReturning($configuration), $contextWindow);
        $actor   = $this->owner();
        $session = $service->startSession($actor, '', $configuration);
        $service->send($actor, $session->uuid, 'and now?');

        // The provider saw the trimmed transcript, not the assembled one.
        self::assertSame($trimmed, $sent);

        $userRow = $this->firstRowWithRole($repository, 'user');
        self::assertSame(4, $userRow['droppedTurns']);
    }

    #[Test]
    public function aTurnThatFitsRecordsZeroDroppedRatherThanNothing(): void
    {
        // Zero and "not evaluated" are different facts: zero says the fit ran
        // and kept everything.
        $repository    = new RecordingAiSessionRepository();
        $configuration = $this->configuration('editorial');

        $kept          = [ChatMessage::user('hi')];
        $contextWindow = $this->createMock(ContextWindowManagerInterface::class);
        $contextWindow->method('fit')->willReturn(
            new ContextFitResult($kept, false, 0, 1, 10, 1000, false, 1.15, ContextBudgetBreakdown::none()),
        );

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatForConfiguration')->willReturn($this->response('short'));

        $service = new ConversationService($llmManager, $repository, $this->resolverReturning($configuration), $contextWindow);
        $actor   = $this->owner();
        $session = $service->startSession($actor, '', $configuration);
        $service->send($actor, $session->uuid, 'hi');

        self::assertSame(0, $this->firstRowWithRole($repository, 'user')['droppedTurns']);
    }

    #[Test]
    public function aConversationWithoutAContextManagerRecordsNoFitAndIsUnchanged(): void
    {
        $repository    = new RecordingAiSessionRepository();
        $configuration = $this->configuration('editorial');

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->expects(self::once())->method('chatForConfiguration')->willReturn($this->response('as before'));

        $service = new ConversationService($llmManager, $repository, $this->resolverReturning($configuration));
        $actor   = $this->owner();
        $session = $service->startSession($actor, '', $configuration);

        self::assertSame('as before', $service->send($actor, $session->uuid, 'hi')->content);
        self::assertNull($this->firstRowWithRole($repository, 'user')['droppedTurns']);
    }

    #[Test]
    public function aTranscriptThatCannotBeTrimmedAnyFurtherIsStillSent(): void
    {
        // Refusing here would end a conversation the provider might still have
        // answered — the estimate errs high on purpose. If it really is too
        // large the provider says so, which is what the caller got before.
        $repository    = new RecordingAiSessionRepository();
        $configuration = $this->configuration('editorial');

        $floor         = [ChatMessage::user('a very long turn')];
        $contextWindow = $this->createMock(ContextWindowManagerInterface::class);
        $contextWindow->method('fit')->willReturn(
            new ContextFitResult($floor, false, 0, 1, 99999, 1000, true, 1.15, ContextBudgetBreakdown::none()),
        );

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->expects(self::once())->method('chatForConfiguration')->willReturn($this->response('answered anyway'));

        $service = new ConversationService($llmManager, $repository, $this->resolverReturning($configuration), $contextWindow);
        $actor   = $this->owner();
        $session = $service->startSession($actor, '', $configuration);

        self::assertSame('answered anyway', $service->send($actor, $session->uuid, 'a very long turn')->content);
    }

    #[Test]
    public function theSkillBlockTheManagerWillInjectIsHandedToTheFit(): void
    {
        // The manager prepends the configuration's skill block to the first user
        // message AFTER this fit (#625). Unless the fit is told about it, the
        // budget binds a list that is never sent that way.
        $repository    = new RecordingAiSessionRepository();
        $configuration = $this->configuration('editorial');
        $configuration->getSkills()->attach($this->skill('cfg:budget', 'Budget Skill', 'Answer in short sentences.'));

        $injected      = null;
        $contextWindow = $this->createMock(ContextWindowManagerInterface::class);
        $contextWindow->method('fit')->willReturnCallback(
            function (
                array $messages,
                LlmConfiguration $configuration,
                ?ChatOptions $options,
                ?UsageStatistics $lastUsage,
                array $toolSpecs = [],
                string $injectedText = '',
            ) use (&$injected): ContextFitResult {
                $injected = $injectedText;

                return new ContextFitResult([ChatMessage::user('hi')], false, 0, 1, 10, 1000, false, 1.15, ContextBudgetBreakdown::none());
            },
        );

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatForConfiguration')->willReturn($this->response('ok'));

        $service = new ConversationService(
            $llmManager,
            $repository,
            $this->resolverReturning($configuration),
            $contextWindow,
            null,
            new SkillComposer(),
        );
        $actor   = $this->owner();
        $session = $service->startSession($actor, '', $configuration);
        $service->send($actor, $session->uuid, 'hi');

        self::assertIsString($injected);
        self::assertStringContainsString('### Skill: Budget Skill', $injected);
        self::assertStringContainsString('Answer in short sentences.', $injected);
    }

    #[Test]
    public function withoutASkillComposerNothingIsReservedAndTheFitIsUnchanged(): void
    {
        $repository    = new RecordingAiSessionRepository();
        $configuration = $this->configuration('editorial');
        $configuration->getSkills()->attach($this->skill('cfg:budget', 'Budget Skill', 'Answer in short sentences.'));

        $injected      = 'not called';
        $contextWindow = $this->createMock(ContextWindowManagerInterface::class);
        $contextWindow->method('fit')->willReturnCallback(
            function (
                array $messages,
                LlmConfiguration $configuration,
                ?ChatOptions $options,
                ?UsageStatistics $lastUsage,
                array $toolSpecs = [],
                string $injectedText = '',
            ) use (&$injected): ContextFitResult {
                $injected = $injectedText;

                return new ContextFitResult([ChatMessage::user('hi')], false, 0, 1, 10, 1000, false, 1.15, ContextBudgetBreakdown::none());
            },
        );

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatForConfiguration')->willReturn($this->response('ok'));

        $service = new ConversationService($llmManager, $repository, $this->resolverReturning($configuration), $contextWindow);
        $actor   = $this->owner();
        $session = $service->startSession($actor, '', $configuration);
        $service->send($actor, $session->uuid, 'hi');

        self::assertSame('', $injected);
    }

    private function skill(string $identifier, string $name, string $body): Skill
    {
        $skill = new Skill();
        $skill->setSource(1);
        $skill->setIdentifier($identifier);
        $skill->setName($name);
        $skill->setBody($body);
        $skill->setBodyChecksum(hash('sha256', $body));
        $skill->setEnabled(true);
        $skill->setOrphaned(false);

        return $skill;
    }

    /**
     * The turn's system prompt is composed by the manager (configuration
     * prompt + its tag-selected snippets, ADR-031) and prepended after the fit,
     * so the fit is told what it will be. Without that the budget is short by
     * the whole snippet block on every turn of a snippet-using configuration.
     */
    #[Test]
    public function theContextWindowIsToldTheComposedSystemPrompt(): void
    {
        $repository    = new RecordingAiSessionRepository();
        $configuration = $this->configuration('editorial');
        $configuration->setSystemPrompt('You are the configured assistant.');
        $configuration->setSnippetTags('persona');

        $seen = null;

        $contextWindow = self::createStub(ContextWindowManagerInterface::class);
        $contextWindow->method('fit')->willReturnCallback(
            static function (
                array $messages,
                LlmConfiguration $configuration,
                ?ChatOptions $options,
                ?UsageStatistics $lastUsage,
                array $toolSpecs,
                string $injectedText,
                ?string $effectiveSystemPrompt,
            ) use (&$seen): ContextFitResult {
                $seen = $effectiveSystemPrompt;

                return new ContextFitResult([ChatMessage::user('fitted')], false, 0, 1, 10, 1000, false, 1.15, ContextBudgetBreakdown::none());
            },
        );

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatForConfiguration')->willReturn($this->response('answered'));

        $service = new ConversationService(
            $llmManager,
            $repository,
            $this->resolverReturning($configuration),
            $contextWindow,
            null,
            null,
            $this->snippetResolver(),
        );
        $actor   = $this->owner();
        $session = $service->startSession($actor, '', $configuration);
        $service->send($actor, $session->uuid, 'hi');

        self::assertSame("You are the configured assistant.\n\nNova:\nYou are Nova.", $seen);
    }

    private function snippetResolver(): ConfigurationSnippetResolver
    {
        $snippet = new PromptSnippet();
        $snippet->setIdentifier('persona-nova');
        $snippet->setName('Nova');
        $snippet->setSnippet('You are Nova.');

        $snippets = self::createStub(PromptSnippetRepository::class);
        $snippets->method('findActiveByTag')->willReturnCallback(
            static fn(string $tag): array => $tag === 'persona' ? [$snippet] : [],
        );

        return new ConfigurationSnippetResolver($snippets, new PromptSnippetComposer());
    }

    /**
     * @return array<string, mixed>
     */
    private function firstRowWithRole(RecordingAiSessionRepository $repository, string $role): array
    {
        foreach ($repository->messages as $row) {
            if ($row['role'] === $role) {
                return $row;
            }
        }

        self::fail(sprintf('No %s row was recorded.', $role));
    }

    /**
     * The turn is budgeted against the window of the model it is sent to.
     *
     * ContextWindowManager::fit() reads `$resolvedModel ?? $configuration
     * ->getLlmModel()`, and a criteria-mode configuration carries no model by
     * design, so passing nothing meant the fit fell back to the 8192-token
     * UNKNOWN_WINDOW_FALLBACK while the send resolved a concrete model from
     * the criteria afterwards. History the dispatched model could have
     * accepted was dropped, and droppedTurns recorded that on the user row as
     * if it had been necessary (#922).
     */
    #[Test]
    public function theTurnIsFittedAgainstTheModelItIsSentTo(): void
    {
        $repository    = new RecordingAiSessionRepository();
        $configuration = $this->configuration('criteria-mode');
        $resolved      = new Model();
        $resolved->setName('the-model-actually-used');

        $passedToFit = false;
        $seenModel   = null;

        $contextWindow = self::createStub(ContextWindowManagerInterface::class);
        $contextWindow->method('fit')->willReturnCallback(
            function (
                array $messages,
                LlmConfiguration $configuration,
                ?ChatOptions $options,
                ?UsageStatistics $lastUsage,
                array $toolSpecs,
                string $injectedText,
                ?string $effectiveSystemPrompt,
                ?Model $resolvedModel = null,
            ) use (&$passedToFit, &$seenModel): ContextFitResult {
                $passedToFit = true;
                $seenModel   = $resolvedModel;

                return new ContextFitResult([ChatMessage::user('and now?')], false, 0, 1, 10, 128000, false, 1.15, ContextBudgetBreakdown::none());
            },
        );

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatForConfiguration')->willReturn($this->response('answered'));

        $service = new ConversationService(
            $llmManager,
            $repository,
            $this->resolverReturning($configuration),
            $contextWindow,
            null,
            null,
            null,
            $this->routingReturning($resolved),
        );
        $actor   = $this->owner();
        $session = $service->startSession($actor, '', $configuration);
        $service->send($actor, $session->uuid, 'and now?');

        self::assertTrue($passedToFit, 'the fit did not run at all, so the assertion below would be vacuous');
        self::assertSame($resolved, $seenModel);
    }

    /**
     * Exactly one routing evaluation per turn.
     *
     * Two would be two decisions, and two decisions taken moments apart can
     * disagree about which model the turn runs on — the hazard
     * ConfigurationCallPlanner::resolveModel() names in its own docblock.
     */
    #[Test]
    public function theModelIsResolvedOncePerTurn(): void
    {
        $repository    = new RecordingAiSessionRepository();
        $configuration = $this->configuration('criteria-mode');
        $resolved      = new Model();

        $contextWindow = self::createStub(ContextWindowManagerInterface::class);
        $contextWindow->method('fit')->willReturn(
            new ContextFitResult([ChatMessage::user('hi')], false, 0, 1, 10, 128000, false, 1.15, ContextBudgetBreakdown::none()),
        );

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatForConfiguration')->willReturn($this->response('answered'));

        $routing = $this->createMock(ModelSelectionServiceInterface::class);
        $routing->expects(self::once())
            ->method('explainRouting')
            ->willReturn(RoutingReadout::decided(new RoutingDecision($resolved, [], RoutingPolicyMode::BALANCED), true, null, false, false));

        $service = new ConversationService(
            $llmManager,
            $repository,
            $this->resolverReturning($configuration),
            $contextWindow,
            null,
            null,
            null,
            $routing,
        );
        $actor   = $this->owner();
        $session = $service->startSession($actor, '', $configuration);
        $service->send($actor, $session->uuid, 'hi');
    }

    /**
     * A routing evaluation that fails must not end a conversation.
     *
     * The resolution here is a budgeting read, not the authoritative one —
     * that still happens inside the manager's terminal, where its failure is
     * the caller's error either way. Losing the window estimate is worth a log
     * line and the pre-#922 fallback, not a dead turn.
     */
    #[Test]
    public function aRoutingFailureLeavesTheTurnRunningAgainstTheOldFallback(): void
    {
        $repository    = new RecordingAiSessionRepository();
        $configuration = $this->configuration('criteria-mode');

        $seenModel = 'never set';

        $contextWindow = self::createStub(ContextWindowManagerInterface::class);
        $contextWindow->method('fit')->willReturnCallback(
            function (
                array $messages,
                LlmConfiguration $configuration,
                ?ChatOptions $options,
                ?UsageStatistics $lastUsage,
                array $toolSpecs,
                string $injectedText,
                ?string $effectiveSystemPrompt,
                ?Model $resolvedModel = null,
            ) use (&$seenModel): ContextFitResult {
                $seenModel = $resolvedModel;

                return new ContextFitResult([ChatMessage::user('hi')], false, 0, 1, 10, 8192, false, 1.15, ContextBudgetBreakdown::none());
            },
        );

        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chatForConfiguration')->willReturn($this->response('still answered'));

        $routing = self::createStub(ModelSelectionServiceInterface::class);
        $routing->method('explainRouting')->willThrowException(new RuntimeException('catalogue unavailable'));

        $service = new ConversationService(
            $llmManager,
            $repository,
            $this->resolverReturning($configuration),
            $contextWindow,
            null,
            null,
            null,
            $routing,
        );
        $actor   = $this->owner();
        $session = $service->startSession($actor, '', $configuration);

        self::assertSame('still answered', $service->send($actor, $session->uuid, 'hi')->content);
        self::assertNull($seenModel);
    }

    private function routingReturning(Model $model): ModelSelectionServiceInterface
    {
        $routing = self::createStub(ModelSelectionServiceInterface::class);
        $routing->method('explainRouting')->willReturn(
            RoutingReadout::decided(new RoutingDecision($model, [], RoutingPolicyMode::BALANCED), true, null, false, false),
        );

        return $routing;
    }

    private function configuration(string $identifier): LlmConfiguration
    {
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier($identifier);

        return $configuration;
    }

    private function response(string $content): CompletionResponse
    {
        return new CompletionResponse($content, 'test-model', UsageStatistics::fromTokens(5, 3));
    }
}
