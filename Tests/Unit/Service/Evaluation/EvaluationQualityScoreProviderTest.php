<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Evaluation;

use Netresearch\NrLlm\Service\Evaluation\EvaluationQualityScoreProvider;
use Netresearch\NrLlm\Service\Evaluation\EvaluationResultRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EvaluationQualityScoreProvider::class)]
final class EvaluationQualityScoreProviderTest extends TestCase
{
    #[Test]
    public function delegatesToRepositoryForKnownModel(): void
    {
        $repository = $this->createMock(EvaluationResultRepositoryInterface::class);
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
        $repository = $this->createMock(EvaluationResultRepositoryInterface::class);
        $repository->method('meanQualityScoreForModel')->willReturn(null);

        $provider = new EvaluationQualityScoreProvider($repository);

        self::assertNull($provider->getQualityScore('unknown-model'));
    }
}
