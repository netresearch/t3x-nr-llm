<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Testing;

use LogicException;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Service\Feature\ToolCallingServiceInterface;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Testing\FakeToolCallingService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(FakeToolCallingService::class)]
final class FakeToolCallingServiceTest extends TestCase
{
    #[Test]
    public function implementsTheRealInterface(): void
    {
        self::assertInstanceOf(ToolCallingServiceInterface::class, new FakeToolCallingService());
    }

    #[Test]
    public function returnsQueuedResponsesInFifoOrderAcrossBothMethods(): void
    {
        $first = self::response('first');
        $second = self::response('second');

        $subject = new FakeToolCallingService();
        $subject->responses = [$first, $second];

        self::assertSame($first, $subject->chatWithTools([], []));
        self::assertSame($second, $subject->chatWithToolsForConfiguration([], [], new LlmConfiguration()));
    }

    #[Test]
    public function recordsChatWithToolsArguments(): void
    {
        $messages = [['role' => 'user', 'content' => 'hi']];
        $tools    = [['name' => 'search']];
        $options  = new ToolOptions();

        $subject = new FakeToolCallingService();
        $subject->responses = [self::response('ok')];

        $subject->chatWithTools($messages, $tools, $options);

        self::assertCount(1, $subject->chatWithToolsCalls);
        self::assertSame($messages, $subject->chatWithToolsCalls[0]['messages']);
        self::assertSame($tools, $subject->chatWithToolsCalls[0]['tools']);
        self::assertSame($options, $subject->chatWithToolsCalls[0]['options']);
        self::assertSame([], $subject->chatWithToolsForConfigurationCalls);
    }

    #[Test]
    public function recordsConfigurationPassedToPerConfigurationCall(): void
    {
        $configuration = new LlmConfiguration();

        $subject = new FakeToolCallingService();
        $subject->responses = [self::response('ok')];

        $subject->chatWithToolsForConfiguration([], [], $configuration);

        self::assertCount(1, $subject->chatWithToolsForConfigurationCalls);
        self::assertSame($configuration, $subject->chatWithToolsForConfigurationCalls[0]['configuration']);
    }

    #[Test]
    public function throwsConfiguredThrowableInsteadOfReturning(): void
    {
        $subject = new FakeToolCallingService();
        $subject->responses = [self::response('unused')];
        $subject->throwable = new RuntimeException('boom');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $subject->chatWithTools([], []);
    }

    #[Test]
    public function throwableIsOneShotAndNextCallReturnsAQueuedResponseAgain(): void
    {
        $queued = self::response('after');

        $subject = new FakeToolCallingService();
        $subject->responses = [$queued];
        $subject->throwable = new RuntimeException('boom');

        try {
            $subject->chatWithTools([], []);
            self::fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException) {
            // one-shot: cleared before throwing
        }

        self::assertNull($subject->throwable);
        self::assertSame($queued, $subject->chatWithTools([], []));
    }

    #[Test]
    public function throwsWhenCalledWithoutAQueuedResponse(): void
    {
        $subject = new FakeToolCallingService();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no response was queued');

        $subject->chatWithTools([], []);
    }

    private static function response(string $content): CompletionResponse
    {
        return new CompletionResponse($content, 'fake-model', new UsageStatistics(0, 0, 0));
    }
}
