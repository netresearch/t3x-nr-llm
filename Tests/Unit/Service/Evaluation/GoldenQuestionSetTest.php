<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Evaluation;

use Netresearch\NrLlm\Domain\Enum\QuestionForm;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Evaluation\GoldenQuestion;
use Netresearch\NrLlm\Service\Evaluation\GoldenQuestionSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GoldenQuestionSet::class)]
#[CoversClass(GoldenQuestion::class)]
final class GoldenQuestionSetTest extends TestCase
{
    private function question(string $id = 'q1'): GoldenQuestion
    {
        return new GoldenQuestion($id, 'Where is the office?', QuestionForm::MATCH, ['doc-1']);
    }

    #[Test]
    public function validSetIsConstructed(): void
    {
        $set = new GoldenQuestionSet('nr_ai_search.bmdv', 'BMDV', 'A set', [$this->question()]);

        self::assertSame('nr_ai_search.bmdv', $set->identifier);
        self::assertCount(1, $set->questions);
    }

    #[Test]
    public function validQuestionCarriesItsLabels(): void
    {
        $question = new GoldenQuestion(
            'q1',
            'Wo wird der Ausbildungsstand erfasst?',
            QuestionForm::GAP,
            ['247_0_0', '250_0_0'],
            'specific-vs-general',
            'BASt und DEGES ermitteln den Stand im BIM-Radar.',
        );

        self::assertSame(QuestionForm::GAP, $question->form);
        self::assertSame(['247_0_0', '250_0_0'], $question->expectedDocumentIds);
        self::assertSame('specific-vs-general', $question->hardClass);
        self::assertFalse($question->expectsNoResult());
    }

    #[Test]
    public function emptyExpectedDocumentsDeclareANoResultQuestion(): void
    {
        $question = new GoldenQuestion('q1', 'Is there a moon base?', QuestionForm::GAP, []);

        self::assertTrue($question->expectsNoResult());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidIdentifierProvider(): array
    {
        return [
            'empty' => [''],
            'uppercase' => ['NrAiSearch.bmdv'],
            'leading dot' => ['.bmdv'],
            'trailing dot' => ['bmdv.'],
            'spaces' => ['nr ai search'],
        ];
    }

    #[Test]
    #[DataProvider('invalidIdentifierProvider')]
    public function invalidIdentifierIsRejected(string $identifier): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1794000055);
        self::assertInstanceOf(GoldenQuestionSet::class, new GoldenQuestionSet($identifier, 'BMDV', 'A set', [$this->question()]));
    }

    #[Test]
    public function emptyNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1794000056);
        self::assertInstanceOf(GoldenQuestionSet::class, new GoldenQuestionSet('a.set', '', 'A set', [$this->question()]));
    }

    #[Test]
    public function emptyQuestionListIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1794000057);
        self::assertInstanceOf(GoldenQuestionSet::class, new GoldenQuestionSet('a.set', 'BMDV', 'A set', []));
    }

    #[Test]
    public function duplicateQuestionIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1794000058);
        self::assertInstanceOf(GoldenQuestionSet::class, new GoldenQuestionSet('a.set', 'BMDV', 'A set', [
            $this->question('q1'),
            $this->question('q1'),
        ]));
    }

    #[Test]
    public function emptyQuestionIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1794000050);
        self::assertInstanceOf(GoldenQuestion::class, new GoldenQuestion('', 'Where?', QuestionForm::MATCH, ['doc-1']));
    }

    #[Test]
    public function emptyQuestionTextIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1794000051);
        self::assertInstanceOf(GoldenQuestion::class, new GoldenQuestion('q1', '', QuestionForm::MATCH, ['doc-1']));
    }

    #[Test]
    public function emptyExpectedDocumentIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1794000052);
        self::assertInstanceOf(GoldenQuestion::class, new GoldenQuestion('q1', 'Where?', QuestionForm::MATCH, ['']));
    }

    #[Test]
    public function duplicateExpectedDocumentIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1794000053);
        self::assertInstanceOf(GoldenQuestion::class, new GoldenQuestion('q1', 'Where?', QuestionForm::MATCH, ['doc-1', 'doc-1']));
    }

    #[Test]
    public function emptyHardClassIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1794000054);
        self::assertInstanceOf(GoldenQuestion::class, new GoldenQuestion('q1', 'Where?', QuestionForm::MATCH, ['doc-1'], ''));
    }
}
