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
use Netresearch\NrLlm\Provider\Middleware\BudgetMiddleware;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Provider\Middleware\UsageMiddleware;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;

#[CoversClass(UsageMiddleware::class)]
final class UsageMiddlewareTest extends AbstractUnitTestCase
{
    private UsageTrackerServiceInterface&MockObject $tracker;

    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tracker = $this->createMock(UsageTrackerServiceInterface::class);
        $this->logger  = $this->createMock(LoggerInterface::class);
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
                'gpt-4o-mini',
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
            context: ProviderCallContext::forConfiguration(ProviderOperation::Chat, $this->configuration(uid: 7)),
            terminal: static fn(): CompletionResponse => $response,
        );

        self::assertSame($response, $result);
    }

    #[Test]
    public function skipRequestCountMetadataRecordsMetricsButNotTheRequest(): void
    {
        // #473: when the manager flags a sub-call via METADATA_SKIP_REQUEST_COUNT,
        // the metrics are still recorded but trackUsage() is told not to count the
        // request (countsAsRequest = false, the 9th argument).
        $this->tracker->expects(self::once())
            ->method('trackUsage')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                false,
            );

        $response = new CompletionResponse(
            content: 'hi',
            model: 'gpt-4o-mini',
            usage: new UsageStatistics(100, 50, 150, 0.0012),
            finishReason: 'stop',
            provider: 'openai',
        );

        $this->pipeline()->run(
            context: ProviderCallContext::forConfiguration(
                ProviderOperation::Chat,
                $this->configuration(uid: 7),
                [UsageMiddleware::METADATA_SKIP_REQUEST_COUNT => true],
            ),
            terminal: static fn(): CompletionResponse => $response,
        );
    }

    #[Test]
    public function requestIsCountedByDefaultWithoutSkipMetadata(): void
    {
        $this->tracker->expects(self::once())
            ->method('trackUsage')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                true,
            );

        $response = new CompletionResponse(
            content: 'hi',
            model: 'gpt-4o-mini',
            usage: new UsageStatistics(100, 50, 150, 0.0012),
            finishReason: 'stop',
            provider: 'openai',
        );

        $this->pipeline()->run(
            context: ProviderCallContext::forConfiguration(ProviderOperation::Chat, $this->configuration(uid: 7)),
            terminal: static fn(): CompletionResponse => $response,
        );
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
                'claude-embed',
                0,
            );

        $response = new EmbeddingResponse(
            embeddings: [[0.1, 0.2]],
            model: 'claude-embed',
            usage: new UsageStatistics(42, 0, 42),  // no cost
            provider: 'claude',
        );

        $this->pipeline()->run(
            context: ProviderCallContext::forConfiguration(ProviderOperation::Embedding, $this->configuration()), // uid = null (not persisted)
            terminal: static fn(): EmbeddingResponse => $response,
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
                'gemini-vision',
                0,
            );

        $response = new VisionResponse(
            description: 'a cat',
            model: 'gemini-vision',
            usage: new UsageStatistics(200, 100, 300, 0.003),
            provider: 'gemini',
        );

        $this->pipeline()->run(
            context: ProviderCallContext::forConfiguration(ProviderOperation::Vision, $this->configuration(uid: 12)),
            terminal: static fn(): VisionResponse => $response,
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
                'm',
                0,
            );

        $response = new CompletionResponse(
            content: 'x',
            model: 'm',
            usage: new UsageStatistics(5, 5, 10),
            // $provider defaults to ''
        );

        $this->pipeline()->run(
            context: ProviderCallContext::forConfiguration(ProviderOperation::Chat, $this->configuration()),
            terminal: static fn(): CompletionResponse => $response,
        );
    }

    #[Test]
    public function skipsUnknownResultTypes(): void
    {
        $this->tracker->expects(self::never())->method('trackUsage');

        $result = $this->pipeline()->run(
            context: ProviderCallContext::forConfiguration(ProviderOperation::Chat, $this->configuration()),
            terminal: static fn(): string => 'raw-string-response',
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
            context: ProviderCallContext::forConfiguration(ProviderOperation::Chat, $this->configuration()),
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
            context: ProviderCallContext::forConfiguration(ProviderOperation::Chat, $config),
            terminal: static fn(): CompletionResponse => new CompletionResponse(
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
                'text-embedding-3-small',
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
            context: ProviderCallContext::forConfiguration(ProviderOperation::Embedding, $this->configuration()),
            terminal: static fn(): array => $payload,
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
            context: ProviderCallContext::forConfiguration(ProviderOperation::Embedding, $this->configuration()),
            terminal: static fn(): array => [
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
            context: ProviderCallContext::forConfiguration(ProviderOperation::Embedding, $this->configuration()),
            terminal: static fn(): array => [
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
            context: ProviderCallContext::forConfiguration(ProviderOperation::Embedding, $this->configuration()),
            terminal: static fn(): array => [
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
            context: ProviderCallContext::forConfiguration(ProviderOperation::Chat, $this->configurationWithModel($model, uid: 7)),
            terminal: static fn(): CompletionResponse => $response,
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
            context: ProviderCallContext::forConfiguration(ProviderOperation::Chat, $this->configurationWithModel($model, uid: 7)),
            terminal: static fn(): CompletionResponse => $response,
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
                'm',
                42,
            );

        $this->pipeline()->run(
            context: ProviderCallContext::forConfiguration(ProviderOperation::Chat, $this->configuration(), [UsageMiddleware::METADATA_TASK_UID => 42]),
            terminal: static fn(): CompletionResponse => new CompletionResponse(
                content: 'x',
                model: 'm',
                usage: new UsageStatistics(5, 5, 10),
                provider: 'openai',
            ),
        );
    }

    #[Test]
    public function recordsBeUserUidFromContextMetadata(): void
    {
        $this->tracker->expects(self::once())
            ->method('trackUsage')
            ->with(
                ProviderOperation::Chat->value,
                'openai',
                ['tokens' => 10, 'promptTokens' => 5, 'completionTokens' => 5],
                null,
                0,
                'm',
                0,
                99,
            );

        $this->pipeline()->run(
            context: ProviderCallContext::forConfiguration(ProviderOperation::Chat, $this->configuration(), [BudgetMiddleware::METADATA_BE_USER_UID => 99]),
            terminal: static fn(): CompletionResponse => new CompletionResponse(
                content: 'x',
                model: 'm',
                usage: new UsageStatistics(5, 5, 10),
                provider: 'openai',
            ),
        );
    }

    #[Test]
    public function passesNullBeUserUidWhenMetadataAbsent(): void
    {
        // Null defers the attribution decision to the tracker's ambient
        // backend.user fallback — the middleware must not coerce it to 0,
        // which would silently re-attribute backend-module calls.
        $this->tracker->expects(self::once())
            ->method('trackUsage')
            ->with(
                ProviderOperation::Chat->value,
                'openai',
                ['tokens' => 10, 'promptTokens' => 5, 'completionTokens' => 5],
                null,
                0,
                'm',
                0,
                null,
            );

        $this->pipeline()->run(
            context: ProviderCallContext::forConfiguration(ProviderOperation::Chat, $this->configuration()),
            terminal: static fn(): CompletionResponse => new CompletionResponse(
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

    #[Test]
    public function usageTrackerFailureIsSwallowedAndDoesNotBreakTheCall(): void
    {
        $response = new CompletionResponse(
            content: 'hi',
            model: 'gpt-4o-mini',
            usage: new UsageStatistics(100, 50, 150, 0.0012),
            finishReason: 'stop',
            provider: 'openai',
        );

        // A post-success bookkeeping failure (e.g. a DB deadlock while writing
        // tx_nrllm_service_usage) must be logged and swallowed, never re-thrown:
        // the already-served provider result has to reach the caller unchanged.
        $this->tracker->method('trackUsage')
            ->willThrowException(new RuntimeException('DB deadlock'));
        $this->logger->expects(self::once())->method('warning');

        $result = $this->pipeline()->run(
            context: ProviderCallContext::forConfiguration(ProviderOperation::Chat, $this->configuration(uid: 7)),
            terminal: static fn(): CompletionResponse => $response,
        );

        self::assertSame($response, $result);
    }

    private function pipeline(): MiddlewarePipeline
    {
        return new MiddlewarePipeline([new UsageMiddleware($this->tracker, $this->logger)]);
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
