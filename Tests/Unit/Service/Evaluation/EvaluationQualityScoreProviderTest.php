<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Evaluation;

use Netresearch\NrLlm\Service\Evaluation\BenchmarkEvaluationResultRepositoryInterface;
use Netresearch\NrLlm\Service\Evaluation\EvaluationQualityScoreProvider;
use Netresearch\NrLlm\Service\Evaluation\EvaluationResultRepositoryInterface;
use Netresearch\NrLlm\Service\Evaluation\GeneratorEvaluationResultRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(EvaluationQualityScoreProvider::class)]
final class EvaluationQualityScoreProviderTest extends TestCase
{
    #[Test]
    public function delegatesToRepositoryForKnownModel(): void
    {
        $repository = $this->createMock(BenchmarkEvaluationResultRepositoryInterface::class);
        $repository
            ->expects(self::once())
            ->method('qualityScoreForBenchmark')
            ->with(
                'test.set',
                'v1:' . str_repeat('a', 64),
                'gpt-test',
                'deterministic',
                null,
            )
            ->willReturn(0.82);

        $provider = new EvaluationQualityScoreProvider($repository, $this->configuration());

        self::assertSame(0.82, $provider->getQualityScore('gpt-test'));
    }

    #[Test]
    public function emptyModelIdShortCircuitsWithoutQuerying(): void
    {
        $repository = $this->createMock(EvaluationResultRepositoryInterface::class);
        $repository->expects(self::never())->method('meanQualityScoreForModel');

        $provider = new EvaluationQualityScoreProvider($repository);

        self::assertNull($provider->getQualityScore(''));
    }

    #[Test]
    public function returnsNullWhenModelHasNoResults(): void
    {
        $repository = $this->createMock(BenchmarkEvaluationResultRepositoryInterface::class);
        $repository
            ->expects(self::once())
            ->method('qualityScoreForBenchmark')
            ->with(
                'test.set',
                'v1:' . str_repeat('a', 64),
                'unknown-model',
                'deterministic',
                null,
            )
            ->willReturn(null);
        self::assertNull(
            (new EvaluationQualityScoreProvider(
                $repository,
                $this->configuration(),
            ))->getQualityScore(
                'unknown-model',
            ),
        );
    }

    #[Test]
    public function legacyRepositoryCannotSupplyUnverifiedRoutingScores(): void
    {
        $repository = $this->createMock(EvaluationResultRepositoryInterface::class);
        $repository->expects(self::never())->method('meanQualityScoreForModel');
        $provider = new EvaluationQualityScoreProvider($repository);
        self::assertNull($provider->getQualityScore('shared-alias'));
        self::assertNull(
            $provider->getQualityScoreForProviderModel(
                'instance-a',
                'shared-alias',
            ),
        );
    }

    #[Test]
    public function scopedCapabilityUsesTheConfiguredProviderInstanceAndPreservesZero(): void
    {
        $repository = $this->createMock(BenchmarkEvaluationResultRepositoryInterface::class);
        $repository->expects(self::never())->method('meanQualityScoreForModel');
        $repository
            ->expects(self::once())
            ->method('qualityScoreForBenchmark')
            ->with(
                'test.set',
                'v1:' . str_repeat('a', 64),
                'shared-alias',
                'deterministic',
                'custom-instance',
            )
            ->willReturn(0.0);
        self::assertSame(
            0.0,
            (new EvaluationQualityScoreProvider(
                $repository,
                $this->configuration(),
            ))->getQualityScoreForProviderModel(
                'custom-instance',
                'shared-alias',
            ),
        );
    }

    #[Test]
    public function emptyScopedIdentifiersDoNotQueryTheRepository(): void
    {
        $repository = $this->createMock(GeneratorEvaluationResultRepositoryInterface::class);
        $repository
            ->expects(self::never())
            ->method('meanQualityScoreForProviderModel');
        $provider = new EvaluationQualityScoreProvider($repository);
        self::assertNull(
            $provider->getQualityScoreForProviderModel('', 'shared-alias'),
        );
        self::assertNull(
            $provider->getQualityScoreForProviderModel('instance-a', ''),
        );
    }

    private function configuration(): ExtensionConfiguration
    {
        $config = self::createStub(ExtensionConfiguration::class);
        $config
            ->method('get')
            ->willReturn(
                [
                    'routing' => [
                        'qualitySet'         => 'test.set',
                        'qualityFingerprint' => 'v1:' . str_repeat('a', 64),
                    ],
                ],
            );

        return $config;
    }

    #[Test]
    public function missingBenchmarkSelectionIsUnknown(): void
    {
        $repository = $this->createMock(BenchmarkEvaluationResultRepositoryInterface::class);
        $repository->expects(self::never())->method('qualityScoreForBenchmark');
        self::assertNull(
            (new EvaluationQualityScoreProvider($repository))->getQualityScore(
                'model',
            ),
        );
    }
}
