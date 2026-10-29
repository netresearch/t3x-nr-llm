<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Feature;

use DateTimeInterface;
use LogicException;
use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\GlossaryTerms;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Service\CacheManager;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Feature\TranslationPolisher;
use Netresearch\NrLlm\Service\Feature\TranslationPromptBuilder;
use Netresearch\NrLlm\Service\Feature\TranslationService;
use Netresearch\NrLlm\Service\Glossary\GlossaryResolverInterface;
use Netresearch\NrLlm\Service\Glossary\ResolvedGlossary;
use Netresearch\NrLlm\Service\LlmConfigurationServiceInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\Option\TranslationOptions;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Specialized\Translation\TranslatorInterface;
use Netresearch\NrLlm\Specialized\Translation\TranslatorRegistryInterface;
use Netresearch\NrLlm\Specialized\Translation\TranslatorResult;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Fixture\RecordingUsageTracker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Throwable;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager as Typo3CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

/**
 * The optional revision of a DeepL translation by a language model (ADR-233).
 *
 * The translator is a counting double that answers with a fixed text, the
 * chat call a recording double, the usage tracker the recording fixture and
 * the cache the real {@see CacheManager} over an in-memory backend.
 */
#[CoversClass(TranslationService::class)]
#[CoversClass(TranslationPolisher::class)]
final class TranslationServicePolishTest extends AbstractUnitTestCase
{
    /** @var array<string, int> translator identifier => calls */
    private array $translatorCalls = [];

    /** What the translator answers. */
    private string $draft = 'Hallo Welt';

    /** @var array<string, mixed>|null the translator's metadata */
    private ?array $draftMetadata = null;

    /** The model of the default configuration; null for no default configuration. */
    private ?string $defaultModel = 'gpt-test';

    /** @var list<array{messages: array<mixed>, options: ?ChatOptions}> */
    private array $chatCalls = [];

    /** What the chat call answers, or throws. */
    private string|Throwable $answer = 'Hallo, Welt!';

    private string $finishReason = 'stop';

    private RecordingUsageTracker $usageTracker;

    /** Make the usage tracker throw. */
    private ?Throwable $trackerFailure = null;

    /** The glossary the site keeps for the language pair. */
    private ?ResolvedGlossary $siteGlossary = null;

    /** How often the site glossary was resolved. */
    private int $glossaryResolutions = 0;

    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    private array $logRecords = [];

