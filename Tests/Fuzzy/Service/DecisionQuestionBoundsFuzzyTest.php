<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fuzzy\Service;

use Eris\Generator;
use Netresearch\NrLlm\Domain\ValueObject\Decision\ChoiceQuestion;
use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionAnswer;
use Netresearch\NrLlm\Domain\ValueObject\Decision\ScoreQuestion;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Tests\Fuzzy\AbstractFuzzyTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * The validation ranges of the decision value objects (ADR-211): a choice
 * takes 2..255 options, a score 2..10 levels, a probability lies in 0..1.
 * The accepted and the refused side of each bound, over the whole range.
 */
#[CoversClass(ChoiceQuestion::class)]
#[CoversClass(ScoreQuestion::class)]
#[CoversClass(DecisionAnswer::class)]
final class DecisionQuestionBoundsFuzzyTest extends AbstractFuzzyTestCase
{
    #[Test]
    public function aChoiceIsAcceptedExactlyForTwoTo255Options(): void
    {
        $this
            ->forAll(Generator\choose(0, 300)) // @phpstan-ignore function.notFound
            ->then(function (int $count): void {
                $options = [];
                for ($i = 0; $i < $count; ++$i) {
                    $options[] = 'o' . $i;
                }

                $this->assertSame(
                    $count >= ChoiceQuestion::MIN_OPTIONS && $count <= ChoiceQuestion::MAX_OPTIONS,
                    $this->accepts(static fn(): ChoiceQuestion => new ChoiceQuestion('pick', 'Pick one', $options)),
                );
            });
    }

    #[Test]
    public function aScoreIsAcceptedExactlyForTwoToTenLevels(): void
    {
        $this
            ->forAll(Generator\choose(0, 20)) // @phpstan-ignore function.notFound
            ->then(function (int $count): void {
                $levels = $count === 0 ? [] : array_map(static fn(int $i): string => 'level ' . $i, range(1, $count));

                $this->assertSame(
                    $count >= ScoreQuestion::MIN_LEVELS && $count <= ScoreQuestion::MAX_LEVELS,
                    $this->accepts(static fn(): ScoreQuestion => new ScoreQuestion('rate', 'Rate it', $levels)),
                );
            });
    }

    #[Test]
    public function aProbabilityOfYesIsAcceptedExactlyInsideZeroToOne(): void
    {
        $this
            ->forAll(Generator\choose(-2000, 3000)) // @phpstan-ignore function.notFound
            ->then(function (int $thousandths): void {
                $value = $thousandths / 1000;

                $this->assertSame(
                    $value >= 0.0 && $value <= 1.0,
                    $this->accepts(static fn(): DecisionAnswer => DecisionAnswer::yesNo('ok', $value)),
                );
            });
    }

    /**
     * @param callable(): object $build
     */
    private function accepts(callable $build): bool
    {
        try {
            $build();

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
