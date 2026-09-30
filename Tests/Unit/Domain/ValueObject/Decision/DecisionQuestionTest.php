<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject\Decision;

use Netresearch\NrLlm\Domain\ValueObject\Decision\ChoiceQuestion;
use Netresearch\NrLlm\Domain\ValueObject\Decision\QuestionGuard;
use Netresearch\NrLlm\Domain\ValueObject\Decision\QuestionType;
use Netresearch\NrLlm\Domain\ValueObject\Decision\ScoreQuestion;
use Netresearch\NrLlm\Domain\ValueObject\Decision\YesNoQuestion;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(YesNoQuestion::class)]
#[CoversClass(ChoiceQuestion::class)]
#[CoversClass(ScoreQuestion::class)]
#[CoversClass(QuestionGuard::class)]
final class DecisionQuestionTest extends TestCase
{
    #[Test]
    public function eachQuestionReportsItsKeyTypeAndInstructions(): void
    {
        $yesNo  = new YesNoQuestion('supported', 'Is it supported?', 'backed by evidence', 'not backed');
        $choice = new ChoiceQuestion('team', 'Which team?', ['billing', 'technical'], ['billing' => 'Payments']);
        $score  = new ScoreQuestion('quality', 'How good?', ['bad', 'fair', 'good']);

        self::assertSame(['supported', QuestionType::YesNo, 'Is it supported?'], [$yesNo->key(), $yesNo->type(), $yesNo->instructions()]);
        self::assertSame(['team', QuestionType::Choice, 'Which team?'], [$choice->key(), $choice->type(), $choice->instructions()]);
        self::assertSame(['quality', QuestionType::Score, 'How good?'], [$score->key(), $score->type(), $score->instructions()]);
        self::assertSame(2, $score->maxLevel());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'upper case' => ['Quality'];
        yield 'leading digit' => ['1st'];
        yield 'dash' => ['is-urgent'];
        yield 'space' => ['is urgent'];
        yield '65 characters' => ['a' . str_repeat('b', 64)];
        // `$` alone matches before a final newline; the key must end where it ends.
        yield 'trailing newline' => ["quality\n"];
    }

    #[Test]
    #[DataProvider('invalidKeys')]
    public function aKeyThatWouldNotSurviveEveryWireFormatIsRefused(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1795211001);

        self::assertInstanceOf(YesNoQuestion::class, new YesNoQuestion($key, 'Is it?'));
    }

    #[Test]
    public function aSixtyFourCharacterKeyIsAccepted(): void
    {
        $key = 'a' . str_repeat('b', 63);

        self::assertSame($key, (new YesNoQuestion($key, 'Is it?'))->key());
    }

    #[Test]
    public function blankInstructionsAreRefused(): void
    {
        $this->expectExceptionCode(1795211002);

        self::assertInstanceOf(ScoreQuestion::class, new ScoreQuestion('quality', "  \n", ['bad', 'good']));
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function optionCounts(): iterable
    {
        yield 'one' => [1, false];
        yield 'two' => [2, true];
        yield '255' => [255, true];
        yield '256' => [256, false];
    }

    #[Test]
    #[DataProvider('optionCounts')]
    public function aChoiceTakesTwoTo255Options(int $count, bool $accepted): void
    {
        $options = [];
        for ($i = 0; $i < $count; ++$i) {
            $options[] = 'option_' . $i;
        }

        if (!$accepted) {
            $this->expectExceptionCode(1795211003);
        }

        self::assertCount($count, (new ChoiceQuestion('pick', 'Pick one', $options))->options);
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function levelCounts(): iterable
    {
        yield 'one' => [1, false];
        yield 'two' => [2, true];
        yield 'ten' => [10, true];
        yield 'eleven' => [11, false];
    }

    #[Test]
    #[DataProvider('levelCounts')]
    public function aScoreTakesTwoToTenLevels(int $count, bool $accepted): void
    {
        if (!$accepted) {
            $this->expectExceptionCode(1795211003);
        }

        $question = new ScoreQuestion('rate', 'Rate it', array_map(static fn(int $i): string => 'level ' . $i, range(1, $count)));

        self::assertSame($count - 1, $question->maxLevel());
    }

    #[Test]
    public function aBlankLevelDescriptionIsRefused(): void
    {
        $this->expectExceptionCode(1795211002);

        self::assertInstanceOf(ScoreQuestion::class, new ScoreQuestion('rate', 'Rate it', ['bad', '']));
    }

    #[Test]
    public function optionsGivenAsAMapAreRefused(): void
    {
        $this->expectExceptionCode(1795211007);

        self::assertInstanceOf(ChoiceQuestion::class, new ChoiceQuestion('pick', 'Pick one', ['first' => 'a', 'second' => 'b'])); // @phpstan-ignore argument.type
    }

    #[Test]
    public function levelsGivenAsAMapAreRefused(): void
    {
        $this->expectExceptionCode(1795211007);

        self::assertInstanceOf(ScoreQuestion::class, new ScoreQuestion('rate', 'Rate it', [1 => 'bad', 2 => 'good'])); // @phpstan-ignore argument.type
    }

    #[Test]
    public function twoLevelsDescribedAlikeAreRefused(): void
    {
        $this->expectExceptionCode(1795211006);

        self::assertInstanceOf(ScoreQuestion::class, new ScoreQuestion('rate', 'Rate it', ['bad', 'good', 'bad']));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function blankMeanings(): iterable
    {
        yield 'yes' => [' ', ''];
        yield 'no' => ['', "\t"];
    }

    #[Test]
    #[DataProvider('blankMeanings')]
    public function aBlankMeaningOfYesOrNoIsRefusedWhileAnAbsentOneIsFine(string $yesMeans, string $noMeans): void
    {
        self::assertSame('', (new YesNoQuestion('ok', 'Ok?'))->yesMeans);

        $this->expectExceptionCode(1795211002);
        self::assertInstanceOf(YesNoQuestion::class, new YesNoQuestion('ok', 'Ok?', $yesMeans, $noMeans));
    }

    #[Test]
    public function aBlankOptionDescriptionIsRefused(): void
    {
        $this->expectExceptionCode(1795211002);

        self::assertInstanceOf(ChoiceQuestion::class, new ChoiceQuestion('pick', 'Pick one', ['a', 'b'], ['a' => ' ']));
    }

    #[Test]
    public function aBlankOptionNameIsRefused(): void
    {
        $this->expectExceptionCode(1795211002);

        self::assertInstanceOf(ChoiceQuestion::class, new ChoiceQuestion('pick', 'Pick one', ['a', ' ']));
    }

    #[Test]
    public function numericOptionNamesAreNamesLikeAnyOther(): void
    {
        $question = new ChoiceQuestion('stars', 'How many stars?', ['0', '1', '2'], ['0' => 'none', '2' => 'two']);

        self::assertSame(['0', '1', '2'], $question->options);
        self::assertSame(['none', '', 'two'], [$question->describe('0'), $question->describe('1'), $question->describe('2')]);
    }

    #[Test]
    public function anOptionNamedTwiceIsRefused(): void
    {
        $this->expectExceptionCode(1795211004);

        self::assertInstanceOf(ChoiceQuestion::class, new ChoiceQuestion('pick', 'Pick one', ['a', 'b', 'a']));
    }

    #[Test]
    public function aDescriptionOfAnOptionTheQuestionDoesNotOfferIsRefused(): void
    {
        $this->expectExceptionCode(1795211005);

        self::assertInstanceOf(ChoiceQuestion::class, new ChoiceQuestion('pick', 'Pick one', ['a', 'b'], ['c' => 'not offered']));
    }
}
