<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Feature;

use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\GlossaryTerms;
use Netresearch\NrLlm\Exception\BudgetExceededException;
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
use Netresearch\NrLlm\Specialized\Translation\TranslatorInterface;
use Netresearch\NrLlm\Specialized\Translation\TranslatorRegistryInterface;
use Netresearch\NrLlm\Specialized\Translation\TranslatorResult;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Fixture\RecordingUsageTracker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use RuntimeException;
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

    /** @var list<array{messages: array<mixed>, options: ?ChatOptions}> */
    private array $chatCalls = [];

    /** What the chat call answers, or throws. */
    private string|Throwable $answer = 'Hallo, Welt!';

    private string $finishReason = 'stop';

    private RecordingUsageTracker $usageTracker;

    /** The glossary the site keeps for the language pair. */
    private ?ResolvedGlossary $siteGlossary = null;

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
        self::assertTrue($chatOptions->getSuppressRequestCount(), 'the chat row carries tokens, the polish row the request');
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

        $model = new Model();
        $model->setModelId('gpt-test');
        $default = new LlmConfiguration();
        $default->_setProperty('uid', 1);
        $default->setIdentifier('default');
        $default->setLlmModel($model);

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findDefault')->willReturn($default);

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
            $withPolisher ? new TranslationPolisher($llmManager, $this->usageTracker, new NullLogger()) : null,
        );
    }

    /**
     * @internal read by the resolver double
     */
    public function currentSiteGlossary(): ?ResolvedGlossary
    {
        return $this->siteGlossary;
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

                return new TranslatorResult($this->draft, $source ?? 'en', $target, $identifier, charactersUsed: mb_strlen($text));
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
