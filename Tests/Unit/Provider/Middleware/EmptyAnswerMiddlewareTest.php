<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Middleware;

use Netresearch\NrLlm\Domain\Enum\FailureClass;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Exception\OutputBudgetExhaustedException;
use Netresearch\NrLlm\Provider\Middleware\EmptyAnswerMiddleware;
use Netresearch\NrLlm\Provider\Middleware\FailureClassifier;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(EmptyAnswerMiddleware::class)]
#[CoversClass(OutputBudgetExhaustedException::class)]
final class EmptyAnswerMiddlewareTest extends AbstractUnitTestCase
{
    #[Test]
    public function anAnswerWithoutTextThatHitTheLimitIsATypedErrorNamingTheConfiguration(): void
    {
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('nr_repurpose_text');
        $context = new ProviderCallContext(ProviderOperation::Chat, 'c', $configuration);

        try {
            (new EmptyAnswerMiddleware())->handle($context, fn (): CompletionResponse => $this->response(" \n", 'length', completionTokens: 1000));
            self::fail('No error for an empty answer.');
        } catch (OutputBudgetExhaustedException $e) {
            self::assertSame('nr_repurpose_text', $e->configurationIdentifier);
            self::assertSame(1000, $e->outputTokens);
            self::assertStringContainsString('nr_repurpose_text', $e->getMessage());
        }
    }

    #[Test]
    public function theConfigurationThatServedTheCallIsTheOneNamed(): void
    {
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('primary');
        $context                                                  = new ProviderCallContext(ProviderOperation::Chat, 'c', $configuration);
        $context->telemetrySignals->servedConfigurationIdentifier = 'fallback';

        try {
            (new EmptyAnswerMiddleware())->handle($context, fn (): CompletionResponse => $this->response('', 'length'));
            self::fail('No error for an empty answer.');
        } catch (OutputBudgetExhaustedException $e) {
            self::assertSame('fallback', $e->configurationIdentifier);
        }
    }

    #[Test]
    public function theFallbackChainDoesNotRetryIt(): void
    {
        self::assertFalse(FailureClassifier::classify(new OutputBudgetExhaustedException('x', 1))->isRetryable());
        self::assertSame(FailureClass::UNKNOWN, FailureClassifier::classify(new OutputBudgetExhaustedException('x', 1)));
    }

    #[Test]
    public function aTruncatedTextIsStillAnAnswer(): void
    {
        $response = $this->response('half an ans', 'length');

        self::assertSame($response, $this->handle($response));
    }

    #[Test]
    public function anEmptyAnswerThatStoppedNormallyIsLeftToItsCaller(): void
    {
        $response = $this->response('', 'stop');

        self::assertSame($response, $this->handle($response));
    }

    #[Test]
    public function aToolCallIsAnAnswerWithoutText(): void
    {
        $response = $this->response('', 'length', [ToolCall::function('1', 'search', [])]);

        self::assertSame($response, $this->handle($response));
    }

    #[Test]
    public function anotherResultPassesUntouched(): void
    {
        self::assertSame('raw', (new EmptyAnswerMiddleware())->handle(
            new ProviderCallContext(ProviderOperation::Embedding, 'c'),
            static fn (): string => 'raw',
        ));
    }

    private function handle(CompletionResponse $response): mixed
    {
        return (new EmptyAnswerMiddleware())->handle(
            new ProviderCallContext(ProviderOperation::Chat, 'c'),
            static fn (): CompletionResponse => $response,
        );
    }

    /**
     * @param list<ToolCall>|null $toolCalls
     */
    private function response(string $content, string $finishReason, ?array $toolCalls = null, int $completionTokens = 1): CompletionResponse
    {
        return new CompletionResponse($content, 'm', UsageStatistics::fromTokens(1, $completionTokens), finishReason: $finishReason, toolCalls: $toolCalls);
    }
}
