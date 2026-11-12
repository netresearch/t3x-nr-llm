<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Observability;

use Netresearch\NrLlm\Domain\DTO\FallbackChain;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Observability\GenAiSemconv;
use Netresearch\NrLlm\Observability\GenAiTelemetry;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Fallback\FallbackCandidateResolver;
use Netresearch\NrLlm\Provider\Middleware\FallbackMiddleware;
use Netresearch\NrLlm\Provider\Middleware\GenAiTracingMiddleware;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Provider\Middleware\TelemetryMiddleware;
use Netresearch\NrLlm\Service\Health\ProviderHealthServiceInterface;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * The model call path of the OpenTelemetry instrumentation (ADR-291): the span
 * of an attempt, the fallback chain around attempts, the metrics, and the two
 * ways it stays silent. The SDK is the host installation's, stood in for by
 * in-memory exporters.
 */
#[CoversClass(GenAiTelemetry::class)]
#[CoversClass(GenAiTracingMiddleware::class)]
#[CoversClass(GenAiSemconv::class)]
#[CoversClass(FallbackMiddleware::class)]
final class GenAiTelemetryTest extends AbstractUnitTestCase
{
    use OpenTelemetryTestHarness;

    protected function tearDown(): void
    {
        $this->uninstallOpenTelemetrySdk();
        parent::tearDown();
    }

