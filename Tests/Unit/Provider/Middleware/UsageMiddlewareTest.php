<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Middleware;

use LogicException;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Provider\Middleware\UsageMiddleware;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionProperty;

#[CoversClass(UsageMiddleware::class)]
final class UsageMiddlewareTest extends AbstractUnitTestCase
{
    private UsageTrackerServiceInterface&MockObject $tracker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tracker = $this->createMock(UsageTrackerServiceInterface::class);
    }

    #[Test]
    public function tracksCompletionResponseWithTokensAndCost(): void
    {
        $this->tracker->expects(self::once())
            ->method('trackUsage')
            ->with(
                ProviderOperation::Chat->value,
                'openai',
                ['tokens' => 150, 'promptTokens' => 100, 'completionTokens' => 50, 'cost' => 0.0012],
                7,
                0,
                '',
                0,
            );

        $response = new CompletionResponse(
            content: 'hi',
            model: 'gpt-4o-mini',
            usage: new UsageStatistics(100, 50, 150, 0.0012),
            finishReason: 'stop',
            provider: 'openai',
        );

        $result = $this->pipeline()->run(
            context: ProviderCallContext::for(ProviderOperation::Chat),
            configuration: $this->configuration(uid: 7),
            terminal: static fn(LlmConfiguration $c): CompletionResponse => $response,
        );

        self::assertSame($response, $result);
    }

    #[Test]
    public function tracksEmbeddingResponseWithOperationAsServiceType(): void
    {
        $this->tracker->expects(self::once())
            ->method('trackUsage')
            ->with(
                ProviderOperation::Embedding->value,
                'claude',
                ['tokens' => 42, 'promptTokens' => 42, 'completionTokens' => 0],
                null,
                0,
                '',
                0,
            );

        $response = new EmbeddingResponse(
            embeddings: [[0.1, 0.2]],
            model: 'claude-embed',
            usage: new UsageStatistics(42, 0, 42),  // no cost
            provider: 'claude',
        );

        $this->pipeline()->run(
            context: ProviderCallContext::for(ProviderOperation::Embedding),
            configuration: $this->configuration(), // uid = null (not persisted)
            terminal: static fn(LlmConfiguration $c): EmbeddingResponse => $response,
        );
    }

    #[Test]
    public function tracksVisionResponse(): void
    {
        $this->tracker->expects(self::once())
            ->method('trackUsage')
            ->with(
                ProviderOperation::Vision->value,
                'gemini',
                ['tokens' => 300, 'promptTokens' => 200, 'completionTokens' => 100, 'cost' => 0.003],
                12,
                0,
                '',
                0,
            );

        $response = new VisionResponse(
            description: 'a cat',
            model: 'gemini-vision',
            usage: new UsageStatistics(200, 100, 300, 0.003),
            provider: 'gemini',
        );

        $this->pipeline()->run(
            context: ProviderCallContext::for(ProviderOperation::Vision),
            configuration: $this->configuration(uid: 12),
            terminal: static fn(LlmConfiguration $c): VisionResponse => $response,
        );
    }

    #[Test]
    public function usesUnknownProviderWhenResponseLacksOne(): void
    {
        $this->tracker->expects(self::once())
            ->method('trackUsage')
            ->with(
                ProviderOperation::Chat->value,
                'unknown',
                ['tokens' => 10, 'promptTokens' => 5, 'completionTokens' => 5],
                null,
                0,
                '',
                0,
            );

        $response = new CompletionResponse(
            content: 'x',
            model: 'm',
            usage: new UsageStatistics(5, 5, 10),
            // $provider defaults to ''
        );

        $this->pipeline()->run(
            context: ProviderCallContext::for(ProviderOperation::Chat),
            configuration: $this->configuration(),
            terminal: static fn(LlmConfiguration $c): CompletionResponse => $response,
        );
    }

    #[Test]
    public function skipsUnknownResultTypes(): void
    {
        $this->tracker->expects(self::never())->method('trackUsage');

        $result = $this->pipeline()->run(
            context: ProviderCallContext::for(ProviderOperation::Chat),
            configuration: $this->configuration(),
            terminal: static fn(LlmConfiguration $c): string => 'raw-string-response',
        );

        self::assertSame('raw-string-response', $result);
    }

    #[Test]
    public function doesNotTrackWhenTerminalThrows(): void
    {
        $this->tracker->expects(self::never())->method('trackUsage');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('boom');

        $this->pipeline()->run(
            context: ProviderCallContext::for(ProviderOperation::Chat),
            configuration: $this->configuration(),
            terminal: static function (): never {
                throw new LogicException('boom', 1504818594);
            },
        );
    }

    #[Test]
    public function recordsConfigurationUidNullWhenUidIsZero(): void
    {
        $this->tracker->expects(self::once())
            ->method('trackUsage')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                null,
                self::anything(),
                self::anything(),
                self::anything(),
            );

        // Force uid = 0 via reflection (the public getter maps null -> int | null,
        // but a just-loaded entity from the repo can have getUid() === 0 in
        // unusual bootstrapping cases; the middleware must treat it as null).
        $config = $this->configuration();
        $this->setUid($config, 0);

        $this->pipeline()->run(
            context: ProviderCallContext::for(ProviderOperation::Chat),
            configuration: $config,
            terminal: static fn(LlmConfiguration $c): CompletionResponse => new CompletionResponse(
                content: 'x',
                model: 'm',
                usage: new UsageStatistics(1, 1, 2),
                provider: 'p',
            ),
        );
    }

    // -----------------------------------------------------------------------
    // Array-payload path — terminal returns `array<string, mixed>` on the
    // CacheMiddleware codec path (embeddings). UsageMiddleware must still
    // record from the `usage` / `provider` sub-keys.
    // -----------------------------------------------------------------------

    #[Test]
    public function tracksArrayPayloadEmittedByCacheCodec(): void
    {
        $this->tracker->expects(self::once())
            ->method('trackUsage')
            ->with(
                ProviderOperation::Embedding->value,
                'openai',
                ['tokens' => 42, 'promptTokens' => 42, 'completionTokens' => 0, 'cost' => 0.0015],
                null,
                0,
                '',
                0,
            );

        $payload = [
            'embeddings' => [[0.1, 0.2]],
            'model'      => 'text-embedding-3-small',
            'provider'   => 'openai',
            'usage'      => [
                'promptTokens'     => 42,
                'completionTokens' => 0,
                'totalTokens'      => 42,
                'estimatedCost'    => 0.0015,
            ],
        ];

        $result = $this->pipeline()->run(
            context: ProviderCallContext::for(ProviderOperation::Embedding),
            configuration: $this->configuration(),
            terminal: static fn(LlmConfiguration $c): array => $payload,
        );

        self::assertSame($payload, $result);
    }

    #[Test]
    public function arrayPayloadWithEmptyProviderRecordsUnknown(): void
    {
        $this->tracker->expects(self::once())
            ->method('trackUsage')
            ->with(
                self::anything(),
                'unknown',
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
            );

        $this->pipeline()->run(
            context: ProviderCallContext::for(ProviderOperation::Embedding),
            configuration: $this->configuration(),
            terminal: static fn(LlmConfiguration $c): array => [
                'provider' => '',
                'usage'    => ['totalTokens' => 5],
            ],
        );
    }

    #[Test]
    public function arrayPayloadMissingUsageKeyIsSkipped(): void
    {
        $this->tracker->expects(self::never())->method('trackUsage');

        $this->pipeline()->run(
            context: ProviderCallContext::for(ProviderOperation::Embedding),
            configuration: $this->configuration(),
            terminal: static fn(LlmConfiguration $c): array => [
                'embeddings' => [[0.1]],
                'provider'   => 'openai',
                // no 'usage' key — nothing reliable to record
            ],
        );
    }

    #[Test]
    public function arrayPayloadWithNonStringProviderIsSkipped(): void
    {
        $this->tracker->expects(self::never())->method('trackUsage');

        $this->pipeline()->run(
            context: ProviderCallContext::for(ProviderOperation::Embedding),
            configuration: $this->configuration(),
            terminal: static fn(LlmConfiguration $c): array => [
                'provider' => ['not', 'a', 'string'],
                'usage'    => ['totalTokens' => 5],
            ],
        );
    }

    #[Test]
    public function derivesCostFromModelPricingWhenResponseHasNoCost(): void
    {
        // gpt-4o demo pricing: $2.50 / 1M input, $10.00 / 1M output
        // -> cost_input = 250 cents/1M, cost_output = 1000 cents/1M
        // estimateCost(1000, 500) = (1000/1e6)*2.50 + (500/1e6)*10.00 = 0.0025 + 0.005 = 0.0075
        $this->tracker->expects(self::once())
            ->method('trackUsage')
            ->with(
                ProviderOperation::Chat->value,
                'openai',
                ['tokens' => 1500, 'promptTokens' => 1000, 'completionTokens' => 500, 'cost' => 0.0075],
                7,
                99,
                'gpt-4o',
                0,
            );

        $model = new Model();
        $model->setModelId('gpt-4o');
        $model->setCostInput(250);
        $model->setCostOutput(1000);
        $this->setModelUid($model, 99);

        $response = new CompletionResponse(
            content: 'hi',
            model: 'gpt-4o',
            usage: new UsageStatistics(1000, 500, 1500), // no cost on the response
            finishReason: 'stop',
            provider: 'openai',
        );

        $this->pipeline()->run(
            context: ProviderCallContext::for(ProviderOperation::Chat),
            configuration: $this->configurationWithModel($model, uid: 7),
            terminal: static fn(LlmConfiguration $c): CompletionResponse => $response,
        );
    }

    #[Test]
    public function keepsResponseCostWhenPresentEvenIfModelHasPricing(): void
    {
        // estimatedCost on the response wins; we do not recompute.
        $this->tracker->expects(self::once())
            ->method('trackUsage')
            ->with(
                ProviderOperation::Chat->value,
                'openai',
                ['tokens' => 1500, 'promptTokens' => 1000, 'completionTokens' => 500, 'cost' => 0.99],
                7,
                99,
                'gpt-4o',
                0,
            );

        $model = new Model();
        $model->setModelId('gpt-4o');
        $model->setCostInput(250);
        $model->setCostOutput(1000);
        $this->setModelUid($model, 99);

        $response = new CompletionResponse(
            content: 'hi',
            model: 'gpt-4o',
            usage: new UsageStatistics(1000, 500, 1500, 0.99),
            finishReason: 'stop',
            provider: 'openai',
        );

        $this->pipeline()->run(
            context: ProviderCallContext::for(ProviderOperation::Chat),
            configuration: $this->configurationWithModel($model, uid: 7),
            terminal: static fn(LlmConfiguration $c): CompletionResponse => $response,
        );
    }

    #[Test]
    public function recordsTaskUidFromContextMetadata(): void
    {
        $this->tracker->expects(self::once())
            ->method('trackUsage')
            ->with(
                ProviderOperation::Chat->value,
                'openai',
                ['tokens' => 10, 'promptTokens' => 5, 'completionTokens' => 5],
                null,
                0,
                '',
                42,
            );

        $this->pipeline()->run(
            context: ProviderCallContext::for(ProviderOperation::Chat, [UsageMiddleware::METADATA_TASK_UID => 42]),
            configuration: $this->configuration(),
            terminal: static fn(LlmConfiguration $c): CompletionResponse => new CompletionResponse(
                content: 'x',
                model: 'm',
                usage: new UsageStatistics(5, 5, 10),
                provider: 'openai',
            ),
        );
    }

    // -----------------------------------------------------------------------
    // Test helpers
    // -----------------------------------------------------------------------

    private function pipeline(): MiddlewarePipeline
    {
        return new MiddlewarePipeline([new UsageMiddleware($this->tracker)]);
    }

    private function configuration(?int $uid = null): LlmConfiguration
    {
        $config = new LlmConfiguration();
        $config->setIdentifier('primary');
        if ($uid !== null) {
            $this->setUid($config, $uid);
        }

        return $config;
    }

    private function setUid(LlmConfiguration $config, int $uid): void
    {
        $prop = new ReflectionProperty($config, 'uid');
        $prop->setValue($config, $uid);
    }

    private function configurationWithModel(Model $model, ?int $uid = null): LlmConfiguration
    {
        $config = $this->configuration($uid);
        $config->setLlmModel($model);

        return $config;
    }

    private function setModelUid(Model $model, int $uid): void
    {
        $prop = new ReflectionProperty($model, 'uid');
        $prop->setValue($model, $uid);
    }
}
