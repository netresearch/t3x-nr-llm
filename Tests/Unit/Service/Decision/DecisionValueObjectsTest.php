<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Decision;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionAnswer;
use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionSubject;
use Netresearch\NrLlm\Domain\ValueObject\Decision\ProbabilityKind;
use Netresearch\NrLlm\Domain\ValueObject\Decision\QuestionType;
use Netresearch\NrLlm\Domain\ValueObject\Decision\SubjectField;
use Netresearch\NrLlm\Domain\ValueObject\Decision\YesNoQuestion;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Decision\DecisionException;
use Netresearch\NrLlm\Service\Decision\DecisionRequest;
use Netresearch\NrLlm\Service\Decision\DecisionResult;
use Netresearch\NrLlm\Service\Decision\Profile\DecisionProfile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(DecisionSubject::class)]
#[CoversClass(DecisionProfile::class)]
#[CoversClass(DecisionAnswer::class)]
#[CoversClass(DecisionResult::class)]
#[CoversClass(DecisionException::class)]
#[CoversClass(DecisionRequest::class)]
final class DecisionValueObjectsTest extends TestCase
{
    #[Test]
    public function aSubjectFieldIsPresentWhenSetEvenIfItIsEmpty(): void
    {
        $subject = new DecisionSubject(task: 'Q', candidate: '');

        self::assertTrue($subject->has(SubjectField::Task));
        self::assertTrue($subject->has(SubjectField::Candidate), 'a blank answer is something to judge');
        self::assertFalse($subject->has(SubjectField::Evidence));
        self::assertSame(['task' => 'Q', 'candidate' => ''], $subject->toState());
    }

    #[Test]
    public function evidenceGivenAsAMapIsRefused(): void
    {
        $this->expectExceptionCode(1795211008);

        self::assertInstanceOf(DecisionSubject::class, new DecisionSubject(evidence: ['doc-7' => 'passage'])); // @phpstan-ignore argument.type
    }

    #[Test]
    public function mapTransformsEveryTextAndKeepsAbsentFieldsAbsent(): void
    {
        $subject = (new DecisionSubject(candidate: 'a', evidence: ['b', 'c']))->map(strtoupper(...));

        self::assertSame(['candidate' => 'A', 'evidence' => ['B', 'C']], $subject->toState());
        self::assertNull($subject->task);
    }

    #[Test]
    public function aProfileNeedsAQuestionAVersionAndUniqueKeys(): void
    {
        $question = new YesNoQuestion('ok', 'Is it ok?');

        foreach ([
            1795211010 => static fn(): DecisionProfile => new DecisionProfile(' ', 1, [$question]),
            1795211011 => static fn(): DecisionProfile => new DecisionProfile('x.y', 0, [$question]),
            1795211012 => static fn(): DecisionProfile => new DecisionProfile('x.y', 1, []),
            1795211013 => static fn(): DecisionProfile => new DecisionProfile('x.y', 1, [$question, new YesNoQuestion('ok', 'Again?')]),
        ] as $code => $build) {
            try {
                $build();
                self::fail(sprintf('expected code %d', $code));
            } catch (InvalidArgumentException $e) {
                self::assertSame($code, $e->getCode());
            }
        }
    }

