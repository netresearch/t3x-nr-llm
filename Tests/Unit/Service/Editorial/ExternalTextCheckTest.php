<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Editorial;

use Netresearch\NrLlm\Domain\Enum\RuleJudgeMode;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Service\Editorial\ExternalTextCheck;
use Netresearch\NrLlm\Service\Editorial\ExternalTextCheckScope;
use Netresearch\NrLlm\Service\Editorial\ExternalTextFinding;
use Netresearch\NrLlm\Service\Editorial\ExternalTextReport;
use Netresearch\NrLlm\Service\Editorial\ExternalTextStatus;
use Netresearch\NrLlm\Service\Editorial\RuleStateDigest;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\Guardrail\Judge\JudgeEgressGate;
use Netresearch\NrLlm\Service\Guardrail\Judge\JudgeReentry;
use Netresearch\NrLlm\Service\Guardrail\Judge\JudgeUnavailableException;
use Netresearch\NrLlm\Service\Guardrail\Judge\JudgeViolation;
use Netresearch\NrLlm\Service\Guardrail\Judge\RuleJudge;
use Netresearch\NrLlm\Service\Guardrail\Judge\RuleSet;
use Netresearch\NrLlm\Service\Guardrail\Judge\RuleSetResolver;
use Netresearch\NrLlm\Service\Guardrail\Term\TermCheckFailedException;
use Netresearch\NrLlm\Service\Guardrail\Term\TermListResolverInterface;
use Netresearch\NrLlm\Service\Guardrail\Term\TermListRule;
use Netresearch\NrLlm\Service\Guardrail\Term\TermMatcher;
use Netresearch\NrLlm\Service\Prompt\ConfigurationSnippetResolver;
use Netresearch\NrLlm\Service\Prompt\PromptSnippetComposer;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Fixture\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;

use function array_map;

/**
 * The check of a delivered text (ADR-300, spec 057 X1-X9).
 */
#[CoversClass(ExternalTextCheck::class)]
#[CoversClass(ExternalTextReport::class)]
#[CoversClass(ExternalTextFinding::class)]
#[CoversClass(JudgeEgressGate::class)]
final class ExternalTextCheckTest extends AbstractUnitTestCase
{
    /** Twelve words: under every word limit of the pipeline. */
    private const SHORT = 'Danke für den Hinweis, wir prüfen den Rabatt gern noch heute.';

    private const SHORT_CLEAN = 'Danke für den Hinweis, wir prüfen das gern noch heute für Sie.';

    private int $judgeCalls = 0;

    /** @var list<TermListRule> */
    private array $lists = [];

    /** @var list<JudgeViolation>|Throwable */
    private array|Throwable $verdict = [];

    private string $ruleText = 'Sprich die Leser mit „Sie“ an.';

    #[Test]
    public function aShortTextWithAForbiddenTermIsAViolationNamingTermAndList(): void
    {
        $this->lists = [new TermListRule('Marke', '', '', ['Rabatt'], [], '')];

        $report = $this->check()->check(self::SHORT, $this->configuration(RuleJudgeMode::OFF));

        self::assertSame(ExternalTextStatus::VIOLATION, $report->status);
        self::assertTrue($report->blocks());
        self::assertCount(1, $report->findings);
        self::assertSame(ExternalTextFinding::KIND_FORBIDDEN_TERM, $report->findings[0]->kind);
        self::assertSame('Marke', $report->findings[0]->source);
        self::assertSame('Rabatt', $report->findings[0]->subject);
    }

    #[Test]
    public function aShortTextWithoutARequiredTermIsAViolation(): void
    {
        $this->lists = [new TermListRule('Pflicht', '', '', [], ['Kundenservice'], '')];

        $report = $this->check()->check(self::SHORT_CLEAN, $this->configuration(RuleJudgeMode::OFF));

        self::assertSame(ExternalTextStatus::VIOLATION, $report->status);
        self::assertSame(ExternalTextFinding::KIND_MISSING_TERM, $report->findings[0]->kind);
        self::assertSame('Kundenservice', $report->findings[0]->subject);
    }

