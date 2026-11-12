<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Guardrail\Judge;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Service\Guardrail\Judge\JudgeAnswerParser;
use Netresearch\NrLlm\Service\Guardrail\Judge\JudgeUnavailableException;
use Netresearch\NrLlm\Service\Guardrail\Judge\JudgeViolation;
use Netresearch\NrLlm\Service\Guardrail\Judge\Rule;
use Netresearch\NrLlm\Service\Guardrail\Judge\RuleSet;
use Netresearch\NrLlm\Service\Schema\JsonSchemaValidator;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

use function array_map;
use function json_encode;
use function str_repeat;

/**
 * The judge's answer is data from a model: a hint quotes only a rule that
 * exists and a passage that is in the text (ADR-288).
 */
#[CoversClass(JudgeAnswerParser::class)]
#[CoversClass(RuleSet::class)]
final class JudgeAnswerParserTest extends AbstractUnitTestCase
{
    private const TEXT = 'Hallo du, hier ist dein Angebot. Wir freuen uns auf Ihren Besuch.';

    #[Test]
    public function aViolationWithAnExistingRuleAndAPassageFromTheTextCounts(): void
    {
        $violations = $this->parse('{"violations":[{"rule":1,"passage":"Hallo du","reason":"r"}]}');

        self::assertCount(1, $violations);
        self::assertSame('Sprich die Leser mit „Sie“ an.', $violations[0]->rule->text);
        self::assertSame('Ton', $violations[0]->rule->snippetName);
        self::assertSame('Hallo du', $violations[0]->passage);
    }

    #[Test]
    public function anEmptyListIsACompliantText(): void
    {
        self::assertSame([], $this->parse('{"violations":[]}'));
    }

    #[Test]
    public function oneFenceAroundTheJsonIsAccepted(): void
    {
        self::assertCount(1, $this->parse("```json\n{\"violations\":[{\"rule\":2,\"passage\":\"dein Angebot\",\"reason\":\"r\"}]}\n```"));
    }

    #[Test]
    public function whiteSpaceAndCaseDoNotHideAPassage(): void
    {
        self::assertCount(1, $this->parse('{"violations":[{"rule":1,"passage":"  hallo   DU ","reason":"r"}]}'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableAnswers(): iterable
    {
        yield 'prose' => ['The text uses the informal du.'];
        yield 'empty' => [''];
        yield 'a list instead of an object' => ['[{"rule":1,"passage":"Hallo du","reason":"r"}]'];
        yield 'no violations key' => ['{"result":"ok"}'];
        yield 'a rule number outside the set' => ['{"violations":[{"rule":3,"passage":"Hallo du","reason":"r"}]}'];
        yield 'a rule number as a string' => ['{"violations":[{"rule":"1","passage":"Hallo du","reason":"r"}]}'];
        yield 'an extra property' => ['{"violations":[{"rule":1,"passage":"Hallo du","why":"du"}]}'];
        yield 'a missing passage' => ['{"violations":[{"rule":1}]}'];
        yield 'a missing reason' => ['{"violations":[{"rule":1,"passage":"Hallo du"}]}'];
        yield 'a passage the text does not hold' => ['{"violations":[{"rule":1,"passage":"Hey du da","reason":"r"}]}'];
        yield 'an empty passage' => ['{"violations":[{"rule":1,"passage":"   ","reason":"r"}]}'];
    }

    #[Test]
    #[DataProvider('unusableAnswers')]
    public function anAnswerThatCannotBeCheckedIsUnusable(string $answer): void
    {
        $this->expectException(JudgeUnavailableException::class);

        $this->parse($answer);
    }

    #[Test]
    public function aViolationThatCannotBeFoundIsDroppedBesideOneThatCan(): void
    {
        $violations = $this->parse('{"violations":[{"rule":1,"passage":"Hey du da","reason":"r"},{"rule":2,"passage":"dein Angebot","reason":"r"}]}');

        self::assertSame([2], array_map(static fn (JudgeViolation $v): int => $v->rule->number, $violations));
    }

    #[Test]
    public function theSameViolationIsReportedOnce(): void
    {
        self::assertCount(1, $this->parse('{"violations":[{"rule":1,"passage":"Hallo du","reason":"r"},{"rule":1,"passage":"hallo du","reason":"r"}]}'));
    }

    #[Test]
    public function atMostTenViolationsAreReported(): void
    {
        $text  = str_repeat('du ', 30);
        $rules = RuleSet::fromSnippets([['name' => 'Ton', 'text' => str_repeat("Siezen.\n", 12), 'dataClass' => null]]);
        $claim = [];
        for ($rule = 1; $rule <= 12; ++$rule) {
            $claim[] = ['passage' => 'du', 'reason' => 'informal', 'rule' => $rule];
        }

        $violations = (new JudgeAnswerParser())->parse((string) json_encode(['violations' => $claim]), $rules, [$text]);

        self::assertCount(JudgeAnswerParser::MAX_VIOLATIONS, $violations);
    }

    #[Test]
    public function theSchemaIsInsideTheStrictSubset(): void
    {
        self::assertTrue((new JsonSchemaValidator())->supportsSchema((new JudgeAnswerParser())->schema($this->rules())));
    }

    #[Test]
    public function aRuleSetNumbersTheNonEmptyLinesOfItsSnippetsInOrder(): void
    {
        $rules = RuleSet::fromSnippets([
            ['name' => 'Ton', 'text' => "Erste Regel.\r\n\n   \nZweite Regel.  ", 'dataClass' => null],
            ['name' => 'Recht', 'text' => 'Dritte Regel.', 'dataClass' => ToolDataClass::PUBLIC_CONTENT],
        ]);

        self::assertSame(
            [[1, 'Erste Regel.', 'Ton'], [2, 'Zweite Regel.', 'Ton'], [3, 'Dritte Regel.', 'Recht']],
            array_map(static fn (Rule $r): array => [$r->number, $r->text, $r->snippetName], $rules->rules),
        );
        self::assertSame([ToolDataClass::PUBLIC_CONTENT], $rules->dataClasses);
        self::assertSame('Zweite Regel.', $rules->rule(2)?->text);
        self::assertNull($rules->rule(4));
        self::assertNull($rules->rule(0));
    }

    #[Test]
    public function aRuleSetStopsAtItsLimit(): void
    {
        $rules = RuleSet::fromSnippets([['name' => 'Viel', 'text' => str_repeat("Regel.\n", RuleSet::MAX_RULES + 5), 'dataClass' => null]]);

        self::assertCount(RuleSet::MAX_RULES, $rules->rules);
    }

    /**
     * @return list<JudgeViolation>
     */
    private function parse(string $answer): array
    {
        return (new JudgeAnswerParser())->parse($answer, $this->rules(), [self::TEXT]);
    }

    private function rules(): RuleSet
    {
        return RuleSet::fromSnippets([['name' => 'Ton', 'text' => "Sprich die Leser mit „Sie“ an.\nKeine Werbesprache wie „dein Angebot“.", 'dataClass' => null]]);
    }
}
