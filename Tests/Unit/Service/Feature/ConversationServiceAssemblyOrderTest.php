<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Feature;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Feature\ConversationService;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\MessageShaper;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Tests\Unit\Service\Session\Fixtures\RecordingAiSessionRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Characterisation of the order in which a conversation turn is assembled
 * (#637): system prompt, then the replayed history oldest-first, then the new
 * user turn.
 *
 * The system prompt is never persisted, so it is re-prepended on every turn.
 * Its lead position is the same invariant the agent loop has: the manager
 * applies the configuration's system prompt through
 * {@see MessageShaper::applySystemPrompt()}, which any earlier system message
 * would suppress.
 */
#[CoversClass(ConversationService::class)]
final class ConversationServiceAssemblyOrderTest extends TestCase
{
    private const OWNER = 42;

    private const SYSTEM_PROMPT = 'You are the session assistant.';

    /** @var list<ChatMessage|array<string, mixed>> */
    private array $sent = [];

    #[Test]
    public function aTurnSendsTheSystemPromptFirstThenTheHistoryOldestFirstThenTheNewUserTurn(): void
    {
        $repository = new RecordingAiSessionRepository();
        $service    = $this->service($repository);
        $options    = new ChatOptions(systemPrompt: self::SYSTEM_PROMPT);

        $actor   = $this->owner();
        $session = $service->startSession($actor, '', $this->configuration());

        $service->send($actor, $session->uuid, 'first question', $options);
        $service->send($actor, $session->uuid, 'second question', $options);

        self::assertSame(
            ['system', 'user', 'assistant', 'user'],
            $this->roles(),
            'A second turn replays the whole transcript behind the system prompt.',
        );
        self::assertSame(
            [self::SYSTEM_PROMPT, 'first question', 'answer', 'second question'],
            $this->contents(),
        );
    }

    #[Test]
    public function theSystemPromptIsPrependedOncePerTurnAndNeverAccumulates(): void
    {
        $repository = new RecordingAiSessionRepository();
        $service    = $this->service($repository);

        $options = new ChatOptions(systemPrompt: self::SYSTEM_PROMPT);
        $actor   = $this->owner();
        $session = $service->startSession($actor, '', $this->configuration());

        $service->send($actor, $session->uuid, 'one', $options);
        $service->send($actor, $session->uuid, 'two', $options);
        $service->send($actor, $session->uuid, 'three', $options);

        self::assertSame(
            ['system', 'user', 'assistant', 'user', 'assistant', 'user'],
            $this->roles(),
        );
        self::assertCount(1, array_filter($this->roles(), static fn(string $role): bool => $role === 'system'));
    }

    /**
     * Without a system prompt on the options the conversation sends no system
     * message at all — the configuration's own prompt is added downstream by
     * the manager's shaper, at position 0, because nothing occupies it.
     */
    #[Test]
    public function withoutASystemPromptTheTurnStartsWithTheOldestHistoryTurn(): void
    {
        $repository = new RecordingAiSessionRepository();
        $service    = $this->service($repository);

        $actor   = $this->owner();
        $session = $service->startSession($actor, '', $this->configuration());

        $service->send($actor, $session->uuid, 'first question');
        $service->send($actor, $session->uuid, 'second question');

        self::assertSame(['user', 'assistant', 'user'], $this->roles());

        $shaped = (new MessageShaper())->applySystemPrompt(
            $this->sent,
            ['system_prompt' => 'configured prompt'],
        );

        self::assertSame(
            ['system', 'user', 'assistant', 'user'],
            array_map(
                static fn(ChatMessage|array $message): string => $message instanceof ChatMessage
                    ? $message->role
                    : (is_string($message['role'] ?? null) ? $message['role'] : ''),
                $shaped,
            ),
        );
    }

    /**
     * A session-supplied system prompt occupies position 0 and therefore
     * suppresses the configuration's prompt in the manager's shaper — the same
     * guard the agent loop's baked lead exists to satisfy. Per-call precedence
     * is intended here; what matters is that only ONE of the two prompts is
     * ever sent.
     */
    #[Test]
    public function aSessionSystemPromptSuppressesTheConfigurationsPromptDownstream(): void
    {
        $repository = new RecordingAiSessionRepository();
        $service    = $this->service($repository);

        $actor   = $this->owner();
        $session = $service->startSession($actor, '', $this->configuration());
        $service->send($actor, $session->uuid, 'hi', new ChatOptions(systemPrompt: self::SYSTEM_PROMPT));

        $shaped = (new MessageShaper())->applySystemPrompt(
            $this->sent,
            ['system_prompt' => 'configured prompt'],
        );

        self::assertSame($this->sent, $shaped);
        self::assertSame(self::SYSTEM_PROMPT, $this->contentOf($shaped[0]));
    }

    private function service(RecordingAiSessionRepository $repository): ConversationService
    {
        $manager = self::createStub(LlmServiceManagerInterface::class);
        $manager->method('chatForConfiguration')->willReturnCallback(
            function (array $messages): CompletionResponse {
                /** @var list<ChatMessage|array<string, mixed>> $messages */
                $this->sent = $messages;

                return new CompletionResponse('answer', 'test-model', UsageStatistics::fromTokens(5, 3));
            },
        );

        $configurationRepository = self::createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findOneByIdentifier')->willReturn($this->configuration());

        return new ConversationService($manager, $repository, new ConfigurationResolver($configurationRepository));
    }

    private function configuration(): LlmConfiguration
    {
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('editorial');

        return $configuration;
    }

    private function owner(): AiActorContext
    {
        return AiActorContext::backendUser(self::OWNER);
    }

    /**
     * @return list<string>
     */
    private function roles(): array
    {
        return array_map(
            static function (ChatMessage|array $message): string {
                if ($message instanceof ChatMessage) {
                    return $message->role;
                }

                return is_string($message['role'] ?? null) ? $message['role'] : '';
            },
            $this->sent,
        );
    }

    /**
     * @return list<string>
     */
    private function contents(): array
    {
        return array_map($this->contentOf(...), $this->sent);
    }

    /**
     * @param ChatMessage|array<string, mixed> $message
     */
    private function contentOf(ChatMessage|array $message): string
    {
        if ($message instanceof ChatMessage) {
            return $message->content;
        }

        return is_string($message['content'] ?? null) ? $message['content'] : '';
    }
}