    #[Test]
    public function aRequiredTermInsideAJsonStringCounts(): void
    {
        $this->lists = [new TermListRule('Pflicht', '', '', [], ['Kundenservice'], '')];

        $report = $this->check()->check('{"post":"Unser Kundenservice\nhilft gern."}', $this->configuration(RuleJudgeMode::OFF));

        self::assertSame(ExternalTextStatus::PASSED, $report->status);
    }

    #[Test]
    public function aForbiddenTermInsideAJsonStringIsFound(): void
    {
        $this->lists = [new TermListRule('Marke', '', '', ['hilft'], [], '')];

        $report = $this->check()->check('{"post":"Unser Team\nhilft gern."}', $this->configuration(RuleJudgeMode::OFF));

        self::assertSame(ExternalTextStatus::VIOLATION, $report->status);
    }

    #[Test]
    public function aShortTextIsJudged(): void
    {
        $this->verdict = [$this->violation('Danke für den Hinweis')];

        $report = $this->check()->check(self::SHORT, $this->configuration(RuleJudgeMode::REPORT));

        self::assertSame(1, $this->judgeCalls);
        self::assertSame(ExternalTextStatus::VIOLATION, $report->status);
        self::assertSame(ExternalTextFinding::KIND_RULE, $report->findings[0]->kind);
        self::assertSame($this->ruleText, $report->findings[0]->subject);
        self::assertSame('Danke für den Hinweis', $report->findings[0]->passage);
        self::assertSame('Ton', $report->findings[0]->source);
        self::assertSame(1, $report->findings[0]->ruleNumber);
    }

    #[Test]
    public function aRuleBrokenUnderAReportJudgeDoesNotBlock(): void
    {
        $this->verdict = [$this->violation('Danke für den Hinweis')];

        self::assertFalse($this->check()->check(self::SHORT, $this->configuration(RuleJudgeMode::REPORT))->blocks());
        self::assertTrue($this->check()->check(self::SHORT, $this->configuration(RuleJudgeMode::BLOCK))->blocks());
    }

    #[Test]
    public function aJudgeThatFailsInReportModeIsNotCheckedNeverPassed(): void
    {
        $this->verdict = new JudgeUnavailableException('timeout');

        $report = $this->check()->check(self::SHORT_CLEAN, $this->configuration(RuleJudgeMode::REPORT));

        self::assertSame(ExternalTextStatus::NOT_CHECKED, $report->status);
        self::assertTrue($report->hasReason(ExternalTextReport::REASON_JUDGE_UNAVAILABLE));
        self::assertFalse($report->blocks());
    }

    #[Test]
    public function aJudgeThatFailsInBlockModeFailsAndBlocks(): void
    {
        $this->verdict = new JudgeUnavailableException('timeout');

        $report = $this->check()->check(self::SHORT_CLEAN, $this->configuration(RuleJudgeMode::BLOCK));

        self::assertSame(ExternalTextStatus::FAILED, $report->status);
        self::assertTrue($report->blocks());
    }

    #[Test]
    public function aRuleTagWithoutSnippetIsNotCheckedWithTheReasonRuleSetEmpty(): void
    {
        $this->ruleText = '';

        $report = $this->check()->check(self::SHORT_CLEAN, $this->configuration(RuleJudgeMode::REPORT));

        self::assertSame(ExternalTextStatus::NOT_CHECKED, $report->status);
        self::assertSame([ExternalTextReport::REASON_RULE_SET_EMPTY], $report->reasons);
        self::assertSame(0, $this->judgeCalls);
    }

    #[Test]
    public function aJudgeInALessTrustedZoneIsNotAskedAndTheTextIsNotChecked(): void
    {
        $report = $this->check(judgingZone: TrustZone::EXTERNAL_GLOBAL)->check(
            self::SHORT_CLEAN,
            $this->configuration(RuleJudgeMode::REPORT, TrustZone::LOCAL),
        );

        self::assertSame(0, $this->judgeCalls);
        self::assertSame(ExternalTextStatus::NOT_CHECKED, $report->status);
        self::assertSame([ExternalTextReport::REASON_JUDGE_EGRESS], $report->reasons);
    }

    #[Test]
    public function anUnknownWriterCountsAsTheMostTrustedZone(): void
    {
        $configuration = $this->configuration(RuleJudgeMode::REPORT);
        $configuration->setLlmModel(null);

        $report = $this->check(judgingZone: TrustZone::EXTERNAL_GLOBAL)->check(self::SHORT_CLEAN, $configuration);

        self::assertSame(0, $this->judgeCalls);
        self::assertSame([ExternalTextReport::REASON_JUDGE_EGRESS], $report->reasons);
    }

