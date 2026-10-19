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
        $first  = $this->response('first');
        $second = $this->response('second');

        $subject = new FakeCompletionService();
        $subject->responses = [$first, $second];

        self::assertSame($first, $subject->complete('a'));
        self::assertSame($second, $subject->completeForConfiguration('b', new LlmConfiguration()));
    }

    #[Test]
    public function factualAndCreativeAlsoDrawFromTheResponseQueue(): void
    {
        $first  = $this->response('factual');
        $second = $this->response('creative');

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
        $subject->responses = [$this->response('ok')];

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
        $subject->responses = [$this->response('ok')];

        $subject->completeForConfiguration('p', $configuration);

        self::assertCount(1, $subject->completeForConfigurationCalls);
        self::assertSame($configuration, $subject->completeForConfigurationCalls[0]['configuration']);
    }

    #[Test]
    public function throwsConfiguredThrowableInsteadOfReturning(): void
    {
        $subject = new FakeCompletionService();
        $subject->responses = [$this->response('unused')];
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

        $expected = new RuntimeException('boom');
        $subject->throwable = $expected;

        $caught = null;
        try {
            $subject->completeJson('a');
        } catch (RuntimeException $failure) {
            $caught = $failure;
        }

        self::assertSame(
            $expected,
            $caught,
            'The first call must throw the exact configured failure.',
        );

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

    private function response(string $content): CompletionResponse
    {
        return new CompletionResponse($content, 'fake-model', new UsageStatistics(0, 0, 0));
    }

    #[Test]
    public function structuredMethodsWrapTheCannedPayloadAsOneAttempt(): void
    {
        $subject = new FakeCompletionService();
        $subject->structuredResult = ['score' => 3];

        $default = $subject->completeStructured('a', ['type' => 'object']);
        $named = $subject->completeStructuredForConfiguration('b', new LlmConfiguration(), ['type' => 'object']);

        foreach ([$default, $named] as $structured) {
            self::assertSame(['score' => 3], $structured->data);
            self::assertSame(1, $structured->attempts);
            self::assertSame(FakeCompletionService::STRUCTURED_MODEL, $structured->response->model);
            self::assertSame('{"score":3}', $structured->response->content);
            self::assertSame(0, $structured->usage->totalTokens);
        }

        self::assertCount(1, $subject->completeStructuredCalls);
        self::assertCount(1, $subject->completeStructuredForConfigurationCalls);
    }
}
