<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Evaluation;

use Netresearch\NrLlm\Service\Evaluation\EvaluationQualityScoreProvider;
use Netresearch\NrLlm\Service\Evaluation\EvaluationResultRepositoryInterface;
use Netresearch\NrLlm\Service\Evaluation\GeneratorEvaluationResultRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EvaluationQualityScoreProvider::class)]
final class EvaluationQualityScoreProviderTest extends TestCase
{
    #[Test]
    public function delegatesToRepositoryForKnownModel(): void
    {
        $repository = $this->createMock(GeneratorEvaluationResultRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('meanQualityScoreForModel')
            ->with('gpt-test', 'deterministic')
            ->willReturn(0.82);

        $provider = new EvaluationQualityScoreProvider($repository);

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
        $repository = $this->createMock(GeneratorEvaluationResultRepositoryInterface::class);
        $repository
            ->expects(self::once())
            ->method('meanQualityScoreForModel')
            ->with('unknown-model', 'deterministic')
            ->willReturn(null);
        self::assertNull(
            (new EvaluationQualityScoreProvider($repository))->getQualityScore(
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
        $repository = $this->createMock(GeneratorEvaluationResultRepositoryInterface::class);
        $repository->expects(self::never())->method('meanQualityScoreForModel');
        $repository
            ->expects(self::once())
            ->method('meanQualityScoreForProviderModel')
            ->with('custom-instance', 'shared-alias', 'deterministic')
            ->willReturn(0.0);
        self::assertSame(
            0.0,
            (new EvaluationQualityScoreProvider($repository))->getQualityScoreForProviderModel(
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
}