    #[Test]
    public function aMissingJudgingConfigurationIsNotChecked(): void
    {
        $configuration = $this->configuration(RuleJudgeMode::REPORT);
        $configuration->setRuleJudgeConfiguration(0);

        $report = $this->check()->check(self::SHORT_CLEAN, $configuration);

        self::assertSame(ExternalTextStatus::NOT_CHECKED, $report->status);
        self::assertSame([ExternalTextReport::REASON_JUDGE_NOT_CONFIGURED], $report->reasons);
    }

    #[Test]
    public function aTextNoRuleAppliesToIsNotCheckedNeverPassed(): void
    {
        $report = $this->check()->check(self::SHORT_CLEAN, $this->configuration(RuleJudgeMode::OFF));

        self::assertSame(ExternalTextStatus::NOT_CHECKED, $report->status);
        self::assertSame([ExternalTextReport::REASON_NO_RULES], $report->reasons);
    }

    #[Test]
    public function aListBoundToAnotherSiteDoesNotApply(): void
    {
        $this->lists = [new TermListRule('Andere', 'other', '', [], ['Kundenservice'], '')];

        $report = $this->check()->check(self::SHORT_CLEAN, $this->configuration(RuleJudgeMode::OFF), new ExternalTextCheckScope(siteIdentifier: 'main'));

        self::assertSame(ExternalTextStatus::NOT_CHECKED, $report->status);
        self::assertSame([ExternalTextReport::REASON_NO_RULES], $report->reasons);
    }

    #[Test]
    public function aCompliantTextPassesWithBothChecks(): void
    {
        $this->lists = [new TermListRule('Marke', '', '', ['Rabatt'], [], '')];

        $report = $this->check()->check(self::SHORT_CLEAN, $this->configuration(RuleJudgeMode::BLOCK));

        self::assertSame(ExternalTextStatus::PASSED, $report->status);
        self::assertSame([], $report->reasons);
        self::assertFalse($report->blocks());
        self::assertSame(1, $this->judgeCalls);
        self::assertSame([['configuration' => 'editorial', 'judging' => 'judge', 'mode' => 'block']], $report->judges);
    }

    #[Test]
    public function aTermCheckThatCannotRunFails(): void
    {
        $termLists = self::createStub(TermListResolverInterface::class);
        $termLists->method('forConfiguration')->willThrowException(new TermCheckFailedException('db', 1));

        $report = $this->check(termLists: $termLists)->check(self::SHORT_CLEAN, $this->configuration(RuleJudgeMode::OFF));

        self::assertSame(ExternalTextStatus::FAILED, $report->status);
        self::assertTrue($report->blocks());
        self::assertSame([ExternalTextReport::REASON_TERM_CHECK_FAILED], $report->reasons);
        self::assertSame('', $report->ruleStateDigest);
    }

    /**
     * A finding that does not block (report-mode rule) must not hide a term
     * check that never ran: the failure blocks on its own.
     */
    #[Test]
    public function aFailedTermCheckBlocksBesideANonBlockingFinding(): void
    {
        $termLists = self::createStub(TermListResolverInterface::class);
        $termLists->method('forConfiguration')->willThrowException(new TermCheckFailedException('db', 1));
        $this->verdict = [$this->violation('Danke für den Hinweis')];

        $report = $this->check(termLists: $termLists)->check(self::SHORT, $this->configuration(RuleJudgeMode::REPORT));

        self::assertSame(ExternalTextStatus::VIOLATION, $report->status);
        self::assertTrue($report->blocks());
        self::assertTrue($report->checkFailed);
        self::assertTrue($report->toArray()['blocks']);
        self::assertTrue($report->hasReason(ExternalTextReport::REASON_TERM_CHECK_FAILED));
    }

