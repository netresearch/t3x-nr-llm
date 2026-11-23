<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Streaming;

use Generator;
use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\DTO\FallbackChain;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Observability\GenAiTelemetry;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Fallback\FallbackCandidateResolver;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\Streaming\StreamingDispatcher;
use Netresearch\NrLlm\Tests\Unit\Fixture\InMemoryTelemetryRepository;
use Netresearch\NrLlm\Tests\Unit\Fixture\RecordingLogger;
use Netresearch\NrLlm\Tests\Unit\Fixture\RecordingUsageTracker;
use Netresearch\NrLlm\Tests\Unit\Observability\OpenTelemetryTestHarness;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\AspectInterface;
use TYPO3\CMS\Core\Context\Context;

/**
 * The streaming part of the OpenTelemetry instrumentation (ADR-291): one span
 * for a stream, started when its consumer first pulls, parented to the code that
 * asked for it, and ended when the stream ends or is dropped.
 */
#[CoversClass(StreamingDispatcher::class)]
#[CoversClass(GenAiTelemetry::class)]
#[AllowMockObjectsWithoutExpectations]
final class StreamingDispatcherTracingTest extends TestCase
{
    use OpenTelemetryTestHarness;

    protected function tearDown(): void
    {
        $this->uninstallOpenTelemetrySdk();
        parent::tearDown();
    }

