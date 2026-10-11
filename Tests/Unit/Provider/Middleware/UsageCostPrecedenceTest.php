<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Middleware;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ProviderCallUsage;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Provider\Middleware\UsageMiddleware;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(UsageMiddleware::class)]
final class UsageCostPrecedenceTest extends AbstractUnitTestCase
{
    /**
     * @return array<string, array{bool}>
     */
    public static function responseShapes(): array
    {
        return ['typed response' => [false], 'cache codec payload' => [true]];
    }

    #[Test]
    #[DataProvider('responseShapes')]
    public function providerReportedZeroSurvivesPositiveModelPricing(
        bool $arrayPayload,
    ): void {
        $model = new Model();
        $model->setModelId('priced-model');
        $model->setCostInput(250);
        $model->setCostOutput(1000);
        self::assertTrue($model->hasPricing());
        self::assertSame(0.0075, $model->estimateCost(1000, 500));
        $configuration = new LlmConfiguration();
        $configuration->setLlmModel($model);

        $context = ProviderCallContext::forConfiguration(
            ProviderOperation::Chat,
            $configuration,
        );
        $usage = new UsageStatistics(1000, 500, 1500, 0.0);
        $response = $arrayPayload ? [
            'model' => 'priced-model',
            'provider' => 'example',
            'usage' => $usage->toArray(),
        ] : new CompletionResponse(
            content: 'served',
            model: 'priced-model',
            usage: $usage,
            provider: 'example',
        );
        $tracker = $this->createMock(UsageTrackerServiceInterface::class);
        $tracker
            ->expects(self::once())
            ->method('trackUsage')
            ->with(
                'chat',
                'example',
                [
                    'tokens' => 1500,
                    'promptTokens' => 1000,
                    'completionTokens' => 500,
                    'cost' => 0.0,
                ],
                null,
                0,
                'priced-model',
                0,
            );
        $pipeline = new MiddlewarePipeline(
            [new UsageMiddleware($tracker, $this->createLoggerMock())],
        );
        $result = $pipeline->run($context, static fn(): mixed => $response);
        self::assertSame($response, $result);
        $recorded = $context->telemetrySignals->callUsage;
        self::assertInstanceOf(ProviderCallUsage::class, $recorded);
        self::assertSame(0.0, $recorded->cost);
        self::assertSame(1000, $recorded->inputTokens);
        self::assertSame(500, $recorded->outputTokens);
    }
}