    #[Test]
    public function aProfileHoldsEditorContentUnlessItSaysOtherwise(): void
    {
        $question = new YesNoQuestion('ok', 'Is it ok?');

        self::assertSame(ToolDataClass::EDITOR_CONTENT, (new DecisionProfile('x.y', 1, [$question]))->dataClass);
        self::assertSame(ToolDataClass::SOURCE_CODE, (new DecisionProfile('x.y', 1, [$question], dataClass: ToolDataClass::SOURCE_CODE))->dataClass);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function outsideTheUnitInterval(): iterable
    {
        yield 'below 0' => [-0.01];
        yield 'above 1' => [1.01];
        yield 'NaN' => [NAN];
    }

    #[Test]
    #[DataProvider('outsideTheUnitInterval')]
    public function aProbabilityOfYesOutsideZeroToOneIsRefused(float $value): void
    {
        $this->expectExceptionCode(1795211030);

        DecisionAnswer::yesNo('ok', $value);
    }

    #[Test]
    #[DataProvider('outsideTheUnitInterval')]
    public function aConfidenceOrProbabilityOutsideZeroToOneIsRefused(float $value): void
    {
        $this->expectExceptionCode(1795211030);

        DecisionAnswer::choice('pick', 'a', ['a' => $value]);
    }

    #[Test]
    public function aNegativeScoreIsRefused(): void
    {
        $this->expectExceptionCode(1795211031);

        DecisionAnswer::score('rate', -0.5);
    }

    #[Test]
    public function anAnswerCarriesOnlyWhatWasReported(): void
    {
        $yesNo = DecisionAnswer::yesNo('ok', 0.95);
        $choice = DecisionAnswer::choice('pick', 'billing', ['billing' => 0.88, 'technical' => 0.12], 0.81);

        self::assertSame([QuestionType::YesNo, 0.95, null, [], null], [$yesNo->type, $yesNo->value, $yesNo->choice, $yesNo->probabilities, $yesNo->confidence]);
        self::assertSame([QuestionType::Choice, null, 'billing', 0.81], [$choice->type, $choice->value, $choice->choice, $choice->confidence]);
    }

    #[Test]
    public function askingAResultForAQuestionTheProfileDoesNotHaveThrows(): void
    {
        $result = new DecisionResult('x.y', 1, 'judge', 'typesafe', 'm', ProbabilityKind::Distribution, ['ok' => DecisionAnswer::yesNo('ok', 1.0)]);

        self::assertSame(1.0, $result->answer('ok')->value);
        self::assertNull($result->inputTokens, 'unmeasured tokens stay null, never 0');

        $this->expectExceptionCode(DecisionException::NO_SUCH_ANSWER);
        $result->answer('other');
    }

    /**
     * @return iterable<string, array{DecisionException, int, string}>
     */
    public static function failures(): iterable
    {
        $cause = new RuntimeException('cause text');

        yield 'unknown profile' => [DecisionException::unknownProfile('x.y'), DecisionException::UNKNOWN_PROFILE, '"x.y"'];
        yield 'missing field' => [DecisionException::missingSubjectField('x.y', SubjectField::Evidence), DecisionException::MISSING_SUBJECT_FIELD, '"evidence"'];
        yield 'invalid profile' => [DecisionException::invalidProfile('Vendor\\Profiles', 'why', $cause), DecisionException::INVALID_PROFILE, 'Vendor\\Profiles'];
        yield 'no configuration' => [DecisionException::noConfiguration(), DecisionException::NO_CONFIGURATION, 'decision.configuration'];
        yield 'unknown configuration' => [DecisionException::unknownConfiguration('judge'), DecisionException::UNKNOWN_CONFIGURATION, '"judge"'];
        yield 'data class not permitted' => [DecisionException::dataClassNotPermitted('x.y', ToolDataClass::SOURCE_CODE, 'judge', TrustZone::EXTERNAL_GLOBAL), DecisionException::DATA_CLASS_NOT_PERMITTED, 'externalGlobal'];
        yield 'model cannot decide' => [DecisionException::modelCannotDecide('judge', 'no chat'), DecisionException::MODEL_CANNOT_DECIDE, 'no chat'];
        yield 'rejected' => [DecisionException::rejected('judge', $cause), DecisionException::REJECTED, 'cause text'];
        yield 'failed' => [DecisionException::failed('judge', $cause), DecisionException::FAILED, 'cause text'];
        yield 'invalid answer' => [DecisionException::invalidAnswer('b', 'k', 'why'), DecisionException::INVALID_ANSWER, 'why'];
        yield 'no such answer' => [DecisionException::noSuchAnswer('x.y', 'k'), DecisionException::NO_SUCH_ANSWER, '"k"'];
    }

    #[Test]
    #[DataProvider('failures')]
    public function everyFailureNamesItsCauseAndCarriesItsCode(DecisionException $exception, int $code, string $named): void
    {
        self::assertSame($code, $exception->getCode());
        self::assertStringContainsString($named, $exception->getMessage());
    }

    #[Test]
    public function aRequestCarriesItsProfileSubjectAndAttribution(): void
    {
        $subject = new DecisionSubject(candidate: 'c');
        $request = new DecisionRequest('x.y', $subject, 'judge', 7, 'my_ext', 'op');

        self::assertSame(['x.y', $subject, 'judge', 7, 'my_ext', 'op'], [$request->profile, $request->subject, $request->configuration, $request->beUserUid, $request->callerSourceExtension, $request->callerSourceOperation]);

        $attributed = (new DecisionRequest('x.y', $subject, 'judge', null, 'my_ext', 'op'))->withBeUserUid(9);
        self::assertSame(['x.y', $subject, 'judge', 9, 'my_ext', 'op'], [$attributed->profile, $attributed->subject, $attributed->configuration, $attributed->beUserUid, $attributed->callerSourceExtension, $attributed->callerSourceOperation]);
    }
}
