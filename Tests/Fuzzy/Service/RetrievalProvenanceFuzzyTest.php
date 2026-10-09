<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Fuzzy\Service;

use Eris\Generators;
use InvalidArgumentException;
use Netresearch\NrLlm\Domain\Enum\QuestionForm;
use Netresearch\NrLlm\Service\Evaluation\GoldenQuestion;
use Netresearch\NrLlm\Service\Evaluation\GoldenQuestionSet;
use Netresearch\NrLlm\Service\Evaluation\RetrievalProvenance;
use Netresearch\NrLlm\Service\Evaluation\RetrievalRunIdentity;
use Netresearch\NrLlm\Tests\Fuzzy\AbstractFuzzyTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(RetrievalProvenance::class)]
#[CoversClass(RetrievalRunIdentity::class)]
final class RetrievalProvenanceFuzzyTest extends AbstractFuzzyTestCase
{
    #[Test]
    public function labelsAreAcceptedExactlyWithinTheByteBoundary(): void
    {
        $this
            ->forAll(Generators::choose(0, 230))
            ->then(
                function (int $length): void {
                    try {
                        $value = new RetrievalProvenance(
                            str_repeat('a', $length),
                            'model-v1',
                            'chunk-v1',
                            'pipeline-v1',
                        );
                        self::assertGreaterThan(0, $length);
                        self::assertLessThanOrEqual(
                            RetrievalProvenance::MAX_IDENTITY_BYTES,
                            $length,
                        );
                        self::assertSame(
                            $value->toArray(),
                            (new RetrievalProvenance(...$value->toArray()))->toArray(),
                        );
                    } catch (InvalidArgumentException) {
                        self::assertTrue(
                            $length === 0 || $length > RetrievalProvenance::MAX_IDENTITY_BYTES,
                        );
                    }
                },
            );
    }

    #[Test]
    public function canonicalEncodingDoesNotCollapseConcatenationTwins(): void
    {
        $this
            ->forAll(Generators::choose(1, 50), Generators::choose(1, 50))
            ->then(
                function (int $left, int $right): void {
                    $a = str_repeat('a', $left);
                    $b = str_repeat('b', $right);
                    $provenance = new RetrievalProvenance(
                        'corpus-v1',
                        'model-v1',
                        'chunk-v1',
                        'pipeline-v1',
                    );
                    $first = new GoldenQuestion(
                        $a,
                        'x' . $b,
                        QuestionForm::MATCH,
                        ['doc-a'],
                    );
                    $second = new GoldenQuestion(
                        $a . 'x',
                        $b,
                        QuestionForm::MATCH,
                        ['doc-a'],
                    );
                    self::assertSame(
                        $first->id . $first->question,
                        $second->id . $second->question,
                    );
                    $one = RetrievalRunIdentity::forSet(
                        new GoldenQuestionSet('test', 'Test', 'desc', [$first]),
                        $provenance,
                    );
                    $two = RetrievalRunIdentity::forSet(
                        new GoldenQuestionSet('test', 'Test', 'desc', [$second]),
                        $provenance,
                    );
                    self::assertNotSame(
                        $one->labelsFingerprint,
                        $two->labelsFingerprint,
                    );
                    self::assertNotSame(
                        $one->benchmarkFingerprint,
                        $two->benchmarkFingerprint,
                    );
                    self::assertSame(
                        $one->variantFingerprint,
                        $two->variantFingerprint,
                    );
                },
            );
    }
    #[Test]
    public function questionAndTargetPermutationsKeepTheBenchmark(): void
    {
        $this
            ->forAll(Generators::choose(1, 50))
            ->then(
                function (int $suffix): void {
                    $provenance = new RetrievalProvenance(
                        'corpus-v1',
                        'model-v1',
                        'chunk-v1',
                        'pipeline-v1',
                    );
                    $a = new GoldenQuestion(
                        'a',
                        'Question-' . $suffix,
                        QuestionForm::MATCH,
                        ['doc-a', 'doc-b'],
                    );
                    $b = new GoldenQuestion(
                        'b',
                        'Other-' . $suffix,
                        QuestionForm::GAP,
                        [],
                    );
                    $reordered = new GoldenQuestion(
                        'a',
                        'Question-' . $suffix,
                        QuestionForm::MATCH,
                        ['doc-b', 'doc-a'],
                    );
                    $one = RetrievalRunIdentity::forSet(
                        new GoldenQuestionSet('test', 'Test', 'desc', [$a, $b]),
                        $provenance,
                    );
                    $two = RetrievalRunIdentity::forSet(
                        new GoldenQuestionSet(
                            'test',
                            'Renamed',
                            'Different presentation',
                            [$b, $reordered],
                        ),
                        $provenance,
                    );
                    self::assertSame(
                        $one->labelsFingerprint,
                        $two->labelsFingerprint,
                    );
                    self::assertSame(
                        $one->benchmarkFingerprint,
                        $two->benchmarkFingerprint,
                    );
                },
            );
    }
}