    /**
     * A judge in block mode that gave no answer blocks, also when a term list
     * found something that blocks anyway, and the report says the check failed.
     */
    #[Test]
    public function aFailedBlockModeJudgeIsRecordedBesideATermFinding(): void
    {
        $this->lists   = [new TermListRule('Marke', '', '', ['Rabatt'], [], '')];
        $this->verdict = new JudgeUnavailableException('timeout');

        $report = $this->check()->check(self::SHORT, $this->configuration(RuleJudgeMode::BLOCK));

        self::assertTrue($report->checkFailed);
        self::assertTrue($report->blocks());
        self::assertTrue($report->hasReason(ExternalTextReport::REASON_JUDGE_UNAVAILABLE));
    }

    #[Test]
    public function anEmptyTextIsNotJudged(): void
    {
        $report = $this->check()->check("  \n", $this->configuration(RuleJudgeMode::REPORT));

        self::assertSame(0, $this->judgeCalls);
        self::assertSame(ExternalTextStatus::NOT_CHECKED, $report->status);
        self::assertSame([ExternalTextReport::REASON_EMPTY_TEXT], $report->reasons);
    }

    #[Test]
    public function aStoreErrorWhileReadingTheRulesFailsTheCheckInsteadOfThrowing(): void
    {
        $termLists = self::createStub(TermListResolverInterface::class);
        $termLists->method('forConfiguration')->willReturn([]);
        $this->ruleText = 'x';

        $check  = $this->check(termLists: $termLists, ruleSetFailure: new RuntimeException('db gone'));
        $report = $check->check(self::SHORT, $this->configuration(RuleJudgeMode::REPORT));

        self::assertSame(ExternalTextStatus::FAILED, $report->status);
        self::assertTrue($report->blocks());
        self::assertTrue($report->hasReason(ExternalTextReport::REASON_CHECK_FAILED));
    }

    /**
     * The pipeline searches the lists of every named configuration by uid,
     * without looking the configuration up; a hidden one is not skipped.
     */
    #[Test]
    public function theListsOfAFurtherConfigurationThatCannotBeLoadedStillApply(): void
    {
        $termLists = self::createStub(TermListResolverInterface::class);
        $termLists->method('forConfiguration')->willReturnCallback(static fn (int $uid): array => $uid === 9 ? [new TermListRule('Werbung', '', '', ['Rabatt'], [], '')] : []);

        $report = $this->check(termLists: $termLists)->check(
            self::SHORT,
            $this->configuration(RuleJudgeMode::OFF),
            new ExternalTextCheckScope(furtherConfigurations: [9]),
        );

        self::assertSame(ExternalTextStatus::VIOLATION, $report->status);
        self::assertTrue($report->blocks());
    }

    /**
     * The judge reads the delivered bytes: prose in a JSON key is in front
     * of it.
     */
    #[Test]
    public function theJudgeReadsTheDeliveredTextNotOnlyTheStringsOfAJsonText(): void
    {
        $seen  = [];
        $judge = self::createStub(RuleJudge::class);
        $judge->method('judge')->willReturnCallback(static function (string $text) use (&$seen): array {
            $seen[] = $text;

            return [];
        });
        $text = '{"Heilt garantiert jede Krankheit":"Mehr dazu morgen"}';

        $this->check(judge: $judge)->check($text, $this->configuration(RuleJudgeMode::BLOCK));

        self::assertSame([$text], $seen);
    }

    /**
     * The judge quotes a passage as it reads it, decoded; a passage of an
     * escaped JSON string still counts as found in the text.
     */
    #[Test]
    public function aPassageTheJudgeQuotesFromAnEscapedJsonStringIsFound(): void
    {
        $service = self::createStub(CompletionServiceInterface::class);
        $service->method('completeForConfiguration')->willReturn(new CompletionResponse(
            '{"violations":[{"rule":1,"passage":"Wir sind die \\"Nummer 1\\" in Europa","reason":"r"}]}',
            'small',
            UsageStatistics::fromTokens(1, 1),
        ));
        $judge = new RuleJudge(static fn (): CompletionServiceInterface => $service, new TermMatcher(), new RecordingLogger());

        $report = $this->check(judge: $judge)->check('{"post":"Wir sind die \\"Nummer 1\\" in Europa"}', $this->configuration(RuleJudgeMode::BLOCK));

        self::assertSame(ExternalTextStatus::VIOLATION, $report->status);
        self::assertSame('Wir sind die "Nummer 1" in Europa', $report->findings[0]->passage);
    }