    #[Test]
    public function aModelCallIsAClientSpanNamedForTheConventionWithItsAttributes(): void
    {
        $this->installOpenTelemetrySdk();
        $pipeline = new MiddlewarePipeline([new GenAiTracingMiddleware($this->telemetry())]);

        $parent = Globals::tracerProvider()->getTracer('host')->spanBuilder('GET /ai')->startSpan();
        $scope  = $parent->activate();

        $context = ProviderCallContext::forService(
            ProviderOperation::Chat,
            'gemini',
            'gemini-2.5-pro',
            'summaries',
            [TelemetryMiddleware::METADATA_SOURCE_EXTENSION => 'nr_news'],
        );
        $result = $pipeline->run(
            $context,
            static fn (): CompletionResponse => new CompletionResponse(
                content: 'The text of the answer',
                model: 'gemini-2.5-pro-001',
                usage: UsageStatistics::fromTokens(11, 7),
                finishReason: 'length',
                provider: 'gemini',
            ),
        );
        $scope->detach();
        $parent->end();

        self::assertInstanceOf(CompletionResponse::class, $result);
        $spans = $this->spansNamed('chat gemini-2.5-pro');
        self::assertCount(1, $spans);

        $span       = $spans[0];
        $attributes = $span->getAttributes()->toArray();
        self::assertSame(SpanKind::KIND_CLIENT, $span->getKind());
        self::assertSame($parent->getContext()->getSpanId(), $span->getParentSpanId());
        self::assertSame('chat', $attributes['gen_ai.operation.name']);
        self::assertSame('gcp.gemini', $attributes['gen_ai.provider.name']);
        self::assertSame('gemini-2.5-pro', $attributes['gen_ai.request.model']);
        self::assertSame('gemini-2.5-pro-001', $attributes['gen_ai.response.model']);
        self::assertSame(['length'], $attributes['gen_ai.response.finish_reasons']);
        self::assertSame(11, $attributes['gen_ai.usage.input_tokens']);
        self::assertSame(7, $attributes['gen_ai.usage.output_tokens']);
        self::assertSame($context->correlationId, $attributes['nr_llm.correlation_id']);
        self::assertSame('summaries', $attributes['nr_llm.configuration']);
        self::assertSame('nr_news', $attributes['nr_llm.source_extension']);
        self::assertSame(0, $attributes['nr_llm.fallback.attempt']);
        self::assertSame(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
        self::assertStringNotContainsString('The text of the answer', $this->everythingRecorded());
    }

    #[Test]
    public function theDurationAndTheTokensOfACallAreMetrics(): void
    {
        $this->installOpenTelemetrySdk();
        $pipeline = new MiddlewarePipeline([new GenAiTracingMiddleware($this->telemetry())]);

        $pipeline->run(
            ProviderCallContext::forService(ProviderOperation::Chat, 'openai', 'gpt-4o'),
            static fn (): CompletionResponse => new CompletionResponse('x', 'gpt-4o-2024', UsageStatistics::fromTokens(30, 12), 'stop', 'openai'),
        );

        $duration = $this->histogramPoints('gen_ai.client.operation.duration');
        self::assertCount(1, $duration);
        self::assertSame(1, $duration[0]->count);
        $attributes = $duration[0]->attributes->toArray();
        self::assertSame('chat', $attributes['gen_ai.operation.name']);
        self::assertSame('openai', $attributes['gen_ai.provider.name']);
        self::assertSame('gpt-4o', $attributes['gen_ai.request.model']);
        self::assertArrayNotHasKey('error.type', $attributes);

        $sumsByType = [];
        foreach ($this->histogramPoints('gen_ai.client.token.usage') as $point) {
            $type = $point->attributes->toArray()['gen_ai.token.type'];
            self::assertIsString($type);
            $sumsByType[$type] = $point->sum;
        }

        self::assertSame(['input' => 30, 'output' => 12], $sumsByType);
    }

    #[Test]
    public function anEmbeddingCallRecordsInputTokensOnly(): void
    {
        $this->installOpenTelemetrySdk();
        $pipeline = new MiddlewarePipeline([new GenAiTracingMiddleware($this->telemetry())]);

        $pipeline->run(
            ProviderCallContext::forService(ProviderOperation::Embedding, 'openai', 'text-embedding-3-small'),
            static fn (): EmbeddingResponse => new EmbeddingResponse([[0.1, 0.2]], 'text-embedding-3-small', UsageStatistics::fromTokens(9, 0), 'openai'),
        );

        $attributes = $this->onlySpan('embeddings text-embedding-3-small')->getAttributes()->toArray();
        self::assertSame(9, $attributes['gen_ai.usage.input_tokens']);
        self::assertArrayNotHasKey('gen_ai.usage.output_tokens', $attributes);

        $types = [];
        foreach ($this->histogramPoints('gen_ai.client.token.usage') as $point) {
            $types[] = $point->attributes->toArray()['gen_ai.token.type'];
        }

        self::assertSame(['input'], $types);
    }

    #[Test]
    public function aFailedAttemptEndsItsSpanWithTheExceptionClassAndNeverTheMessage(): void
    {
        $this->installOpenTelemetrySdk();
        $pipeline = new MiddlewarePipeline([new GenAiTracingMiddleware($this->telemetry())]);
        $failure  = new ProviderConnectionException('POST https://api.example.com/v1?key=sk-secret-123 failed', 0);
        $caught   = null;

        try {
            $pipeline->run(
                ProviderCallContext::forService(ProviderOperation::Chat, 'openai', 'gpt-4o'),
                static function () use ($failure): mixed {
                    throw $failure;
                },
            );
        } catch (ProviderConnectionException $caught) {
            // Asserted below: the very instance reaches the caller.
        }

        self::assertSame($failure, $caught);

        $span = $this->onlySpan('chat gpt-4o');
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertSame(ProviderConnectionException::class, $span->getAttributes()->toArray()['error.type']);
        self::assertSame([], $span->getEvents());
        self::assertStringNotContainsString('sk-secret-123', $this->everythingRecorded());
        self::assertStringNotContainsString('api.example.com', $this->everythingRecorded());

        $duration = $this->histogramPoints('gen_ai.client.operation.duration');
        self::assertSame(ProviderConnectionException::class, $duration[0]->attributes->toArray()['error.type']);
    }

    #[Test]
    public function aFallbackChainShowsEveryAttemptAsAChildSpanAndTheFailedOnesAsEvents(): void
    {
        $this->installOpenTelemetrySdk();
        $telemetry  = $this->telemetry();
        $repository = self::createStub(LlmConfigurationRepository::class);
        $alt        = $this->configuration('alt');
        $repository->method('findOneByIdentifier')->willReturn($alt);
        $health = self::createStub(ProviderHealthServiceInterface::class);
        $health->method('reorder')->willReturnArgument(0);

        $pipeline = new MiddlewarePipeline([
            new FallbackMiddleware(new FallbackCandidateResolver($repository), new NullLogger(), $health, $telemetry),
            new GenAiTracingMiddleware($telemetry),
        ]);

        $parent = Globals::tracerProvider()->getTracer('host')->spanBuilder('worker')->startSpan();
        $scope  = $parent->activate();
        $result = $pipeline->run(
            ProviderCallContext::forConfiguration(ProviderOperation::Chat, $this->configuration('primary', new FallbackChain(['alt']))),
            static function (ProviderCallContext $context): CompletionResponse {
                if ($context->telemetryConfigurationIdentifier() === 'primary') {
                    throw new ProviderConnectionException('connection to the primary refused', 0);
                }

                return new CompletionResponse('x', 'm', UsageStatistics::fromTokens(1, 1), 'stop', 'openai');
            },
        );
        $scope->detach();
        $parent->end();

        self::assertInstanceOf(CompletionResponse::class, $result);

        $chain = $this->spansNamed('nr_llm.fallback_chain');
        self::assertCount(1, $chain);
        self::assertSame(SpanKind::KIND_INTERNAL, $chain[0]->getKind());
        self::assertSame($parent->getContext()->getSpanId(), $chain[0]->getParentSpanId());
        $chainAttributes = $chain[0]->getAttributes()->toArray();
        self::assertSame(1, $chainAttributes['nr_llm.fallback.attempts']);
        self::assertSame('alt', $chainAttributes['nr_llm.configuration.served']);
        self::assertSame('primary', $chainAttributes['nr_llm.configuration']);

        $events = $chain[0]->getEvents();
        self::assertCount(1, $events);
        self::assertSame('nr_llm.fallback.attempt_failed', $events[0]->getName());
        self::assertSame(
            ['nr_llm.configuration' => 'primary', 'error.type' => ProviderConnectionException::class],
            $events[0]->getAttributes()->toArray(),
        );

        $attempts = $this->spansNamed('chat');
        self::assertCount(2, $attempts);
        foreach ($attempts as $attempt) {
            self::assertSame($chain[0]->getSpanId(), $attempt->getParentSpanId());
        }

        [$first, $second] = $attempts;
        self::assertSame(StatusCode::STATUS_ERROR, $first->getStatus()->getCode());
        self::assertSame('primary', $first->getAttributes()->toArray()['nr_llm.configuration']);
        self::assertSame(0, $first->getAttributes()->toArray()['nr_llm.fallback.attempt']);
        self::assertSame(StatusCode::STATUS_UNSET, $second->getStatus()->getCode());
        self::assertSame('alt', $second->getAttributes()->toArray()['nr_llm.configuration']);
        self::assertSame(1, $second->getAttributes()->toArray()['nr_llm.fallback.attempt']);
        self::assertStringNotContainsString('refused', $this->everythingRecorded());
    }

    #[Test]
    public function everyFailedAttemptOfALongerChainIsOneEventInTheOrderItHappened(): void
    {
        $this->installOpenTelemetrySdk();
        $telemetry  = $this->telemetry();
        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturnCallback(fn (string $identifier): LlmConfiguration => $this->configuration($identifier));
        $health = self::createStub(ProviderHealthServiceInterface::class);
        $health->method('reorder')->willReturnArgument(0);

        $pipeline = new MiddlewarePipeline([
            new FallbackMiddleware(new FallbackCandidateResolver($repository), new NullLogger(), $health, $telemetry),
            new GenAiTracingMiddleware($telemetry),
        ]);

        $pipeline->run(
            ProviderCallContext::forConfiguration(ProviderOperation::Chat, $this->configuration('primary', new FallbackChain(['first', 'second']))),
            static function (ProviderCallContext $context): CompletionResponse {
                if ($context->telemetryConfigurationIdentifier() !== 'second') {
                    throw new ProviderConnectionException('down', 0);
                }

                return new CompletionResponse('x', 'm', UsageStatistics::fromTokens(1, 1), 'stop', 'openai');
            },
        );

        $chain = $this->onlySpan('nr_llm.fallback_chain');
        self::assertSame(2, $chain->getAttributes()->toArray()['nr_llm.fallback.attempts']);
        self::assertSame('second', $chain->getAttributes()->toArray()['nr_llm.configuration.served']);

        $failed = [];
        foreach ($chain->getEvents() as $event) {
            $failed[] = $event->getAttributes()->toArray()['nr_llm.configuration'];
        }

        self::assertSame(['primary', 'first'], $failed);
        self::assertCount(3, $this->spansNamed('chat'));
    }

    #[Test]
    public function aCallWhoseConfigurationHasNoChainGetsNoChainSpan(): void
    {
        $this->installOpenTelemetrySdk();
        $telemetry = $this->telemetry();
        $health    = self::createStub(ProviderHealthServiceInterface::class);
        $pipeline  = new MiddlewarePipeline([
            new FallbackMiddleware(new FallbackCandidateResolver(self::createStub(LlmConfigurationRepository::class)), new NullLogger(), $health, $telemetry),
            new GenAiTracingMiddleware($telemetry),
        ]);

        $pipeline->run(
            ProviderCallContext::forConfiguration(ProviderOperation::Chat, $this->configuration('solo')),
            static fn (): CompletionResponse => new CompletionResponse('x', 'm', UsageStatistics::fromTokens(1, 1), 'stop', 'openai'),
        );

        self::assertSame([], $this->spansNamed('nr_llm.fallback_chain'));
        self::assertCount(1, $this->spansNamed('chat'));
    }

    #[Test]
    public function aProviderStatusLookupIsNoAiCallAndGetsNoSpan(): void
    {
        $this->installOpenTelemetrySdk();
        $pipeline = new MiddlewarePipeline([new GenAiTracingMiddleware($this->telemetry())]);

        $result = $pipeline->run(
            ProviderCallContext::forService(ProviderOperation::Metadata, 'deepl', ''),
            static fn (): string => 'usage',
        );

        self::assertSame('usage', $result);
        self::assertSame([], $this->finishedSpans());
    }

    #[Test]
    public function withTheSettingOffAnInstalledSdkReceivesNothing(): void
    {
        $this->installOpenTelemetrySdk();
        $pipeline = new MiddlewarePipeline([new GenAiTracingMiddleware($this->telemetry(false))]);

        $result = $pipeline->run(
            ProviderCallContext::forService(ProviderOperation::Chat, 'openai', 'gpt-4o'),
            static fn (): string => 'answer',
        );

        self::assertSame('answer', $result);
        self::assertSame([], $this->finishedSpans());
        self::assertSame([], $this->histogramPoints('gen_ai.client.operation.duration'));
    }

    #[Test]
    public function withoutAnSdkTheCallRunsUnchangedAndNothingIsActive(): void
    {
        // No installOpenTelemetrySdk(): Globals answers with the API's no-op providers.
        $telemetry = $this->telemetry();
        $pipeline  = new MiddlewarePipeline([new GenAiTracingMiddleware($telemetry)]);
        $calls     = 0;

        $result = $pipeline->run(
            ProviderCallContext::forService(ProviderOperation::Chat, 'openai', 'gpt-4o'),
            static function () use (&$calls): string {
                ++$calls;

                return 'answer';
            },
        );

        self::assertFalse($telemetry->isActive());
        self::assertSame('answer', $result);
        self::assertSame(1, $calls);
    }

    #[Test]
    public function aMissingSettingMeansOn(): void
    {
        $this->installOpenTelemetrySdk();
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn([]);

        self::assertTrue((new GenAiTelemetry($configuration))->isActive());
    }

    #[Test]
    public function anUnreadableConfigurationMeansOn(): void
    {
        $this->installOpenTelemetrySdk();
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willThrowException(new RuntimeException('no configuration'));

        self::assertTrue((new GenAiTelemetry($configuration))->isActive());
    }

    #[Test]
    public function theInstrumentationScopeIsNamedForThePackage(): void
    {
        $this->installOpenTelemetrySdk();
        $pipeline = new MiddlewarePipeline([new GenAiTracingMiddleware($this->telemetry())]);

        $pipeline->run(
            ProviderCallContext::forService(ProviderOperation::Chat, 'openai', 'gpt-4o'),
            static fn (): string => 'x',
        );

        self::assertSame('netresearch/nr-llm', $this->onlySpan('chat gpt-4o')->getInstrumentationScope()->getName());
    }

    private function configuration(string $identifier, ?FallbackChain $chain = null): LlmConfiguration
    {
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier($identifier);
        $configuration->setIsActive(true);
        if ($chain instanceof FallbackChain) {
            $configuration->setFallbackChainDTO($chain);
        }

        return $configuration;
    }
}
