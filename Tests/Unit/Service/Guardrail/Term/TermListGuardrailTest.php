<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Guardrail\Term;

use Netresearch\NrLlm\Domain\Enum\GuardrailVerdict;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Provider\Middleware\BudgetMiddleware;
use Netresearch\NrLlm\Provider\Middleware\GuardrailMiddleware;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\Guardrail\Term\TermListGuardrail;
use Netresearch\NrLlm\Service\Guardrail\Term\TermListMessages;
use Netresearch\NrLlm\Service\Guardrail\Term\TermListResolverInterface;
use Netresearch\NrLlm\Service\Guardrail\Term\TermListRule;
use Netresearch\NrLlm\Service\Guardrail\Term\TermMatcher;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Fixture\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

#[CoversClass(TermListGuardrail::class)]
#[CoversClass(TermListRule::class)]
#[CoversClass(TermListMessages::class)]
final class TermListGuardrailTest extends AbstractUnitTestCase
{
    #[Test]
    public function aForbiddenTermBlocksAndTheReasonNamesTermListAndNoGeneratedText(): void
    {
        $guardrail = $this->guardrail([$this->rule(forbidden: ['Rabatt', 'Billigprodukt', 'Nie gesehen'], message: 'Bitte anders formulieren.')]);

        $result = $guardrail->checkOutputInContext($this->response('Ein geheimer Satz mit Rabatt und Billigprodukt.'), $this->context());

        self::assertSame(GuardrailVerdict::DENY, $result->verdict);
        self::assertSame('forbidden|Marke|Rabatt, Billigprodukt Bitte anders formulieren.', $result->reason);
        self::assertStringNotContainsString('geheimer', $result->reason);
    }

    #[Test]
    public function theReasonNamesAtMostTwentyTerms(): void
    {
        $terms     = array_map(static fn (int $n): string => 'wort' . $n, range(1, 25));
        $guardrail = $this->guardrail([$this->rule(forbidden: $terms)]);

        $result = $guardrail->checkOutputInContext($this->response(implode(' ', $terms)), $this->context());

        self::assertStringContainsString('wort20', $result->reason);
        self::assertStringNotContainsString('wort21', $result->reason);
    }

    #[Test]
    public function aMissingRequiredTermBlocks(): void
    {
        $guardrail = $this->guardrail([$this->rule(required: ['Impressum', 'Datenschutz'])]);

        $result = $guardrail->checkOutputInContext($this->response('Unser Impressum. ' . $this->longText()), $this->context());

        self::assertSame(GuardrailVerdict::DENY, $result->verdict);
        self::assertSame('required|Marke|Datenschutz', $result->reason);
    }

    #[Test]
    public function aTextThatKeepsTheListPasses(): void
    {
        $guardrail = $this->guardrail([$this->rule(forbidden: ['Rabatt'], required: ['Impressum'])]);

        $result = $guardrail->checkOutputInContext($this->response('Unser Impressum. ' . $this->longText()), $this->context());

        self::assertSame(GuardrailVerdict::ALLOW, $result->verdict);
    }

    #[Test]
    public function noListMeansNoVerdictAndNoHold(): void
    {
        $guardrail = $this->guardrail([]);

        self::assertSame(GuardrailVerdict::ALLOW, $guardrail->checkOutputInContext($this->response('Rabatt'), $this->context())->verdict);
        self::assertFalse($guardrail->holdsStream($this->context()));
    }

    #[Test]
    public function aCallWithoutConfigurationIsNotCheckedAndNotHeld(): void
    {
        $resolver = $this->createMock(TermListResolverInterface::class);
        $resolver->expects(self::never())->method('forConfiguration');
        $guardrail = new TermListGuardrail($resolver, new TermMatcher(), $this->messages(), new RecordingLogger());
        $context   = new ProviderCallContext(ProviderOperation::Chat, 'c');

        self::assertSame(GuardrailVerdict::ALLOW, $guardrail->checkOutputInContext($this->response('Rabatt'), $context)->verdict);
        self::assertFalse($guardrail->holdsStream($context));
        self::assertSame(GuardrailVerdict::ALLOW, $guardrail->checkOutput($this->response('Rabatt'))->verdict);
    }