    #[Test]
    public function aStreamIsOneSpanWithTheTimeToFirstChunkAndNoTokenCounts(): void
    {
        $this->installOpenTelemetrySdk();
        $parent = Globals::tracerProvider()->getTracer('host')->spanBuilder('GET /chat')->startSpan();
        $scope  = $parent->activate();

        $generator = $this->dispatcher()->stream(
            $this->context(),
            $this->configuration('chat', 'openai', 'gpt-4o'),
            static function (): Generator {
                yield 'The first words of a private answer';
                yield ' and the rest';
            },
        );
        // The consumer pulls later, inside some other span; the stream still hangs
        // below the code that asked for it.
        $scope->detach();
        $other      = Globals::tracerProvider()->getTracer('host')->spanBuilder('consumer')->startSpan();
        $otherScope = $other->activate();
        $chunks     = iterator_to_array($generator);
        $otherScope->detach();
        $other->end();
        $parent->end();

        self::assertSame(['The first words of a private answer', ' and the rest'], $chunks);
        $spans = $this->spansNamed('chat gpt-4o');
        self::assertCount(1, $spans);

        $span       = $spans[0];
        $attributes = $span->getAttributes()->toArray();
        self::assertSame(SpanKind::KIND_CLIENT, $span->getKind());
        self::assertSame($parent->getContext()->getSpanId(), $span->getParentSpanId());
        self::assertSame('chat', $attributes['gen_ai.operation.name']);
        self::assertSame('openai', $attributes['gen_ai.provider.name']);
        self::assertSame('gpt-4o', $attributes['gen_ai.request.model']);
        self::assertTrue($attributes['gen_ai.request.stream']);
        self::assertIsFloat($attributes['gen_ai.response.time_to_first_chunk']);
        self::assertArrayNotHasKey('gen_ai.usage.input_tokens', $attributes);
        self::assertArrayNotHasKey('gen_ai.usage.output_tokens', $attributes);
        self::assertSame(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
        self::assertStringNotContainsString('private answer', $this->everythingRecorded());

        self::assertCount(1, $this->histogramPoints('gen_ai.client.operation.time_to_first_chunk'));
        self::assertCount(1, $this->histogramPoints('gen_ai.client.operation.duration'));
        self::assertSame([], $this->histogramPoints('gen_ai.client.token.usage'));
    }

    #[Test]
    public function aStreamThatSwappedToAFallbackNamesTheConfigurationThatAnswered(): void
    {
        $this->installOpenTelemetrySdk();
        $fallback   = $this->configuration('fallback', 'anthropic', 'claude-sonnet');
        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturn($fallback);

        $generator = $this->dispatcher($repository)->stream(
            $this->context(),
            $this->configuration('primary', 'openai', 'gpt-4o', new FallbackChain(['fallback'])),
            static function (LlmConfiguration $configuration): Generator {
                if ($configuration->getIdentifier() === 'primary') {
                    throw new ProviderConnectionException('primary down', 0);
                }

                yield 'ok';
            },
        );
        iterator_to_array($generator);

        $spans = $this->spansNamed('chat claude-sonnet');
        self::assertCount(1, $spans);
        $attributes = $spans[0]->getAttributes()->toArray();
        self::assertSame('anthropic', $attributes['gen_ai.provider.name']);
        self::assertSame('fallback', $attributes['nr_llm.configuration']);
    }

    #[Test]
    public function aStreamThatFailsEndsItsSpanWithTheExceptionClass(): void
    {
        $this->installOpenTelemetrySdk();
        $generator = $this->dispatcher()->stream(
            $this->context(),
            $this->configuration('chat', 'openai', 'gpt-4o'),
            static function (): Generator {
                yield 'partial';

                throw new RuntimeException('provider said: the prompt was "secret plan"', 7846839957);
            },
        );

        try {
            iterator_to_array($generator);
            self::fail('The failure must reach the consumer.');
        } catch (RuntimeException) {
            // Re-thrown untouched.
        }

        $span = $this->onlySpan('chat gpt-4o');
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertSame(RuntimeException::class, $span->getAttributes()->toArray()['error.type']);
        self::assertStringNotContainsString('secret plan', $this->everythingRecorded());
    }

    #[Test]
    public function aStreamTheConsumerDropsEndsItsSpanAsAbandonedNotAsAnError(): void
    {
        $this->installOpenTelemetrySdk();
        $generator = $this->dispatcher()->stream(
            $this->context(),
            $this->configuration('chat', 'openai', 'gpt-4o'),
            static function (): Generator {
                yield 'one';
                yield 'two';
            },
        );
        $generator->current();
        unset($generator);

        $span = $this->onlySpan('chat gpt-4o');
        self::assertSame(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
        self::assertTrue($span->getAttributes()->toArray()['nr_llm.stream.abandoned']);
    }

    #[Test]
    public function withoutAnSdkAStreamRunsUnchanged(): void
    {
        $chunks = iterator_to_array($this->dispatcher()->stream(
            $this->context(),
            $this->configuration('chat', 'openai', 'gpt-4o'),
            static function (): Generator {
                yield 'a';
                yield 'b';
            },
        ));

        self::assertSame(['a', 'b'], $chunks);
    }

    private function dispatcher(?LlmConfigurationRepository $repository = null): StreamingDispatcher
    {
        $budget = self::createStub(BudgetServiceInterface::class);
        $budget->method('check')->willReturn(BudgetCheckResult::allowed());

        $aspect = self::createStub(AspectInterface::class);
        $aspect->method('get')->willReturn(0);
        $context = self::createStub(Context::class);
        $context->method('getAspect')->willReturn($aspect);

        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['telemetry' => ['enabled' => '1']]);

        return new StreamingDispatcher(
            $budget,
            new RecordingUsageTracker(),
            new InMemoryTelemetryRepository(),
            new FallbackCandidateResolver($repository ?? self::createStub(LlmConfigurationRepository::class)),
            new RecordingLogger(),
            $context,
            $extensionConfiguration,
            genAi: $this->telemetry(),
        );
    }

    private function context(): ProviderCallContext
    {
        return new ProviderCallContext(ProviderOperation::Stream, 'corr-1');
    }

    private function configuration(string $identifier, string $providerType, string $modelId, ?FallbackChain $chain = null): LlmConfiguration
    {
        $configuration = $this->createMock(LlmConfiguration::class);
        $configuration->method('getIdentifier')->willReturn($identifier);
        $configuration->method('getProviderType')->willReturn($providerType);
        $configuration->method('getModelId')->willReturn($modelId);
        $configuration->method('getLlmModel')->willReturn(null);
        $configuration->method('getUid')->willReturn(null);
        $configuration->method('isActive')->willReturn(true);
        $configuration->method('getFallbackChainDTO')->willReturn($chain ?? new FallbackChain());

        return $configuration;
    }
}
