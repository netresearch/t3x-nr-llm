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
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Budget\BackendUserContextResolverInterface;
use Netresearch\NrLlm\Service\Feature\ToolCallingService;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;

#[CoversClass(ToolCallingService::class)]
class ToolCallingServiceTest extends AbstractUnitTestCase
{
    /**
     * Create a subject with a mock LLM manager for expectation testing.
     *
     * @return array{subject: ToolCallingService, llmManager: LlmServiceManagerInterface&MockObject}
     */
    private function createSubjectWithMockManager(): array
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);

        return [
            'subject' => new ToolCallingService($llmManagerMock),
            'llmManager' => $llmManagerMock,
        ];
    }

    /**
     * @return list<ChatMessage>
     */
    private function messages(): array
    {
        return [ChatMessage::user('What is the weather in Leipzig?')];
    }

    /**
     * @return list<ToolSpec>
     */
    private function tools(): array
    {
        return [
            ToolSpec::function(
                'get_weather',
                'Get the current weather for a location.',
                [
                    'type' => 'object',
                    'properties' => [
                        'location' => ['type' => 'string'],
                    ],
                    'required' => ['location'],
                ],
            ),
        ];
    }

    #[Test]
    public function chatWithToolsDelegatesMessagesToolsAndOptionsToManager(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $messages = $this->messages();
        $tools = $this->tools();
        $options = ToolOptions::required();
        $expected = $this->createMockResponse('ok');

        $llmManagerMock
            ->expects(self::once())
            ->method('chatWithTools')
            ->with($messages, $tools, $options)
            ->willReturn($expected);

        self::assertSame($expected, $subject->chatWithTools($messages, $tools, $options));
    }

    #[Test]
    public function chatWithToolsDefaultsOptionsWhenNoneGiven(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $llmManagerMock
            ->expects(self::once())
            ->method('chatWithTools')
            ->with(
                self::anything(),
                self::anything(),
                self::isInstanceOf(ToolOptions::class),
            )
            ->willReturn($this->createMockResponse('ok'));

        $subject->chatWithTools($this->messages(), $this->tools());
    }

    #[Test]
    public function chatWithToolsPopulatesBeUserUidFromResolverWhenUnset(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $resolver = $this->createMock(BackendUserContextResolverInterface::class);
        $resolver->expects(self::once())
            ->method('resolveBeUserUid')
            ->willReturn(42);

        $subject = new ToolCallingService($llmManagerMock, $resolver);

        $llmManagerMock->expects(self::once())
            ->method('chatWithTools')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(static fn(ToolOptions $options): bool
                    => $options->getBeUserUid() === 42),
            )
            ->willReturn($this->createMockResponse('ok'));

        $subject->chatWithTools($this->messages(), $this->tools());
    }

    #[Test]
    public function chatWithToolsRespectsExplicitBeUserUidOverResolver(): void
    {
        // Caller-supplied uid wins — the resolver is for the absent-default
        // case only. We assert by giving the resolver an expectation that
        // would fail the test if it were ever called.
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $resolver = $this->createMock(BackendUserContextResolverInterface::class);
        $resolver->expects(self::never())
            ->method('resolveBeUserUid');

        $subject = new ToolCallingService($llmManagerMock, $resolver);

        $llmManagerMock->expects(self::once())
            ->method('chatWithTools')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(static fn(ToolOptions $options): bool
                    => $options->getBeUserUid() === 7),
            )
            ->willReturn($this->createMockResponse('ok'));

        $subject->chatWithTools($this->messages(), $this->tools(), ToolOptions::auto()->withBeUserUid(7));
    }

    #[Test]
    public function chatWithToolsForConfigurationDelegatesToManager(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $messages = $this->messages();
        $tools = $this->tools();
        $configuration = new LlmConfiguration();
        $options = ToolOptions::auto();
        $expected = $this->createMockResponse('ok');

        $llmManagerMock
            ->expects(self::once())
            ->method('chatWithToolsForConfiguration')
            ->with($messages, $tools, $configuration, $options)
            ->willReturn($expected);

        self::assertSame($expected, $subject->chatWithToolsForConfiguration($messages, $tools, $configuration, $options));
    }

    #[Test]
    public function chatWithToolsForConfigurationPopulatesBeUserUidFromResolverWhenUnset(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $resolver = $this->createMock(BackendUserContextResolverInterface::class);
        $resolver->expects(self::once())
            ->method('resolveBeUserUid')
            ->willReturn(42);

        $subject = new ToolCallingService($llmManagerMock, $resolver);

        $llmManagerMock->expects(self::once())
            ->method('chatWithToolsForConfiguration')
            ->with(
                self::anything(),
                self::anything(),
                self::isInstanceOf(LlmConfiguration::class),
                self::callback(static fn(ToolOptions $options): bool
                    => $options->getBeUserUid() === 42),
            )
            ->willReturn($this->createMockResponse('ok'));

        $subject->chatWithToolsForConfiguration($this->messages(), $this->tools(), new LlmConfiguration());
    }

    private function createMockResponse(
        string $content,
        string $finishReason = 'stop',
    ): CompletionResponse {
        return new CompletionResponse(
            content: $content,
            model: 'test-model',
            usage: new UsageStatistics(
                promptTokens: 10,
                completionTokens: 20,
                totalTokens: 30,
            ),
            finishReason: $finishReason,
            provider: 'test',
        );
    }
}
