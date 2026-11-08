<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Middleware;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ProviderCallUsage;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Provider\Middleware\UsageMiddleware;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;

#[CoversClass(UsageMiddleware::class)]
final class UsagePresenceTest extends AbstractUnitTestCase
{
    /**
     * @param array<string, int|float> $reported
     */
    #[Test]
    #[DataProvider('usageCases')]
    public function recordedCostRequiresKnownInputs(
        bool $priced,
        array $reported,
        ?int $input,
        ?int $output,
        ?float $expectedCost,
    ): void {
        $model = new Model();
        $model->setModelId('priced-model');
        $model->setCostInput($priced ? 250 : 0);
        $model->setCostOutput($priced ? 1000 : 0);

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('primary');
        $configuration->setLlmModel($model);

        $context = ProviderCallContext::forConfiguration(
            ProviderOperation::Chat,
            $configuration,
        );
        $tracker = $this->createMock(UsageTrackerServiceInterface::class);
        $tracker
            ->expects(self::once())
            ->method('trackUsage')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(
                    static fn (
                        array $metrics,
                    ): bool => $expectedCost === null ? !array_key_exists('cost', $metrics) : ($metrics['cost'] ?? null) === $expectedCost,
                ),
            );
        // Exercise the cache codec too: normalization must preserve absence,
        // including when a partial measurement is serialized and restored.
        $normalized = UsageStatistics::fromArray($reported);
        $response   = new CompletionResponse(
            content: 'answer',
            model: 'priced-model',
            usage: UsageStatistics::fromArray($normalized->toArray()),
            provider: 'openai',
        );
        $middleware = new UsageMiddleware($tracker, new NullLogger());
        self::assertSame(
            $response,
            $middleware->handle(
                $context,
                static fn (): CompletionResponse => $response,
            ),
        );
        $recorded = $context->telemetrySignals->callUsage;
        self::assertInstanceOf(ProviderCallUsage::class, $recorded);
        self::assertSame($input, $recorded->inputTokens);
        self::assertSame($output, $recorded->outputTokens);
        self::assertSame($expectedCost, $recorded->cost);
    }

    /**
     * @return iterable<string, array{bool, array<string, int|float>, ?int, ?int, ?float}>
     */
    public static function usageCases(): iterable
    {
        foreach ([false, true] as $priced) {
            $prefix = $priced ? 'priced' : 'unpriced';
            yield $prefix . ' absent' => [$priced, [], null, null, null];
            yield $prefix . ' explicit zero' => [
                $priced,
                [
                    'promptTokens'     => 0,
                    'completionTokens' => 0,
                    'totalTokens'      => 0,
                ],
                0,
                0,
                $priced ? 0.0 : null,
            ];
            yield $prefix . ' input only' => [$priced, ['promptTokens' => 1000], 1000, null, null];
            yield $prefix . ' output only' => [$priced, ['completionTokens' => 500], null, 500, null];
            yield $prefix . ' positive' => [
                $priced,
                [
                    'promptTokens'     => 1000,
                    'completionTokens' => 500,
                    'totalTokens'      => 1500,
                ],
                1000,
                500,
                $priced ? 0.0075 : null,
            ];
            yield $prefix . ' independent monetary amount' => [$priced, ['estimatedCost' => 0.025], null, null, 0.025];
            yield $prefix . ' independent zero monetary amount' => [$priced, ['estimatedCost' => 0.0], null, null, 0.0];
        }
    }
}
