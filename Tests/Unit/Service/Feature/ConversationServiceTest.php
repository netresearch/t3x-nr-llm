<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Feature;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Feature\ConversationService;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Tests\Unit\Service\Session\Fixtures\RecordingAiSessionRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConversationService::class)]
final class ConversationServiceTest extends TestCase
{
    #[Test]
    public function sendPersistsBothTurnsAndReturnsTheReply(): void
    {
        $repository = new RecordingAiSessionRepository();
        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->expects(self::once())->method('chat')->willReturn($this->response('Hello there'));
        $service = new ConversationService($llmManager, $repository);

        $session  = $service->startSession('greeting');
        $response = $service->send($session->uuid, 'Hi');

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
    public function sendReplaysPriorTurnsToTheProvider(): void
    {
        $repository = new RecordingAiSessionRepository();
        $captured   = [];
        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->method('chat')->willReturnCallback(function (array $messages) use (&$captured): CompletionResponse {
            $captured[] = $messages;

            return $this->response('reply');
        });
        $service = new ConversationService($llmManager, $repository);

        $session = $service->startSession();
        $service->send($session->uuid, 'first');
        $service->send($session->uuid, 'second');

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
    public function sendThrowsForAnUnknownSession(): void
    {
        $repository = new RecordingAiSessionRepository();
        $llmManager = $this->createMock(LlmServiceManagerInterface::class);
        $llmManager->expects(self::never())->method('chat');
        $service = new ConversationService($llmManager, $repository);

        $this->expectException(InvalidArgumentException::class);
        $service->send('00000000-0000-0000-0000-000000000000', 'hi');
    }

    #[Test]
    public function startSessionStampsTitleAndZeroOwnerWithoutAResolver(): void
    {
        $repository = new RecordingAiSessionRepository();
        $service    = new ConversationService($this->createMock(LlmServiceManagerInterface::class), $repository);

        $session = $service->startSession('my chat');

        self::assertSame('my chat', $session->title);
        self::assertSame(0, $session->beUser);
        self::assertNotSame('', $session->uuid);
    }

    private function response(string $content): CompletionResponse
    {
        return new CompletionResponse($content, 'test-model', UsageStatistics::fromTokens(5, 3));
    }
}
