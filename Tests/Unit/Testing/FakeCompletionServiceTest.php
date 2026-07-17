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
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Testing\FakeCompletionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(FakeCompletionService::class)]
final class FakeCompletionServiceTest extends TestCase
{
    #[Test]
    public function implementsTheRealInterface(): void
    {
        self::assertInstanceOf(CompletionServiceInterface::class, new FakeCompletionService());
    }

    #[Test]
    public function returnsQueuedResponsesInFifoOrderAcrossTheResponseMethods(): void
    {
        $first  = self::response('first');
        $second = self::response('second');

        $subject = new FakeCompletionService();
        $subject->responses = [$first, $second];

        self::assertSame($first, $subject->complete('a'));
        self::assertSame($second, $subject->completeForConfiguration('b', new LlmConfiguration()));
    }

    #[Test]
    public function factualAndCreativeAlsoDrawFromTheResponseQueue(): void
    {
        $first  = self::response('factual');
        $second = self::response('creative');

        $subject = new FakeCompletionService();
        $subject->responses = [$first, $second];

        self::assertSame($first, $subject->completeFactual('a'));
        self::assertSame($second, $subject->completeCreative('b'));
    }

    #[Test]
    public function completeJsonReturnsTheCannedArray(): void
    {
        $subject = new FakeCompletionService();
        $subject->jsonResult = ['answer' => 42];

        self::assertSame(['answer' => 42], $subject->completeJson('a'));
        self::assertSame(['answer' => 42], $subject->completeJsonForConfiguration('b', new LlmConfiguration()));
    }

    #[Test]
    public function completeMarkdownReturnsTheCannedString(): void
    {
        $subject = new FakeCompletionService();
        $subject->markdownResult = '# heading';

        self::assertSame('# heading', $subject->completeMarkdown('a'));
        self::assertSame('# heading', $subject->completeMarkdownForConfiguration('b', new LlmConfiguration()));
    }

    #[Test]
    public function recordsPromptAndOptionsPerMethod(): void
    {
        $subject = new FakeCompletionService();
        $subject->responses = [self::response('ok')];

        $subject->complete('the prompt');

        self::assertCount(1, $subject->completeCalls);
        self::assertSame('the prompt', $subject->completeCalls[0]['prompt']);
        self::assertNull($subject->completeCalls[0]['options']);
    }

    #[Test]
    public function recordsConfigurationPassedToPerConfigurationCalls(): void
    {
        $configuration = new LlmConfiguration();

        $subject = new FakeCompletionService();
        $subject->responses = [self::response('ok')];

        $subject->completeForConfiguration('p', $configuration);

        self::assertCount(1, $subject->completeForConfigurationCalls);
        self::assertSame($configuration, $subject->completeForConfigurationCalls[0]['configuration']);
    }

    #[Test]
    public function throwsConfiguredThrowableInsteadOfReturning(): void
    {
        $subject = new FakeCompletionService();
        $subject->responses = [self::response('unused')];
        $subject->throwable = new RuntimeException('boom');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $subject->complete('a');
    }

    #[Test]
    public function throwableIsOneShotForTheJsonMethodToo(): void
    {
        $subject = new FakeCompletionService();
        $subject->jsonResult = ['ok' => true];
        $subject->throwable = new RuntimeException('boom');

        try {
            $subject->completeJson('a');
            self::fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException) {
            // one-shot: cleared before throwing
        }

        self::assertNull($subject->throwable);
        self::assertSame(['ok' => true], $subject->completeJson('a'));
    }

    #[Test]
    public function throwsWhenAResponseMethodIsCalledWithoutAQueuedResponse(): void
    {
        $subject = new FakeCompletionService();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no response was queued');

        $subject->complete('a');
    }

    private static function response(string $content): CompletionResponse
    {
        return new CompletionResponse($content, 'fake-model', new UsageStatistics(0, 0, 0));
    }
}
