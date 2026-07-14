<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Evaluation;

use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Service\Evaluation\ModelQualityScoreProviderInterface;
use Netresearch\NrLlm\Service\Evaluation\QualityAwareModelSelector;
use Netresearch\NrLlm\Service\ModelSelectionServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(QualityAwareModelSelector::class)]
final class QualityAwareModelSelectorTest extends TestCase
{
    private function model(string $modelId): Model
    {
        $model = new Model();
        $model->setModelId($modelId);

        return $model;
    }

    /**
     * @param list<Model> $candidates
     */
    private function selectionService(array $candidates): ModelSelectionServiceInterface
    {
        $stub = self::createStub(ModelSelectionServiceInterface::class);
        $stub->method('findCandidates')->willReturn($candidates);

        return $stub;
    }

    /**
     * @param array<string, float|null> $scores
     */
    private function qualityProvider(array $scores): ModelQualityScoreProviderInterface
    {
        return new class ($scores) implements ModelQualityScoreProviderInterface {
            /**
             * @param array<string, float|null> $scores
             */
            public function __construct(private readonly array $scores) {}

            public function getQualityScore(string $modelId): ?float
            {
                return $this->scores[$modelId] ?? null;
            }
        };
    }

    #[Test]
    public function returnsNullWhenNoCandidatesMatch(): void
    {
        $selector = new QualityAwareModelSelector($this->selectionService([]), $this->qualityProvider([]));

        self::assertNull($selector->selectByQuality(['capabilities' => ['chat']]));
    }

    #[Test]
    public function ranksHighestQualityFirst(): void
    {
        $selector = new QualityAwareModelSelector(
            $this->selectionService([$this->model('a'), $this->model('b'), $this->model('c')]),
            $this->qualityProvider(['a' => 0.5, 'b' => 0.9, 'c' => 0.7]),
        );

        $selected = $selector->selectByQuality(['capabilities' => ['chat']]);

        self::assertNotNull($selected);
        self::assertSame('b', $selected->getModelId());
    }

    #[Test]
    public function minQualityFiltersOutLowAndUnscoredModels(): void
    {
        $selector = new QualityAwareModelSelector(
            $this->selectionService([$this->model('a'), $this->model('b'), $this->model('c')]),
            $this->qualityProvider(['a' => 0.5, 'b' => 0.9]),
        );

        $selected = $selector->selectByQuality(['capabilities' => ['chat']], 0.8);

        self::assertNotNull($selected);
        self::assertSame('b', $selected->getModelId());
    }

    #[Test]
    public function returnsNullWhenNoCandidateMeetsMinQuality(): void
    {
        $selector = new QualityAwareModelSelector(
            $this->selectionService([$this->model('a'), $this->model('b')]),
            $this->qualityProvider(['a' => 0.5, 'b' => 0.6]),
        );

        self::assertNull($selector->selectByQuality(['capabilities' => ['chat']], 0.9));
    }

    #[Test]
    public function fallsBackToBaseOrderWhenNoScoresExist(): void
    {
        // No quality data and no minimum → degrades to the base selection's first candidate.
        $selector = new QualityAwareModelSelector(
            $this->selectionService([$this->model('first'), $this->model('second')]),
            $this->qualityProvider([]),
        );

        $selected = $selector->selectByQuality(['capabilities' => ['chat']]);

        self::assertNotNull($selected);
        self::assertSame('first', $selected->getModelId());
    }

    #[Test]
    public function scoredModelsOutrankUnscoredEvenWhenListedLater(): void
    {
        $selector = new QualityAwareModelSelector(
            $this->selectionService([$this->model('unscored'), $this->model('scored')]),
            $this->qualityProvider(['scored' => 0.4]),
        );

        $selected = $selector->selectByQuality(['capabilities' => ['chat']]);

        self::assertNotNull($selected);
        self::assertSame('scored', $selected->getModelId());
    }
}
