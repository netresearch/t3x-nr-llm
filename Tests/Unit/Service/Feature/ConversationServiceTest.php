<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Feature;

use Netresearch\NrLlm\Domain\Enum\ServiceAccountScope;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Exception\AccessDeniedException;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Feature\ConversationService;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
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

    #[Test]
    public function sendPersistsBothTurnsAndReturnsTheReply(): void
    {
        $repository = new RecordingAiSessionRepository();
        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->expects(self::once())->method('chat')->willReturn($this->response('Hello there'));
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
        $llmManager->method('chat')->willReturn($this->response('ok'));
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
        $llmManager->method('chat')->willReturn($this->response('ok'));
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
        $llmManager->method('chat')->willReturnCallback(function (array $messages, ?ChatOptions $options) use (&$captured): CompletionResponse {
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
        $llmManager->method('chat')->willReturnCallback(function (array $messages, ?ChatOptions $options) use (&$captured): CompletionResponse {
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
        $llmManager->method('chat')->willReturnCallback(function (array $messages) use (&$captured): CompletionResponse {
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
        $llmManager->method('chat')->willReturnCallback(function (array $messages) use (&$captured): CompletionResponse {
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
        $llmManager->method('chat')->willReturnCallback(function () use (&$calls): CompletionResponse {
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
        $sequences = array_map(static fn($m): int => $m->sequence, $repository->findMessages($session->uid));
        self::assertSame($sequences, array_values(array_unique($sequences)));
    }

    #[Test]
    public function sendThrowsForAnUnknownSession(): void
    {
        $repository = new RecordingAiSessionRepository();
        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->expects(self::never())->method('chat');
        $service = $this->service($llmManager, $repository);

        $this->expectException(InvalidArgumentException::class);
        $service->send($this->owner(), '00000000-0000-0000-0000-000000000000', 'hi');
    }

    #[Test]
    public function startSessionStampsTitleAndTheActorAsOwner(): void
    {
        $service = $this->service($this->createMock(LlmServiceManagerInterface::class), new RecordingAiSessionRepository());

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
        return new ConversationService($llmManager, $repository, new ConfigurationResolver());
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
