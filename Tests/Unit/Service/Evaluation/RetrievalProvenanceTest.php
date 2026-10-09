<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Evaluation;

use InvalidArgumentException;
use Netresearch\NrLlm\Domain\Enum\QuestionForm;
use Netresearch\NrLlm\Service\Evaluation\GoldenQuestion;
use Netresearch\NrLlm\Service\Evaluation\GoldenQuestionSet;
use Netresearch\NrLlm\Service\Evaluation\RetrievalProvenance;
use Netresearch\NrLlm\Service\Evaluation\RetrievalRunIdentity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RetrievalProvenance::class)]
#[CoversClass(RetrievalRunIdentity::class)]
final class RetrievalProvenanceTest extends TestCase
{
    private function set(GoldenQuestion ...$questions): GoldenQuestionSet
    {
        return new GoldenQuestionSet('test.corpus', 'Set', 'Description', array_values($questions));
    }

    private function provenance(
        string $corpus = 'corpus-v1',
        string $model = 'embed-v1',
        string $execution = 'commit-a',
    ): RetrievalProvenance {
        return new RetrievalProvenance($corpus, $model, 'chunk-v1', 'hybrid-v1', $execution);
    }

    #[Test]
    public function labelsIgnoreOrderAndCommentaryButKeepScoringFields(): void
    {
        $a = new GoldenQuestion(
            'a',
            'Question A?',
            QuestionForm::MATCH,
            ['doc-b', 'doc-a'],
            'normal',
            'comment-a',
        );
        $b = new GoldenQuestion('b', 'Question B?', QuestionForm::GAP, []);
        $reordered = new GoldenQuestion(
            'a',
            'Question A?',
            QuestionForm::MATCH,
            ['doc-a', 'doc-b'],
            'normal',
            'comment-b',
        );
        $first = RetrievalRunIdentity::forSet($this->set($a, $b), $this->provenance());
        $second = RetrievalRunIdentity::forSet($this->set($b, $reordered), $this->provenance());
        self::assertSame($first->labelsFingerprint, $second->labelsFingerprint);
        self::assertSame($first->benchmarkFingerprint, $second->benchmarkFingerprint);
        $changed = new GoldenQuestion(
            'a',
            'Changed question?',
            QuestionForm::MATCH,
            ['doc-a', 'doc-b'],
            'normal',
        );
        self::assertNotSame(
            $first->labelsFingerprint,
            RetrievalRunIdentity::forSet($this->set($changed, $b), $this->provenance())->labelsFingerprint,
        );
    }

    #[Test]
    public function benchmarkAndTreatmentChangesAreIndependent(): void
    {
        $set = $this->set(new GoldenQuestion('a', 'Question?', QuestionForm::MATCH, ['doc-a']));
        $first = RetrievalRunIdentity::forSet($set, $this->provenance());
        $corpus = RetrievalRunIdentity::forSet($set, $this->provenance('corpus-v2'));
        $model = RetrievalRunIdentity::forSet($set, $this->provenance(model: 'embed-v2'));
        $execution = RetrievalRunIdentity::forSet($set, $this->provenance(execution: 'commit-b'));
        self::assertNotSame($first->benchmarkFingerprint, $corpus->benchmarkFingerprint);
        self::assertSame($first->variantFingerprint, $corpus->variantFingerprint);
        self::assertSame($first->benchmarkFingerprint, $model->benchmarkFingerprint);
        self::assertNotSame($first->variantFingerprint, $model->variantFingerprint);
        self::assertSame($first->benchmarkFingerprint, $execution->benchmarkFingerprint);
        self::assertSame($first->variantFingerprint, $execution->variantFingerprint);
    }

    #[Test]
    public function missingCapabilityIsUnknownAndNotLexicalNotApplicable(): void
    {
        $set = $this->set(new GoldenQuestion('a', 'Question?', QuestionForm::GAP, []));
        $unknown = RetrievalRunIdentity::forSet($set, null);
        $lexical = RetrievalRunIdentity::forSet(
            $set,
            new RetrievalProvenance(
                'corpus-v1',
                RetrievalProvenance::NOT_APPLICABLE,
                RetrievalProvenance::NOT_APPLICABLE,
                'lexical-v1',
            ),
        );
        self::assertNull($unknown->provenance);
        self::assertSame('', $unknown->benchmarkFingerprint);
        self::assertSame('', $unknown->variantFingerprint);
        self::assertNotSame('', $lexical->benchmarkFingerprint);
    }