    #[Test]
    public function aListIsAskedForTheConfigurationOfTheCall(): void
    {
        $resolver = $this->createMock(TermListResolverInterface::class);
        $resolver->expects(self::once())->method('forConfiguration')->with(7)->willReturn([]);
        $guardrail = new TermListGuardrail($resolver, new TermMatcher(), $this->messages(), new RecordingLogger());

        $guardrail->checkOutputInContext($this->response('x'), $this->context());
    }

    #[Test]
    public function aHeldStreamDependsOnAListNamingTheConfiguration(): void
    {
        self::assertTrue($this->guardrail([$this->rule(forbidden: ['Rabatt'])])->holdsStream($this->context()));
    }

    #[Test]
    public function aForbiddenTermInAToolArgumentBlocksEvenWithoutText(): void
    {
        $guardrail = $this->guardrail([$this->rule(forbidden: ['Rabatt'], required: ['Impressum'])]);
        $response  = $this->response('', [ToolCall::function('1', 'write', ['fields' => ['title' => 'Großer Rabatt', 'pid' => 3]])]);

        $result = $guardrail->checkOutputInContext($response, $this->context());

        self::assertSame(GuardrailVerdict::DENY, $result->verdict);
        self::assertSame('forbidden|Marke|Rabatt', $result->reason);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function jsonAnswers(): iterable
    {
        yield 'after a line break' => ['{"text":"Unsere Angebote.\n\nRabatt auf alles."}'];
        yield 'after a tab' => ['{"text":"Angebot:\tRabatt"}'];
        yield 'in a list in a fence' => ["```json\n{\"items\":[{\"text\":\"Erst.\\nRabatt\"}]}\n```"];
        yield 'written with escapes' => ['{"text":"\u0052abatt"}'];
    }

    #[Test]
    #[DataProvider('jsonAnswers')]
    public function aForbiddenTermInsideAJsonAnswerBlocks(string $content): void
    {
        $guardrail = $this->guardrail([$this->rule(forbidden: ['Rabatt'])]);

        $result = $guardrail->checkOutputInContext($this->response($content), $this->context());

        self::assertSame(GuardrailVerdict::DENY, $result->verdict);
    }

    #[Test]
    public function aRequiredTermIsNotDemandedFromAToolStep(): void
    {
        $guardrail = $this->guardrail([$this->rule(required: ['Impressum'])]);
        $response  = $this->response($this->longText(), [ToolCall::function('1', 'read', ['q' => 'x'])]);

        self::assertSame(GuardrailVerdict::ALLOW, $guardrail->checkOutputInContext($response, $this->context())->verdict);
    }

    #[Test]
    public function aRequiredTermIsDemandedFromThirtyWordsOn(): void
    {
        $guardrail = $this->guardrail([$this->rule(required: ['Impressum'])]);

        self::assertSame(GuardrailVerdict::ALLOW, $guardrail->checkOutputInContext($this->response(str_repeat('wort ', 29)), $this->context())->verdict);
        self::assertSame(GuardrailVerdict::DENY, $guardrail->checkOutputInContext($this->response(str_repeat('wort ', 30)), $this->context())->verdict);
    }

    #[Test]
    public function aShortHelperAnswerIsNotAskedForARequiredTerm(): void
    {
        $guardrail = $this->guardrail([$this->rule(required: ['Impressum'])]);

        self::assertSame(GuardrailVerdict::ALLOW, $guardrail->checkOutputInContext($this->response('de'), $this->context())->verdict);
    }

    #[Test]
    public function aRequiredTermIsNotAskedOfJson(): void
    {
        $guardrail = $this->guardrail([$this->rule(required: ['Impressum'])]);

        self::assertSame(GuardrailVerdict::ALLOW, $guardrail->checkOutputInContext($this->response('  {"text": "' . $this->longText() . '"}'), $this->context())->verdict);
        self::assertSame(GuardrailVerdict::ALLOW, $guardrail->checkOutputInContext($this->response("```json\n" . '{"text": "' . $this->longText() . '"}' . "\n```"), $this->context())->verdict);
    }

    #[Test]
    public function textThatOnlyLooksLikeJsonIsStillText(): void
    {
        $guardrail = $this->guardrail([$this->rule(required: ['Impressum'])]);

        self::assertSame(GuardrailVerdict::DENY, $guardrail->checkOutputInContext($this->response('[Anzeige] ' . $this->longText()), $this->context())->verdict);
    }

    #[Test]
    public function aRequiredTermIsNotAskedOfAnImageDescription(): void
    {
        $guardrail = $this->guardrail([$this->rule(required: ['Impressum'])]);
        $context   = new ProviderCallContext(ProviderOperation::Vision, 'c', $this->context()->configuration);

        self::assertSame(GuardrailVerdict::ALLOW, $guardrail->checkOutputInContext($this->response($this->longText()), $context)->verdict);
    }

    #[Test]
    public function aRequiredTermIsNotAskedOfAnAgentRun(): void
    {
        $guardrail = $this->guardrail([$this->rule(required: ['Impressum'])]);
        $context   = $this->context([GuardrailMiddleware::METADATA_AGENT_RUN_UID => 5]);

        self::assertSame(GuardrailVerdict::ALLOW, $guardrail->checkOutputInContext($this->response($this->longText()), $context)->verdict);
    }

    #[Test]
    public function aListOfAnotherSiteStaysOut(): void
    {
        $guardrail = $this->guardrail([$this->rule(forbidden: ['Rabatt'], required: ['Impressum'], site: 'shop')]);
        $context   = $this->context([ProviderCallContext::METADATA_SITE => 'blog']);

        self::assertSame(GuardrailVerdict::ALLOW, $guardrail->checkOutputInContext($this->response('Rabatt'), $context)->verdict);
    }

    #[Test]
    public function aListOfTheNamedSiteApplies(): void
    {
        $guardrail = $this->guardrail([$this->rule(required: ['Impressum'], site: 'shop')]);
        $context   = $this->context([ProviderCallContext::METADATA_SITE => 'shop']);

        self::assertSame(GuardrailVerdict::DENY, $guardrail->checkOutputInContext($this->response($this->longText()), $context)->verdict);
    }

    #[Test]
    public function aCallNamingNoSiteKeepsForbiddenTermsAndDropsRequiredOnes(): void
    {
        $forbids  = $this->guardrail([$this->rule(forbidden: ['Rabatt'], site: 'shop')]);
        $requires = $this->guardrail([$this->rule(required: ['Impressum'], site: 'shop')]);

        self::assertSame(GuardrailVerdict::DENY, $forbids->checkOutputInContext($this->response('Rabatt'), $this->context())->verdict);
        self::assertSame(GuardrailVerdict::ALLOW, $requires->checkOutputInContext($this->response($this->longText()), $this->context())->verdict);
    }

    #[Test]
    public function aLanguageIsComparedByItsBaseCode(): void
    {
        $guardrail = $this->guardrail([$this->rule(required: ['Impressum'], language: 'de')]);

        self::assertSame(
            GuardrailVerdict::DENY,
            $guardrail->checkOutputInContext($this->response($this->longText()), $this->context([ProviderCallContext::METADATA_LANGUAGE => 'de-DE']))->verdict,
        );
        self::assertSame(
            GuardrailVerdict::ALLOW,
            $guardrail->checkOutputInContext($this->response($this->longText()), $this->context([ProviderCallContext::METADATA_LANGUAGE => 'en']))->verdict,
        );
        self::assertSame(
            GuardrailVerdict::ALLOW,
            $guardrail->checkOutputInContext($this->response($this->longText()), $this->context())->verdict,
        );
    }

    #[Test]
    public function aListForTheNamedLanguageForbids(): void
    {
        $guardrail = $this->guardrail([$this->rule(forbidden: ['Rabatt'], language: 'de')]);

        self::assertSame(
            GuardrailVerdict::DENY,
            $guardrail->checkOutputInContext($this->response('Rabatt'), $this->context([ProviderCallContext::METADATA_LANGUAGE => 'de-AT']))->verdict,
        );
    }

    #[Test]
    public function aListForAnotherLanguageForbidsNothing(): void
    {
        $guardrail = $this->guardrail([$this->rule(forbidden: ['Rabatt'], language: 'de')]);

        self::assertSame(
            GuardrailVerdict::ALLOW,
            $guardrail->checkOutputInContext($this->response('Rabatt'), $this->context([ProviderCallContext::METADATA_LANGUAGE => 'en']))->verdict,
        );
    }

    #[Test]
    public function aFailedCheckBlocksSaysSoAndIsLogged(): void
    {
        $resolver = self::createStub(TermListResolverInterface::class);
        $resolver->method('forConfiguration')->willThrowException(new RuntimeException('database gone'));
        $logger    = new RecordingLogger();
        $guardrail = new TermListGuardrail($resolver, new TermMatcher(), $this->messages(), $logger);

        $result = $guardrail->checkOutputInContext($this->response($this->longText()), $this->context());

        self::assertSame(GuardrailVerdict::DENY, $result->verdict);
        self::assertSame('checkFailed', $result->reason);
        self::assertNotSame([], $logger->records);
    }

    #[Test]
    public function aPatternFailureOnTheTextIsAFailedCheckNotAPass(): void
    {
        $guardrail = $this->guardrail([$this->rule(forbidden: ['Rabatt'])]);

        $result = $guardrail->checkOutputInContext($this->response("kaputt \xC3\x28"), $this->context());

        self::assertSame(GuardrailVerdict::DENY, $result->verdict);
        self::assertSame('checkFailed', $result->reason);
    }

    #[Test]
    public function anUnreadableListHoldsTheStream(): void
    {
        $resolver = self::createStub(TermListResolverInterface::class);
        $resolver->method('forConfiguration')->willThrowException(new RuntimeException('database gone'));
        $guardrail = new TermListGuardrail($resolver, new TermMatcher(), $this->messages(), new RecordingLogger());

        self::assertTrue($guardrail->holdsStream($this->context()));
    }

    #[Test]
    public function itIsMandatoryUnderItsOwnIdentifier(): void
    {
        $guardrail = $this->guardrail([]);

        self::assertSame('term-list', $guardrail->getIdentifier());
        self::assertTrue($guardrail->isMandatory());
    }

    #[Test]
    public function aForbiddenTermInTheTextOfAChatPassesAndTheStreamRuns(): void
    {
        $guardrail = $this->guardrail([$this->rule(forbidden: ['Rabatt'], required: ['Impressum'])]);
        $context   = $this->chatContext();

        self::assertSame(GuardrailVerdict::ALLOW, $guardrail->checkOutputInContext($this->response('Ein Rabatt ' . $this->longText()), $context)->verdict);
        self::assertFalse($guardrail->holdsStream($context));
    }

    #[Test]
    public function aForbiddenTermAWritingToolWouldStoreBlocksInAChat(): void
    {
        $guardrail = $this->guardrail([$this->rule(forbidden: ['Rabatt'])]);
        $response  = $this->response('', [ToolCall::function('1', 'update_content', ['fields' => ['header' => 'Großer Rabatt']])]);

        $result = $guardrail->checkOutputInContext($response, $this->chatContext());

        self::assertSame(GuardrailVerdict::DENY, $result->verdict);
        self::assertSame('forbidden|Marke|Rabatt', $result->reason);
    }

    #[Test]
    public function aSearchForAForbiddenTermRunsInAChatWithoutOpeningAList(): void
    {
        $resolver = $this->createMock(TermListResolverInterface::class);
        $resolver->expects(self::never())->method('forConfiguration');
        $guardrail = new TermListGuardrail($resolver, new TermMatcher(), $this->messages(), new RecordingLogger());
        $response  = $this->response('', [ToolCall::function('1', 'search_records', ['term' => 'Rabatt'])]);

        self::assertSame(GuardrailVerdict::ALLOW, $guardrail->checkOutputInContext($response, $this->chatContext())->verdict);
    }

    #[Test]
    public function aToolThatDeclaresNoReadIsScreenedInAChat(): void
    {
        $guardrail = $this->guardrail([$this->rule(forbidden: ['Rabatt'])]);
        $response  = $this->response('', [ToolCall::function('1', 'unknown_tool', ['text' => 'Rabatt'])]);

        self::assertSame(GuardrailVerdict::DENY, $guardrail->checkOutputInContext($response, $this->chatContext())->verdict);
    }

    #[Test]
    public function aWriteBesideASearchIsScreenedOnItsOwnArguments(): void
    {
        $guardrail = $this->guardrail([$this->rule(forbidden: ['Rabatt'])]);
        $clean     = $this->response('', [
            ToolCall::function('1', 'search_records', ['term' => 'Rabatt']),
            ToolCall::function('2', 'update_content', ['fields' => ['header' => 'Sommer']]),
        ]);
        $dirty = $this->response('', [
            ToolCall::function('1', 'search_records', ['term' => 'Sommer']),
            ToolCall::function('2', 'update_content', ['fields' => ['header' => 'Rabatt']]),
        ]);

        self::assertSame(GuardrailVerdict::ALLOW, $guardrail->checkOutputInContext($clean, $this->chatContext())->verdict);
        self::assertSame(GuardrailVerdict::DENY, $guardrail->checkOutputInContext($dirty, $this->chatContext())->verdict);
    }

    #[Test]
    public function aListOfAnotherLanguageLeavesAWriteInAChatAlone(): void
    {
        $guardrail = $this->guardrail([$this->rule(forbidden: ['Rabatt'], language: 'en')]);
        $response  = $this->response('', [ToolCall::function('1', 'update_content', ['header' => 'Rabatt'])]);
        $context   = $this->chatContext([ProviderCallContext::METADATA_LANGUAGE => 'de-DE']);

        self::assertSame(GuardrailVerdict::ALLOW, $guardrail->checkOutputInContext($response, $context)->verdict);
    }

    #[Test]
    public function anUnreadableListBlocksAWriteInAChatAndLeavesTheTextAlone(): void
    {
        $resolver = self::createStub(TermListResolverInterface::class);
        $resolver->method('forConfiguration')->willThrowException(new RuntimeException('gone'));
        $guardrail = new TermListGuardrail($resolver, new TermMatcher(), $this->messages(), new RecordingLogger());

        $write = $this->response('', [ToolCall::function('1', 'update_content', ['header' => 'Sommer'])]);
        self::assertSame(GuardrailVerdict::DENY, $guardrail->checkOutputInContext($write, $this->chatContext())->verdict);
        self::assertSame(GuardrailVerdict::ALLOW, $guardrail->checkOutputInContext($this->response('Sommer'), $this->chatContext())->verdict);
        self::assertFalse($guardrail->holdsStream($this->chatContext()));
    }

    #[Test]
    public function aRunGivenNoToolsIsScreenedLikeADirectCompletion(): void
    {
        // A queued task or a field fill: the answer is what gets stored.
        $guardrail = $this->guardrail([$this->rule(forbidden: ['Rabatt'])]);
        $context   = $this->context([GuardrailMiddleware::METADATA_AGENT_RUN_UID => 5]);

        self::assertSame(GuardrailVerdict::DENY, $guardrail->checkOutputInContext($this->response('Ein Rabatt'), $context)->verdict);
        self::assertTrue($guardrail->holdsStream($context));
    }

    #[Test]
    public function aCallOutsideARunStillScreensAReadToolsArguments(): void
    {
        $guardrail = $this->guardrail([$this->rule(forbidden: ['Rabatt'])]);
        $response  = $this->response('', [ToolCall::function('1', 'search_records', ['term' => 'Rabatt'])]);

        self::assertSame(GuardrailVerdict::DENY, $guardrail->checkOutputInContext($response, $this->context())->verdict);
    }

    #[Test]
    public function aBlockOnAWorkerIsWrittenInTheLanguageOfThePersonTheCallBelongsTo(): void
    {
        unset($GLOBALS['BE_USER']);
        $language = self::createStub(LanguageService::class);
        $language->method('sL')->willReturn('blocked|%1$s|%2$s');
        $factory = $this->createMock(LanguageServiceFactory::class);
        $factory->expects(self::once())->method('create')->with('de')->willReturn($language);

        $resolver = self::createStub(TermListResolverInterface::class);
        $resolver->method('forConfiguration')->willReturn([$this->rule(forbidden: ['Rabatt'])]);
        $resolver->method('languageOfUser')->willReturnMap([[9, 'de']]);
        $guardrail = new TermListGuardrail($resolver, new TermMatcher(), new TermListMessages($factory), new RecordingLogger());

        $guardrail->checkOutputInContext($this->response('Ein Rabatt'), $this->context([BudgetMiddleware::METADATA_BE_USER_UID => 9]));
    }

    /**
     * @param list<TermListRule> $lists
     */
    private function guardrail(array $lists): TermListGuardrail
    {
        $resolver = self::createStub(TermListResolverInterface::class);
        $resolver->method('forConfiguration')->willReturn($lists);

        return new TermListGuardrail($resolver, new TermMatcher(), $this->messages(), new RecordingLogger());
    }

    /**
     * Messages with a label that shows which sentence was chosen and what went
     * into it, in place of the XLF text.
     */
    private function messages(): TermListMessages
    {
        $language = self::createStub(LanguageService::class);
        $language->method('sL')->willReturnCallback(static fn (string $key): string => match (true) {
            str_ends_with($key, '.forbidden') => 'forbidden|%1$s|%2$s',
            str_ends_with($key, '.required')  => 'required|%1$s|%2$s',
            default                           => 'checkFailed',
        });
        $factory = self::createStub(LanguageServiceFactory::class);
        $factory->method('createFromUserPreferences')->willReturn($language);

        return new TermListMessages($factory);
    }

    /**
     * @param list<string> $forbidden
     * @param list<string> $required
     */
    private function rule(array $forbidden = [], array $required = [], string $site = '', string $language = '', string $message = ''): TermListRule
    {
        return new TermListRule('Marke', $site, $language, $forbidden, $required, $message);
    }

    private function longText(): string
    {
        return str_repeat('ein ganz normaler Satz ', 10);
    }

    /**
     * @param list<ToolCall> $toolCalls
     */
    private function response(string $content, array $toolCalls = []): CompletionResponse
    {
        return new CompletionResponse($content, 'm', UsageStatistics::fromTokens(1, 1), toolCalls: $toolCalls === [] ? null : $toolCalls);
    }

    /**
     * A round of a run that offers tools, as ToolLoopService sends it.
     *
     * @param array<string, mixed> $metadata
     */
    private function chatContext(array $metadata = []): ProviderCallContext
    {
        return $this->context($metadata + [
            GuardrailMiddleware::METADATA_AGENT_RUN_UID  => 5,
            GuardrailMiddleware::METADATA_RUN_READ_TOOLS => ['search_records'],
        ]);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function context(array $metadata = []): ProviderCallContext
    {
        $configuration = new LlmConfiguration();
        $configuration->_setProperty('uid', 7);

        return new ProviderCallContext(ProviderOperation::Chat, 'c', $configuration, metadata: $metadata);
    }
}