    /**
     * A writer named but not found is not the configuration's provider: the
     * text counts as written in the most trusted zone (ADR-288 R10).
     */
    #[Test]
    public function aNamedWriterThatDoesNotExistCountsAsTheMostTrustedZone(): void
    {
        $report = $this->check()->check(self::SHORT_CLEAN, $this->configuration(RuleJudgeMode::REPORT), new ExternalTextCheckScope(writerProvider: 'deleted-local'));

        self::assertSame(0, $this->judgeCalls);
        self::assertSame([ExternalTextReport::REASON_JUDGE_EGRESS], $report->reasons);
    }

    /**
     * A decoder keeps the last of two members with one key, and a JSON text
     * may be a string alone; every string literal of the text is searched.
     *
     * @return iterable<string, array{string}>
     */
    public static function jsonHidingATerm(): iterable
    {
        yield 'string root' => ['"\\u0052abatt"'];
        yield 'escaped key' => ['{"\\u0052abatt":"clean"}'];
        yield 'escaped value in a fence' => ["```json\n{\"post\":\"\\u0052abatt\"}\n```"];
        yield 'surrogate pair before the term' => ['{"post":"\\ud83d\\ude00 \\u0052abatt"}'];
    }

    #[Test]
    #[DataProvider('jsonHidingATerm')]
    public function aForbiddenTermInAnyJsonStringLiteralIsFound(string $text): void
    {
        $this->lists = [new TermListRule('Marke', '', '', ['Rabatt'], [], '')];

        self::assertSame(ExternalTextStatus::VIOLATION, $this->check()->check($text, $this->configuration(RuleJudgeMode::OFF))->status);
    }