    #[Test]
    public function unsafeOrUnboundedIdentityIsRefused(): void
    {
        $this->assertInvalidProvenance(
            ['corpus-v1', 'https://user:password@example.test/model', 'chunk-v1', 'hybrid-v1'],
            1794000215,
        );
    }

    #[Test]
    public function everyScoringLabelFieldChangesTheBenchmark(): void
    {
        $baseline = new GoldenQuestion('a', 'Question?', QuestionForm::MATCH, ['doc-a'], 'normal');
        $original = RetrievalRunIdentity::forSet($this->set($baseline), $this->provenance());
        foreach ([
            new GoldenQuestion('b', 'Question?', QuestionForm::MATCH, ['doc-a'], 'normal'),
            new GoldenQuestion('a', 'Different?', QuestionForm::MATCH, ['doc-a'], 'normal'),
            new GoldenQuestion('a', 'Question?', QuestionForm::GAP, ['doc-a'], 'normal'),
            new GoldenQuestion('a', 'Question?', QuestionForm::MATCH, ['doc-b'], 'normal'),
            new GoldenQuestion('a', 'Question?', QuestionForm::MATCH, ['doc-a'], 'rare'),
        ] as $changed) {
            $identity = RetrievalRunIdentity::forSet($this->set($changed), $this->provenance());
            self::assertNotSame($original->labelsFingerprint, $identity->labelsFingerprint);
            self::assertNotSame($original->benchmarkFingerprint, $identity->benchmarkFingerprint);
            self::assertSame($original->variantFingerprint, $identity->variantFingerprint);
        }
    }

    #[Test]
    public function chunkingAndPipelineChangeOnlyTheTreatment(): void
    {
        $set = $this->set(new GoldenQuestion('a', 'Question?', QuestionForm::MATCH, ['doc-a']));
        $original = RetrievalRunIdentity::forSet($set, $this->provenance());
        foreach ([
            new RetrievalProvenance('corpus-v1', 'embed-v1', 'chunk-v2', 'hybrid-v1'),
            new RetrievalProvenance('corpus-v1', 'embed-v1', 'chunk-v1', 'hybrid-v2'),
        ] as $changed) {
            $identity = RetrievalRunIdentity::forSet($set, $changed);
            self::assertSame($original->benchmarkFingerprint, $identity->benchmarkFingerprint);
            self::assertNotSame($original->variantFingerprint, $identity->variantFingerprint);
        }
    }

    #[Test]
    public function validationCoversEveryIdentityAndItsLengthBoundary(): void
    {
        $valid = new RetrievalProvenance(str_repeat('x', 190), 'org/model:v1', 'chunk-v1', 'pipeline-v1');
        self::assertSame(190, strlen($valid->corpusRevision));
        foreach (['', str_repeat('x', 191), 'http://host/v1', "line\nbreak", 'body text', 'über-v1'] as $invalid) {
            foreach (range(0, 4) as $position) {
                $values = ['corpus-v1', 'model-v1', 'chunk-v1', 'pipeline-v1', 'execution-v1'];
                $values[$position] = $invalid;
                $this->assertInvalidProvenance($values, 1794000215);
            }
        }

        foreach (['corpusRevision', 'pipelineIdentity'] as $field) {
            $values = [
                'corpusRevision' => 'corpus-v1',
                'modelRevision' => 'model-v1',
                'chunkingIdentity' => 'chunk-v1',
                'pipelineIdentity' => 'pipeline-v1',
            ];
            $values[$field] = RetrievalProvenance::NOT_APPLICABLE;
            $this->assertInvalidProvenance($values, 1794000216);
        }
    }

    /**
     * Acceptance fails with the resulting metadata; rejection must retain its reason.
     *
     * @param array<array-key, string> $values
     */
    private function assertInvalidProvenance(array $values, int $exceptionCode): void
    {
        try {
            $accepted = new RetrievalProvenance(...$values);
            self::fail(
                'Invalid provenance was accepted: ' . json_encode($accepted->toArray(), JSON_THROW_ON_ERROR),
            );
        } catch (InvalidArgumentException $exception) {
            self::assertSame($exceptionCode, $exception->getCode());
        }
    }
}