    /** Make the logger throw on an info record, i.e. after the answer arrived. */
    private bool $loggerThrowsOnInfo = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usageTracker = new RecordingUsageTracker();
    }

    #[Test]
    public function aDeepLTranslationIsRevisedWhenAsked(): void
    {
        $result = $this->subject()->translateWithTranslator('Hello world', 'de', 'en', $this->deepl()->withPolish());

        self::assertSame('Hallo, Welt!', $result->translatedText);
        self::assertTrue($result->isPolished());
        self::assertSame('deepl', $result->translator, 'the first pass stays the translator of record');
        self::assertSame(['applied' => true, 'model' => 'gpt-test'], $result->metadata['polish'] ?? null);
        self::assertCount(1, $this->chatCalls);

        $prompt = $this->prompt(0);
        self::assertStringContainsString("Source text:\nHello world", $prompt);
        self::assertStringContainsString("Translation to revise:\nHallo Welt", $prompt);
    }

    #[Test]
    public function withoutTheOptionNothingIsRevised(): void
    {
        $result = $this->subject()->translateWithTranslator('Hello world', 'de', 'en', $this->deepl());

        self::assertSame('Hallo Welt', $result->translatedText);
        self::assertFalse($result->isPolished());
        self::assertArrayNotHasKey('polish', $result->metadata ?? []);
        self::assertSame([], $this->chatCalls);
        self::assertSame([], $this->usageTracker->calls);
    }

    #[Test]
    public function aTranslationByTheLlmTranslatorIsNotRevisedAgain(): void
    {
        $result = $this->subject()->translateWithTranslator(
            'Hello world',
            'de',
            'en',
            (new TranslationOptions())->withTranslator('llm')->withPolish(),
        );

        self::assertSame('Hallo Welt', $result->translatedText);
        self::assertFalse($result->isPolished());
        self::assertSame(['applied' => false, 'reason' => 'llm_translator'], $result->metadata['polish'] ?? null);
        self::assertSame([], $this->chatCalls);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function rejectedAnswers(): iterable
    {
        yield 'a dropped tag' => ['<p>Hallo <strong>Welt</strong></p>', '<p>Hallo, Welt!</p>', 'markup_changed'];
        yield 'an altered attribute' => ['<a href="/de">Hallo</a>', '<a href="/en">Hallo!</a>', 'markup_changed'];
        yield 'a dropped placeholder' => ['Hallo {name}', 'Hallo du', 'placeholders_changed'];
        yield 'an altered printf placeholder' => ['%1$s Dateien', '%s Dateien', 'placeholders_changed'];
        yield 'an empty answer' => ['Hallo Welt', "  \n", 'empty'];
        yield 'crossed nesting' => ['<a href="/x">Hallo <strong>Welt</strong></a>', '<a href="/x">Hallo <strong>Welt</a></strong>', 'markup_changed'];
        yield 'swapped link targets' => ['<a href="/a">eins</a> und <a href="/b">zwei</a>', '<a href="/b">eins</a> und <a href="/a">zwei</a>', 'markup_changed'];
        yield 'a code fence' => ['Hallo Welt', "```\nHallo, Welt!\n```", 'code_fence'];
        yield 'an English preamble' => ['Hallo Welt', "Here is the revised translation:\nHallo, Welt!", 'preamble'];
        yield 'a German preamble' => ['Hallo Welt', "Hier ist die überarbeitete Übersetzung:\nHallo, Welt!", 'preamble'];
        yield 'a one-line preamble' => ['Hallo Welt', 'Sure, Hallo, Welt!', 'preamble'];
        yield 'an introducing line without a keyword' => ['Hallo Welt', "Hier die überarbeitete Übersetzung:\nHallo, Welt!", 'preamble'];
        yield 'an entity normalised in an href (accepted limit)' => ['<a href="/x?a=1&amp;b=2">Hallo</a>', '<a href="/x?a=1&b=2">Hallo!</a>', 'markup_changed'];
        yield 'a note after the last closing tag' => ['<p>Hallo Welt</p>', "<p>Hallo, Welt!</p>\nHinweis: Komma ergänzt.", 'added_lines'];
        yield 'a trailing note' => ['Hallo Welt', "Hallo, Welt!\nHinweis: Komma ergänzt.", 'added_lines'];
        yield 'a dropped comment' => ['<!-- keep -->Hallo Welt', 'Hallo, Welt!', 'markup_changed'];
    }

    #[Test]
    #[DataProvider('rejectedAnswers')]
    public function anAnswerThatChangesWhatMustSurviveFallsBackToTheFirstTranslation(string $draft, string $answer, string $reason): void
    {
        $this->draft  = $draft;
        $this->answer = $answer;

        $result = $this->subject()->translateWithTranslator('Hello', 'de', 'en', $this->deepl()->withPolish());

        self::assertSame($draft, $result->translatedText);
        self::assertFalse($result->isPolished());
        self::assertSame($reason, $result->metadata['polish']['reason'] ?? null);
    }

    #[Test]
    public function movedTagsAndPlaceholdersAreAccepted(): void
    {
        $this->draft  = '<em>Hallo</em> {name}, %s neue Nachrichten';
        $this->answer = 'Du hast %s neue Nachrichten, <em>hallo</em> {name}';

        $result = $this->subject()->translateWithTranslator('Hello', 'de', 'en', $this->deepl()->withPolish());

        self::assertTrue($result->isPolished());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function equivalentMarkup(): iterable
    {
        yield 'a self-closing br' => ['Hallo<br>Welt', 'Hallo,<br />Welt!'];
        yield 'the tag name case' => ['<B>Hallo</B> Welt', '<b>Hallo,</b> Welt!'];
        yield 'the quote style' => ["<a href='/x' title=\"x\">Hallo</a>", '<a  href="/x" title=\'x\'>Hallo!</a>'];
        yield 'a list laid out on lines' => ['<ul><li>Eins</li><li>Zwei</li></ul>', "<ul>\n  <li>Eins</li>\n  <li>Zwei!</li>\n</ul>"];
        yield 'a line break after a br' => ['Zeile eins<br>Zeile zwei', "Zeile eins,<br>\nZeile zwei"];
        yield 'whitespace in a comment' => ['<!--  keep  -->Hallo Welt', '<!-- keep -->Hallo, Welt!'];
    }

    #[Test]
    #[DataProvider('equivalentMarkup')]
    public function equivalentMarkupIsAccepted(string $draft, string $answer): void
    {
        $this->draft  = $draft;
        $this->answer = $answer;

        $result = $this->subject()->translateWithTranslator('Hello', 'de', 'en', $this->deepl()->withPolish());

        self::assertTrue($result->isPolished(), 'reason: ' . var_export($result->metadata['polish']['reason'] ?? null, true));
    }

    /**
     * An accepted limit (ADR-233): a label on the same line, outside the tags,
     * is not caught by any guard.
     */
    #[Test]
    public function aSameLineLabelOutsideTheTagsIsNotCaught(): void
    {
        $this->draft  = '<p>Hallo Welt</p>';
        $this->answer = 'Übersetzung: <p>Hallo, Welt!</p>';

        $result = $this->subject()->translateWithTranslator('Hello world', 'de', 'en', $this->deepl()->withPolish());

        self::assertTrue($result->isPolished());
    }

    #[Test]
    public function aDraftThatStartsWithAnIntroducingLineMayKeepIt(): void
    {
        $this->draft  = "Hinweis:\nBitte melden Sie sich an.";
        $this->answer = "Hinweis:\nBitte melden Sie sich zuerst an.";

        $result = $this->subject()->translateWithTranslator("Note:\nPlease log in.", 'de', 'en', $this->deepl()->withPolish());

        self::assertTrue($result->isPolished());
    }

    #[Test]
    public function aWordThatOnlyStartsLikeAPreambleIsNoPreamble(): void
    {
        $this->draft  = 'Isolated cases are rare.';
        $this->answer = 'Here isolated cases are rare.';

        $result = $this->subject()->translateWithTranslator('Vereinzelte Fälle sind selten.', 'en', 'de', $this->deepl()->withPolish());

        self::assertTrue($result->isPolished());
    }

    #[Test]
    public function anExceptionAfterTheAnswerArrivedFallsBackToo(): void
    {
        $this->loggerThrowsOnInfo = true;
        $this->draft              = '<p>Hallo</p>';
        $this->answer             = 'Hallo!';

        $result = $this->subject()->translateWithTranslator('Hello', 'de', 'en', $this->deepl()->withPolish());

        self::assertSame('<p>Hallo</p>', $result->translatedText);
        self::assertSame('failed', $result->metadata['polish']['reason'] ?? null);
        self::assertCount(1, $this->chatCalls, 'the answer had arrived');
        self::assertSame('error', $this->logRecords[0]['level'] ?? null);
    }

    #[Test]
    public function aProviderFailureIsAWarningWithTheException(): void
    {
        $this->answer = new ProviderConnectionException('timeout', 1730000003);

        $this->subject()->translateWithTranslator('Hello world', 'de', 'en', $this->deepl()->withPolish());

        self::assertCount(1, $this->logRecords);
        self::assertSame('warning', $this->logRecords[0]['level']);
        self::assertSame($this->answer, $this->logRecords[0]['context']['exception'] ?? null);
    }

    #[Test]
    public function anUnexpectedFailureIsAnErrorWithTheException(): void
    {
        $this->answer = new LogicException('defect', 1730000004);

        $result = $this->subject()->translateWithTranslator('Hello world', 'de', 'en', $this->deepl()->withPolish());

        self::assertSame('failed', $result->metadata['polish']['reason'] ?? null);
        self::assertSame('error', $this->logRecords[0]['level'] ?? null);
        self::assertSame($this->answer, $this->logRecords[0]['context']['exception'] ?? null);
    }

    #[Test]
    public function aBudgetRefusalIsAWarning(): void
    {
        $this->answer = new BudgetExceededException(BudgetCheckResult::denied('daily_cost', 5.0, 5.0));

        $this->subject()->translateWithTranslator('Hello world', 'de', 'en', $this->deepl()->withPolish());

        self::assertSame('warning', $this->logRecords[0]['level'] ?? null);
    }

    /**
     * The characters row counts what the model was given — the first
     * translation — and is written once the call returned, so an accepted
     * answer of another length and a rejected answer record the same.
     */
    #[Test]
    public function theCharactersRowCountsTheFirstTranslationWhetherOrNotTheAnswerIsAccepted(): void
    {
        $this->answer = 'Hallo, liebe Welt!';
        $accepted     = $this->subject()->translateWithTranslator('Hello world', 'de', 'en', $this->deepl()->withPolish());

        $this->answer = '<p>Hallo, Welt!</p>';
        $rejected     = $this->subject()->translateWithTranslator('Hello world', 'de', 'en', $this->deepl()->withPolish());

        self::assertTrue($accepted->isPolished());
        self::assertSame('markup_changed', $rejected->metadata['polish']['reason'] ?? null);
        self::assertCount(2, $this->usageTracker->calls);
        foreach ($this->usageTracker->calls as $row) {
            self::assertSame(TranslationPolisher::OPERATION, $row['serviceType']);
            self::assertSame(['characters' => mb_strlen('Hallo Welt')], $row['metrics']);
            self::assertFalse($row['countsAsRequest']);
        }
    }

    #[Test]
    public function aFailedUsageRowIsAWarningWithTheExceptionAndKeepsTheRevision(): void
    {
        $this->trackerFailure = new RuntimeException('database gone', 1730000006);

        $result = $this->subject()->translateWithTranslator('Hello world', 'de', 'en', $this->deepl()->withPolish());

        self::assertTrue($result->isPolished());
        self::assertSame('warning', $this->logRecords[0]['level'] ?? null);
        self::assertSame($this->trackerFailure, $this->logRecords[0]['context']['exception'] ?? null);
    }

    #[Test]
    public function theSiteGlossaryIsResolvedOncePerCall(): void
    {
        $this->siteGlossary = new ResolvedGlossary(3, 'en', 'de', GlossaryTerms::fromText('world = Welt'));

        $this->subject()->translateWithTranslator('Hello world', 'de', 'en', $this->deepl()->withSite('main')->withPolish());

        self::assertSame(1, $this->glossaryResolutions);
    }

    #[Test]
    public function theSiteGlossaryIsResolvedOncePerBatch(): void
    {
        $this->siteGlossary = new ResolvedGlossary(3, 'en', 'de', GlossaryTerms::fromText('world = Welt'));

        $this->subject()->translateBatchWithTranslator(['Hello', 'world', 'again'], 'de', 'en', $this->deepl()->withSite('main')->withPolish());

        self::assertSame(1, $this->glossaryResolutions);
        self::assertCount(3, $this->chatCalls);
    }

    #[Test]
    public function aDraftThatStartsLikeAPreambleMayKeepIt(): void
    {
        $this->draft  = 'Hier ist unser Angebot';
        $this->answer = 'Hier ist unser neues Angebot';

        $result = $this->subject()->translateWithTranslator('Here is our offer', 'de', 'en', $this->deepl()->withPolish());

        self::assertTrue($result->isPolished());
    }

    #[Test]
    public function aCutOffFirstTranslationIsNotRevised(): void
    {
        $this->draftMetadata = ['truncated' => true];

        $result = $this->subject()->translateWithTranslator('Hello world', 'de', 'en', $this->deepl()->withPolish());

        self::assertSame('Hallo Welt', $result->translatedText);
        self::assertSame('incomplete_translation', $result->metadata['polish']['reason'] ?? null);
        self::assertSame([], $this->chatCalls);
    }

    #[Test]
    public function aGlossaryTermThatSurvivesLetsTheRevisionApply(): void
    {
        $this->draft  = 'Willkommen im Kundenportal';
        $this->answer = 'Herzlich willkommen im Kundenportal!';

        $result = $this->subject()->translateWithTranslator(
            'Welcome to the customer portal',
            'de',
            'en',
            $this->deepl()->withGlossary(['customer portal' => 'Kundenportal'])->withPolish(),
        );

        self::assertTrue($result->isPolished());
        self::assertSame('Herzlich willkommen im Kundenportal!', $result->translatedText);
    }

    #[Test]
    public function anEmptyGlossaryTargetIsIgnored(): void
    {
        $result = $this->subject()->translateWithTranslator(
            'Hello world',
            'de',
            'en',
            $this->deepl()->withGlossary(['world' => ''])->withPolish(),
        );

        self::assertTrue($result->isPolished());
    }

    #[Test]
    public function aCutOffAnswerFallsBackToTheFirstTranslation(): void
    {
        $this->finishReason = 'length';

        $result = $this->subject()->translateWithTranslator('Hello world', 'de', 'en', $this->deepl()->withPolish());

        self::assertSame('Hallo Welt', $result->translatedText);
        self::assertSame('truncated', $result->metadata['polish']['reason'] ?? null);
    }

    #[Test]
    public function aBudgetRefusalFallsBackToTheFirstTranslation(): void
    {
        $this->answer = new RuntimeException(
            'pipeline',
            1730000001,
            new BudgetExceededException(BudgetCheckResult::denied('daily_cost', 5.0, 5.0)),
        );

        $result = $this->subject()->translateWithTranslator('Hello world', 'de', 'en', $this->deepl()->withPolish());

        self::assertSame('Hallo Welt', $result->translatedText);
        self::assertSame('budget_refused', $result->metadata['polish']['reason'] ?? null);
        self::assertSame([], $this->usageTracker->calls, 'a refused call is no request');
    }

    #[Test]
    public function aFailedCallFallsBackToTheFirstTranslation(): void
    {
        $this->answer = new RuntimeException('provider down', 1730000002);

        $result = $this->subject()->translateWithTranslator('Hello world', 'de', 'en', $this->deepl()->withPolish());

        self::assertSame('Hallo Welt', $result->translatedText);
        self::assertSame('failed', $result->metadata['polish']['reason'] ?? null);
    }

    #[Test]
    public function aGlossaryTermTheAnswerDropsFallsBackToTheFirstTranslation(): void
    {
        $this->draft  = 'Willkommen im Kundenportal';
        $this->answer = 'Willkommen im Portal';

        $result = $this->subject()->translateWithTranslator(
            'Welcome to the customer portal',
            'de',
            'en',
            $this->deepl()->withGlossary(['customer portal' => 'Kundenportal'])->withPolish(),
        );

        self::assertSame('Willkommen im Kundenportal', $result->translatedText);
        self::assertSame('glossary_term_changed', $result->metadata['polish']['reason'] ?? null);
        self::assertStringContainsString('- Kundenportal', $this->prompt(0));
    }

    #[Test]
    public function theSiteGlossaryTermsMustSurviveWhenTheCallerPassesNone(): void
    {
        $this->siteGlossary = new ResolvedGlossary(3, 'en', 'de', GlossaryTerms::fromText('customer portal = Kundenportal'));
        $this->draft        = 'Willkommen im Kundenportal';
        $this->answer       = 'Willkommen im Portal';

        $result = $this->subject()->translateWithTranslator(
            'Welcome to the customer portal',
            'de',
            'en',
            $this->deepl()->withSite('main')->withPolish(),
        );

        self::assertSame('Willkommen im Kundenportal', $result->translatedText);
        self::assertSame('glossary_term_changed', $result->metadata['polish']['reason'] ?? null);
        self::assertStringContainsString('- Kundenportal', $this->prompt(0));
    }

    #[Test]
    public function anEmptyFirstTranslationIsNotRevised(): void
    {
        $this->draft = '';

        $result = $this->subject()->translateWithTranslator('Hello world', 'de', 'en', $this->deepl()->withPolish());

        self::assertSame('', $result->translatedText);
        self::assertSame('incomplete_translation', $result->metadata['polish']['reason'] ?? null);
        self::assertSame([], $this->chatCalls);
    }

    #[Test]
    public function formalityAndTheCallersInstructionReachThePrompt(): void
    {
        $this->subject()->translateWithTranslator(
            'Hello world',
            'de',
            'en',
            $this->deepl()->withFormality('formal')->withPolish(true, 'Use short sentences.'),
        );

        $prompt = $this->prompt(0);
        self::assertStringContainsString('Keep the formal tone', $prompt);
        self::assertStringContainsString('Additional instruction: Use short sentences.', $prompt);
        self::assertStringContainsString('Keep every HTML or XML tag', $prompt);
    }

    #[Test]
    public function theRevisionIsItsOwnUsageRowForTheCallingExtension(): void
    {
        $this->subject()->translateWithTranslator(
            'Hello world',
            'de',
            'en',
            $this->deepl()->withPolish()->withBeUserUid(7)->withCallerSource('nr_cowriter', 'translate'),
        );

        self::assertCount(1, $this->usageTracker->calls);
        $row = $this->usageTracker->calls[0];
        self::assertSame(TranslationPolisher::OPERATION, $row['serviceType']);
        self::assertSame('translation_polish', $row['serviceType']);
        self::assertSame('nr_cowriter', $row['sourceExtension']);
        self::assertSame(7, $row['beUserUid']);
        self::assertSame('gpt-test', $row['modelId']);
        self::assertSame(['characters' => 10], $row['metrics']);
        self::assertFalse($row['countsAsRequest'], 'the translator row is the single request of record');

        $chatOptions = $this->chatCalls[0]['options'];
        self::assertInstanceOf(ChatOptions::class, $chatOptions);
        self::assertFalse($chatOptions->getSuppressRequestCount(), 'the revision is an ordinary, counted request');
        self::assertSame(7, $chatOptions->getBeUserUid(), 'the budget pre-flight checks the caller');
        self::assertSame('nr_cowriter', $chatOptions->getCallerSourceExtension());
        self::assertSame('translate', $chatOptions->getCallerSourceOperation());
    }

    #[Test]
    public function withoutAPolisherARequestedRevisionIsReportedAsUnavailable(): void
    {
        $result = $this->subject(withPolisher: false)
            ->translateWithTranslator('Hello world', 'de', 'en', $this->deepl()->withPolish());

        self::assertSame('Hallo Welt', $result->translatedText);
        self::assertSame('unavailable', $result->metadata['polish']['reason'] ?? null);
    }

    #[Test]
    public function aBatchIsRevisedTextByText(): void
    {
        $results = $this->subject()->translateBatchWithTranslator(['Hello', 'world'], 'de', 'en', $this->deepl()->withPolish());

        self::assertCount(2, $results);
        self::assertTrue($results[0]->isPolished());
        self::assertTrue($results[1]->isPolished());
        self::assertStringContainsString("Source text:\nHello\n", $this->prompt(0));
        self::assertStringContainsString("Source text:\nworld\n", $this->prompt(1));
    }

    #[Test]
    public function aRevisedTranslationIsCachedUnderItsOwnKey(): void
    {
        $subject = $this->subject();

        $plain    = $subject->translateWithTranslator('Hello world', 'de', 'en', $this->deepl()->withCacheTtl(60));
        $polished = $subject->translateWithTranslator('Hello world', 'de', 'en', $this->deepl()->withCacheTtl(60)->withPolish());
        $again    = $subject->translateWithTranslator('Hello world', 'de', 'en', $this->deepl()->withCacheTtl(60)->withPolish());

        self::assertSame(2, $this->translatorCalls['deepl'] ?? 0, 'with and without revision are two keys; the repeat is a hit');
        self::assertSame('Hallo Welt', $plain->translatedText);
        self::assertSame('Hallo, Welt!', $polished->translatedText);
        self::assertSame('Hallo, Welt!', $again->translatedText);
        self::assertTrue($again->isPolished());
        self::assertTrue($again->metadata['cached'] ?? false);
        self::assertCount(1, $this->chatCalls);
    }

    #[Test]
    public function anotherInstructionIsAnotherCacheKey(): void
    {
        $subject = $this->subject();

        $subject->translateWithTranslator('Hello world', 'de', 'en', $this->deepl()->withCacheTtl(60)->withPolish(true, 'Be brief.'));
        $subject->translateWithTranslator('Hello world', 'de', 'en', $this->deepl()->withCacheTtl(60)->withPolish(true, 'Be warm.'));

        self::assertSame(2, $this->translatorCalls['deepl'] ?? 0);
    }

    #[Test]
    public function anotherRevisingModelIsAnotherCacheKey(): void
    {
        $subject = $this->subject();

        $subject->translateWithTranslator('Hello world', 'de', 'en', $this->deepl()->withCacheTtl(60)->withPolish());
        $this->defaultModel = 'gpt-other';
        $subject->translateWithTranslator('Hello world', 'de', 'en', $this->deepl()->withCacheTtl(60)->withPolish());

        self::assertSame(2, $this->translatorCalls['deepl'] ?? 0);
    }

    #[Test]
    public function aRevisionWhoseModelCannotBeToldIsNotCached(): void
    {
        $subject            = $this->subject();
        $this->defaultModel = null;

        $subject->translateWithTranslator('Hello world', 'de', 'en', $this->deepl()->withCacheTtl(60)->withPolish());
        $second = $subject->translateWithTranslator('Hello world', 'de', 'en', $this->deepl()->withCacheTtl(60)->withPolish());

        self::assertSame(2, $this->translatorCalls['deepl'] ?? 0);
        self::assertTrue($second->isPolished());
        self::assertArrayNotHasKey('cached', $second->metadata ?? []);
    }

    #[Test]
    public function aBatchByTheLlmTranslatorIsNotRevised(): void
    {
        $results = $this->subject()->translateBatchWithTranslator(
            ['Hello', 'world'],
            'de',
            'en',
            (new TranslationOptions())->withTranslator('llm')->withPolish(),
        );

        self::assertCount(2, $results);
        foreach ($results as $result) {
            self::assertSame(['applied' => false, 'reason' => 'llm_translator'], $result->metadata['polish'] ?? null);
        }

        self::assertSame([], $this->chatCalls);
    }

    #[Test]
    public function aRevisionThatFellBackIsNotCached(): void
    {
        $subject      = $this->subject();
        $this->answer = new BudgetExceededException(BudgetCheckResult::denied('daily_cost', 5.0, 5.0));

        $subject->translateWithTranslator('Hello world', 'de', 'en', $this->deepl()->withCacheTtl(60)->withPolish());
        $this->answer = 'Hallo, Welt!';
        $second       = $subject->translateWithTranslator('Hello world', 'de', 'en', $this->deepl()->withCacheTtl(60)->withPolish());

        self::assertSame(2, $this->translatorCalls['deepl'] ?? 0);
        self::assertTrue($second->isPolished());
    }

    private function deepl(): TranslationOptions
    {
        return (new TranslationOptions())->withTranslator('deepl');
    }

    private function prompt(int $call): string
    {
        $text = '';
        foreach ($this->chatCalls[$call]['messages'] ?? [] as $message) {
            if ($message instanceof ChatMessage) {
                $text .= $message->content . "\n";
            }
        }

        return $text;
    }

    private function subject(bool $withPolisher = true): TranslationService
    {
        $registry = self::createStub(TranslatorRegistryInterface::class);
        $registry->method('get')->willReturnCallback(fn (string $identifier): TranslatorInterface => $this->translator($identifier));

        $llmManager = self::createStub(LlmServiceManagerInterface::class);
        $llmManager->method('chat')->willReturnCallback(function (array $messages, ?ChatOptions $options): CompletionResponse {
            $this->chatCalls[] = ['messages' => $messages, 'options' => $options];
            if ($this->answer instanceof Throwable) {
                throw $this->answer;
            }

            return new CompletionResponse($this->answer, 'gpt-test', new UsageStatistics(10, 5, 15), $this->finishReason);
        });

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findDefault')->willReturnCallback(function (): ?LlmConfiguration {
            if ($this->defaultModel === null) {
                return null;
            }

            $model = new Model();
            $model->setModelId($this->defaultModel);
            $default = new LlmConfiguration();
            $default->_setProperty('uid', 1);
            $default->setIdentifier('default');
            $default->setLlmModel($model);

            return $default;
        });

        $typo3CacheManager = new Typo3CacheManager();
        $typo3CacheManager->setCacheConfigurations([
            'nrllm_responses' => ['frontend' => VariableFrontend::class, 'backend' => TransientMemoryBackend::class, 'options' => [], 'groups' => []],
        ]);

        return new TranslationService(
            $llmManager,
            $registry,
            self::createStub(LlmConfigurationServiceInterface::class),
            new TranslationPromptBuilder(),
            null,
            $this->glossaryResolver(),
            null,
            new CacheManager($typo3CacheManager),
            new ConfigurationResolver($repository),
            $withPolisher ? new TranslationPolisher($llmManager, $this->tracker(), $this->logger()) : null,
        );
    }

    /**
     * @internal read by the resolver double
     */
    public function currentSiteGlossary(): ?ResolvedGlossary
    {
        ++$this->glossaryResolutions;

        return $this->siteGlossary;
    }

    /**
     * @internal read by the logger double
     *
     * @param array<mixed> $context
     */
    public function recordLog(string $level, string $message, array $context): void
    {
        if ($level === 'info' && $this->loggerThrowsOnInfo) {
            throw new RuntimeException('logger broke after the answer arrived', 1730000005);
        }

        $this->logRecords[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }

    /**
     * @internal read by the tracker double
     */
    public function currentTrackerFailure(): ?Throwable
    {
        return $this->trackerFailure;
    }

    private function tracker(): UsageTrackerServiceInterface
    {
        return new class ($this, $this->usageTracker) implements UsageTrackerServiceInterface {
            public function __construct(
                private readonly TranslationServicePolishTest $test,
                private readonly RecordingUsageTracker $recording,
            ) {}

            public function trackUsage(
                string $serviceType,
                string $provider,
                array $metrics = [],
                ?int $configurationUid = null,
                int $modelUid = 0,
                string $modelId = '',
                int $taskUid = 0,
                ?int $beUserUid = null,
                bool $countsAsRequest = true,
                string $sourceExtension = '',
            ): void {
                $failure = $this->test->currentTrackerFailure();
                if ($failure instanceof Throwable) {
                    throw $failure;
                }

                $this->recording->trackUsage($serviceType, $provider, $metrics, $configurationUid, $modelUid, $modelId, $taskUid, $beUserUid, $countsAsRequest, $sourceExtension);
            }

            public function getUsageReport(string $serviceType, DateTimeInterface $from, DateTimeInterface $to): array
            {
                return [];
            }

            public function getUserUsage(int $beUserUid, DateTimeInterface $from, DateTimeInterface $to): array
            {
                return [];
            }

            public function getTodayUsage(string $serviceType, string $provider): ?array
            {
                return null;
            }

            public function getCurrentMonthCost(): float
            {
                return 0.0;
            }
        };
    }

    private function logger(): AbstractLogger
    {
        return new class ($this) extends AbstractLogger {
            public function __construct(private readonly TranslationServicePolishTest $test) {}

            /**
             * @param array<mixed> $context
             */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->test->recordLog(is_string($level) ? $level : 'unknown', (string) $message, $context);
            }
        };
    }

    private function glossaryResolver(): GlossaryResolverInterface
    {
        return new class ($this) implements GlossaryResolverInterface {
            public function __construct(private readonly TranslationServicePolishTest $test) {}

            public function resolve(string $siteIdentifier, string $sourceLanguage, string $targetLanguage): ?ResolvedGlossary
            {
                return $this->test->currentSiteGlossary();
            }

            public function storeDeepLGlossary(int $uid, string $deeplGlossaryId, string $entriesHash): void
            {
                // Not reached: no DeepL glossary sync is wired.
            }

            public function isDeepLGlossaryReferenced(string $deeplGlossaryId, int $exceptUid): bool
            {
                return false;
            }
        };
    }

    private function translator(string $identifier): TranslatorInterface
    {
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('getIdentifier')->willReturn($identifier);
        $translator->method('translate')->willReturnCallback(
            function (string $text, string $target, ?string $source) use ($identifier): TranslatorResult {
                $this->translatorCalls[$identifier] = ($this->translatorCalls[$identifier] ?? 0) + 1;

                return new TranslatorResult($this->draft, $source ?? 'en', $target, $identifier, charactersUsed: mb_strlen($text), metadata: $this->draftMetadata);
            },
        );
        $translator->method('translateBatch')->willReturnCallback(
            function (array $texts, string $target, ?string $source) use ($identifier): array {
                $results = [];
                foreach ($texts as $text) {
                    self::assertIsString($text);
                    $results[] = new TranslatorResult('[' . $identifier . '] ' . $text, $source ?? 'en', $target, $identifier);
                }

                return $results;
            },
        );

        return $translator;
    }
}