    /**
     * A pattern engine that gives up must not read as "no term": with a
     * tiny backtrack limit and no JIT, a long JSON text still never passes.
     */
    #[Test]
    public function aJsonTextThePatternEngineGivesUpOnDoesNotPass(): void
    {
        $this->lists = [new TermListRule('Marke', '', '', ['Rabatt'], [], '')];
        $text        = '{"post":"' . str_repeat('a\\"b ', 5000) . '\\u0052abatt"}';
        $jit         = ini_get('pcre.jit');
        $limit       = ini_get('pcre.backtrack_limit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '10');

        try {
            $report = $this->check()->check($text, $this->configuration(RuleJudgeMode::OFF));
        } finally {
            ini_set('pcre.jit', (string) $jit);
            ini_set('pcre.backtrack_limit', (string) $limit);
        }

        self::assertNotSame(ExternalTextStatus::PASSED, $report->status);
        self::assertTrue($report->blocks());
    }

    /**
     * A digit written as an escape is the digit: `\u0030` is "0", which PHP
     * reads as false, never U+FFFD.
     */
    #[Test]
    public function aTermWithAZeroWrittenAsEscapesIsFound(): void
    {
        $this->lists = [new TermListRule('Marke', '', '', ['A0'], [], '')];

        self::assertSame(ExternalTextStatus::VIOLATION, $this->check()->check('{"post":"Modell \\u0041\\u0030 jetzt"}', $this->configuration(RuleJudgeMode::OFF))->status);
    }

    /**
     * A term written with a surrogate pair is the character the pair stands
     * for.
     */
    #[Test]
    public function aTermWrittenWithASurrogatePairIsFound(): void
    {
        $this->lists = [new TermListRule('Marke', '', '', ["\u{1F600}Rabatt"], [], '')];

        self::assertSame(ExternalTextStatus::VIOLATION, $this->check()->check('{"post":"Neu: \\ud83d\\ude00Rabatt"}', $this->configuration(RuleJudgeMode::OFF))->status);
    }

    /**
     * A decoder keeps one of two members with one key, and which one differs
     * between consumers: such a text cannot be vouched for, and blocks.
     *
     * @return iterable<string, array{string}>
     */
    public static function jsonWithAmbiguousKeys(): iterable
    {
        yield 'duplicate member' => ['{"post":"\\u0052abatt","post":"clean"}'];
        yield 'nested duplicate member' => ['{"a":{"post":"clean","post":"clean"}}'];
        yield 'key a PHP object refuses' => ['{"\\u0000":"x","post":"clean"}'];
        yield 'lone surrogate other decoders accept' => ['{"t":"\\u0052abatt","z":"\\ud800"}'];
        yield 'nesting deeper than PHP decodes' => [str_repeat('[', 600) . '"\\u0052abatt"' . str_repeat(']', 600)];
    }

    #[Test]
    #[DataProvider('jsonWithAmbiguousKeys')]
    public function aJsonTextWithAmbiguousKeysFailsTheCheck(string $text): void
    {
        $this->lists = [new TermListRule('Marke', '', '', ['Rabatt'], [], '')];

        $report = $this->check()->check($text, $this->configuration(RuleJudgeMode::OFF));

        self::assertTrue($report->blocks());
        self::assertTrue($report->hasReason(ExternalTextReport::REASON_JSON_AMBIGUOUS));
    }

    /**
     * A text that is no JSON is plain text: a deeply nested or broken
     * document is searched as it is.
     */
    #[Test]
    public function aTextThatIsNoJsonIsSearchedAsPlainText(): void
    {
        $this->lists = [new TermListRule('Marke', '', '', ['Rabatt'], [], '')];

        self::assertSame(ExternalTextStatus::VIOLATION, $this->check()->check('Der 24" Monitor mit Rabatt', $this->configuration(RuleJudgeMode::OFF))->status);
    }

    /**
     * A string value that starts with a colon is no key.
     */
    #[Test]
    public function aValueStartingWithAColonIsNoDuplicateKey(): void
    {
        $this->lists = [new TermListRule('Marke', '', '', ['Rabatt'], [], '')];

        $report = $this->check()->check('{"tags":[":smile:", ":wink:"],"x":[" :b"]}', $this->configuration(RuleJudgeMode::OFF));

        self::assertSame(ExternalTextStatus::PASSED, $report->status);
    }

    /**
     * A key that decodes to an integer is searched as the digits it is.
     */
    #[Test]
    public function aKeyThatDecodesToAnIntegerIsSearched(): void
    {
        $this->lists = [new TermListRule('Marke', '', '', ['110'], [], '')];

        self::assertSame(ExternalTextStatus::VIOLATION, $this->check()->check('{"\\u0031\\u0031\\u0030":1}', $this->configuration(RuleJudgeMode::OFF))->status);
    }

    /**
     * An escaped backslash before a `u` is a backslash, not an escape: the
     * text says `\\u0052abatt` literally and holds no forbidden word.
     */
    #[Test]
    public function anEscapedBackslashIsNotDecodedAsAnEscape(): void
    {
        $this->lists = [new TermListRule('Marke', '', '', ['Rabatt'], [], '')];

        self::assertNotSame(ExternalTextStatus::VIOLATION, $this->check()->check('{"code":"\\\\u0052abatt"}', $this->configuration(RuleJudgeMode::OFF))->status);
    }

    #[Test]
    public function aWriterGivenAsWhiteSpaceIsTheConfigurationsProvider(): void
    {
        $report = $this->check()->check(self::SHORT_CLEAN, $this->configuration(RuleJudgeMode::REPORT), new ExternalTextCheckScope(writerProvider: ' '));

        self::assertSame(ExternalTextStatus::PASSED, $report->status);
    }

    #[Test]
    public function theReportIsBoundToTheExactText(): void
    {
        $this->lists = [new TermListRule('Marke', '', '', ['Rabatt'], [], '')];

        $report = $this->check()->check(self::SHORT_CLEAN, $this->configuration(RuleJudgeMode::OFF));

        self::assertSame('sha256:' . hash('sha256', self::SHORT_CLEAN), $report->textDigest);
        self::assertNotSame($report->textDigest, ExternalTextReport::digestOf(self::SHORT_CLEAN . ' '));
    }

    #[Test]
    public function aChangedRuleChangesTheRuleStateDigestOfTheSameText(): void
    {
        $before = $this->check()->check(self::SHORT_CLEAN, $this->configuration(RuleJudgeMode::REPORT))->ruleStateDigest;

        $this->ruleText = 'Sprich die Leser mit „du“ an.';
        $after          = $this->check()->check(self::SHORT_CLEAN, $this->configuration(RuleJudgeMode::REPORT))->ruleStateDigest;

        $this->lists = [new TermListRule('Marke', '', '', ['Rabatt'], [], '')];
        $withList    = $this->check()->check(self::SHORT_CLEAN, $this->configuration(RuleJudgeMode::REPORT))->ruleStateDigest;

        self::assertStringStartsWith('sha256:', $before);
        self::assertNotSame($before, $after);
        self::assertNotSame($after, $withList);
        self::assertSame($withList, $this->check()->check(self::SHORT, $this->configuration(RuleJudgeMode::REPORT))->ruleStateDigest);
    }

    #[Test]
    public function aFurtherConfigurationAddsItsListsAndItsStricterJudge(): void
    {
        $further       = $this->configuration(RuleJudgeMode::BLOCK, uid: 9, identifier: 'channel');
        $this->verdict = [$this->violation('Danke für den Hinweis')];

        $report = $this->check(further: $further)->check(
            self::SHORT,
            $this->configuration(RuleJudgeMode::REPORT),
            new ExternalTextCheckScope(furtherConfigurations: [9, 9, 3]),
        );

        self::assertSame(2, $this->judgeCalls);
        self::assertTrue($report->blocks());
        self::assertSame(['editorial', 'channel'], array_map(static fn (array $judge): string => $judge['configuration'], $report->judges));
    }

    private function check(
        TrustZone $judgingZone = TrustZone::EXTERNAL_GLOBAL,
        ?TermListResolverInterface $termLists = null,
        ?LlmConfiguration $further = null,
        ?Throwable $ruleSetFailure = null,
        ?RuleJudge $judge = null,
    ): ExternalTextCheck {
        if (!$termLists instanceof TermListResolverInterface) {
            $termLists = self::createStub(TermListResolverInterface::class);
            $termLists->method('forConfiguration')->willReturnCallback(fn (int $uid): array => $uid === 3 ? $this->lists : []);
        }

        $ruleSets = self::createStub(RuleSetResolver::class);
        $ruleSets->method('forTags')->willReturnCallback(fn (): RuleSet => $ruleSetFailure instanceof Throwable ? throw $ruleSetFailure : RuleSet::fromSnippets(
            $this->ruleText === '' ? [] : [['name' => 'Ton', 'text' => $this->ruleText, 'dataClass' => null]],
        ));

        if (!$judge instanceof RuleJudge) {
            $judge = self::createStub(RuleJudge::class);
            $judge->method('judge')->willReturnCallback(function (): array {
                ++$this->judgeCalls;
                if ($this->verdict instanceof Throwable) {
                    throw $this->verdict;
                }

                return $this->verdict;
            });
        }

        $judging        = $this->configuration(RuleJudgeMode::OFF, $judgingZone, 7, 'judge');
        $configurations = self::createStub(LlmConfigurationRepository::class);
        $configurations->method('findByUid')->willReturnCallback(static fn (int $uid): ?LlmConfiguration => match ($uid) {
            7       => $judging,
            9       => $further,
            default => null,
        });

        $snippetRepository = self::createStub(PromptSnippetRepository::class);
        $snippetRepository->method('findActiveByTag')->willReturn([]);

        return new ExternalTextCheck(
            $termLists,
            new TermMatcher(),
            $ruleSets,
            $judge,
            new JudgeEgressGate(new TrustZoneResolver(), self::createStub(ProviderRepository::class), $configurations),
            new JudgeReentry(),
            $configurations,
            new RuleStateDigest($configurations, new ConfigurationSnippetResolver($snippetRepository, new PromptSnippetComposer()), $ruleSets, $termLists),
            new RecordingLogger(),
        );
    }

    private function configuration(
        RuleJudgeMode $mode,
        TrustZone $zone = TrustZone::EXTERNAL_GLOBAL,
        int $uid = 3,
        string $identifier = 'editorial',
    ): LlmConfiguration {
        $provider = new Provider();
        $provider->setTrustZoneEnum($zone);

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->_setProperty('uid', $uid);
        $configuration->setIdentifier($identifier);
        $configuration->setLlmModel($model);
        $configuration->setIsActive(true);
        $configuration->setRuleJudgeMode($mode->value);
        $configuration->setRuleJudgeTags('tone');
        $configuration->setRuleJudgeConfiguration(7);

        return $configuration;
    }

    private function violation(string $passage): JudgeViolation
    {
        return new JudgeViolation(RuleSet::fromSnippets([['name' => 'Ton', 'text' => $this->ruleText, 'dataClass' => null]])->rules[0], $passage);
    }
}
