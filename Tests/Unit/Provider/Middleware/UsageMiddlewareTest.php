<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Middleware;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
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
use RuntimeException;

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
                serviceType: ProviderOperation::Chat->value,
                provider: 'openai',
                metrics: ['tokens' => 150, 'cost' => 0.0012],
                configurationUid: 7,
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
                ['tokens' => 42],
                null,
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
                ['tokens' => 300, 'cost' => 0.003],
                12,
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
                ['tokens' => 10],
                null,
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

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $this->pipeline()->run(
            context: ProviderCallContext::for(ProviderOperation::Chat),
            configuration: $this->configuration(),
            terminal: static function (LlmConfiguration $c): never {
                throw new RuntimeException('boom');
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
}
