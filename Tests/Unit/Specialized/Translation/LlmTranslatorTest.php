<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Translation;

use LogicException;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Provider\AbstractProvider;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Specialized\Translation\LlmTranslator;
use Netresearch\NrLlm\Specialized\Translation\TranslatorResult;
use Netresearch\NrLlm\Tests\LlmServiceManagerTestFactory;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(LlmTranslator::class)]
#[CoversClass(TranslatorResult::class)]
class LlmTranslatorTest extends AbstractUnitTestCase
{
    use LlmServiceManagerTestFactory;
    private LlmServiceManager $llmManager;
    private UsageTrackerServiceInterface&Stub $usageTrackerStub;
    private LlmTranslator $subject;
    private TranslatorTestProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $extensionConfigStub = self::createStub(ExtensionConfiguration::class);
        $extensionConfigStub
            ->method('get')
            ->willReturn([
                'providers' => [],
            ]);

        $loggerStub = self::createStub(LoggerInterface::class);

        $this->provider = new TranslatorTestProvider();

        // Default DB configuration (flow 2): a provider-agnostic chat() — used
        // by detectLanguage() and the auto-detect path of translate() — has no
        // pinned provider, so the manager resolves the backend-module default
        // configuration. A bare config with a Model assigned and no access
        // restrictions satisfies resolveDefaultConfiguration()'s gate
        // (getLlmModel() !== null && !hasAccessRestrictions()).
        $defaultModel = new Model();
        $defaultModel->setModelId('gpt-5.2');

        $defaultConfiguration = new LlmConfiguration();
        $defaultConfiguration->setIdentifier('test-default');
        $defaultConfiguration->setLlmModel($defaultModel);

        $configurationRepositoryStub = self::createStub(LlmConfigurationRepository::class);
        $configurationRepositoryStub->method('findDefault')->willReturn($defaultConfiguration);

        // The default configuration resolves its adapter through the registry;
        // route it back to the same in-memory test provider the pinned
        // (per-call ['provider' => 'openai']) flow uses, so both paths share
        // one response source.
        $adapterRegistryStub = self::createStub(ProviderAdapterRegistryInterface::class);
        $adapterRegistryStub->method('createAdapterFromModel')->willReturn($this->provider);

        $this->llmManager = $this->createLlmServiceManager(
            $extensionConfigStub,
            $loggerStub,
            $adapterRegistryStub,
            $this->emptyMiddlewarePipeline(),
            self::createStub(CacheManagerInterface::class),
            $configurationRepositoryStub,
        );

        $this->llmManager->registerProvider($this->provider);

        $this->usageTrackerStub = self::createStub(UsageTrackerServiceInterface::class);

        $this->subject = new LlmTranslator(
            $this->llmManager,
            $this->usageTrackerStub,
        );
    }

    private function setResponse(string $content, string $finishReason = 'stop'): void
    {
        $this->provider->nextResponse = new CompletionResponse(
            content: $content,
            model: 'gpt-5.2',
            usage: new UsageStatistics(100, 50, 150),
            finishReason: $finishReason,
            provider: 'openai',
        );
    }

    // ==================== Basic tests ====================

    #[Test]
    public function getIdentifierReturnsLlm(): void
    {
        self::assertEquals('llm', $this->subject->getIdentifier());
    }

    #[Test]
    public function getNameReturnsLlmBasedTranslation(): void
    {
        self::assertEquals('LLM-based Translation', $this->subject->getName());
    }

    #[Test]
    public function translateSuppressesRequestCountOnTheUnderlyingChatCall(): void
    {
        // Regression for #473: the translator records its own 'translation' request
        // row, so the underlying chat call must not be counted as a second request.
        $capturedOptions = [];

        $managerMock = $this->createMock(LlmServiceManagerInterface::class);
        $managerMock
            ->method('chat')
            ->willReturnCallback(
                function (array $messages, ?ChatOptions $options = null) use (&$capturedOptions): CompletionResponse {
                    $capturedOptions[] = $options;

                    return new CompletionResponse(
                        content: 'Hallo',
                        model: 'gpt-5.2',
                        usage: new UsageStatistics(100, 50, 150),
                        finishReason: 'stop',
                        provider: 'openai',
                    );
                },
            );

        $translator = new LlmTranslator(
            $managerMock,
            self::createStub(UsageTrackerServiceInterface::class),
        );

        // Explicit source language avoids the auto-detection sub-call, isolating
        // the translation chat call under assertion.
        $translator->translate('Hello', 'de', 'en', ['provider' => 'openai']);

        self::assertNotEmpty($capturedOptions);
        self::assertInstanceOf(ChatOptions::class, $capturedOptions[0]);
        self::assertTrue(
            $capturedOptions[0]->getSuppressRequestCount(),
            'The underlying chat call of a translation must not be counted as a request.',
        );
    }

    #[Test]
    public function isAvailableReturnsTrueWhenProviderAvailable(): void
    {
        self::assertTrue($this->subject->isAvailable());
    }

    #[Test]
    public function getSupportedLanguagesReturnsLanguageCodes(): void
    {
        $languages = $this->subject->getSupportedLanguages();

        self::assertContains('en', $languages);
        self::assertContains('de', $languages);
        self::assertContains('fr', $languages);
        self::assertContains('es', $languages);
        self::assertContains('ja', $languages);
        self::assertContains('zh', $languages);
    }

    #[Test]
    public function supportsLanguagePairAlwaysReturnsTrue(): void
    {
        self::assertTrue($this->subject->supportsLanguagePair('en', 'de'));
        self::assertTrue($this->subject->supportsLanguagePair('xx', 'yy'));
    }

    // ==================== translate tests ====================

    #[Test]
    public function translateReturnsTranslatorResult(): void
    {
        $this->setResponse('Hallo Welt');

        $result = $this->subject->translate('Hello World', 'de', 'en', ['provider' => 'openai']);

        self::assertInstanceOf(TranslatorResult::class, $result);
        self::assertEquals('Hallo Welt', $result->translatedText);
        self::assertEquals('en', $result->sourceLanguage);
        self::assertEquals('de', $result->targetLanguage);
        self::assertStringStartsWith('llm:', $result->translator);
    }

    #[Test]
    public function translateTracksUsage(): void
    {
        $this->setResponse('Translated text');

        $usageTrackerMock = $this->createMock(UsageTrackerServiceInterface::class);
        $usageTrackerMock
            ->expects(self::once())
            ->method('trackUsage')
            ->with(
                'translation',
                self::stringStartsWith('llm:'),
                self::callback(
                    // Characters only: tokens and cost are recorded by the
                    // middleware pipeline on the underlying chat row —
                    // repeating them here would double-count them.
                    fn(array $metrics): bool => isset($metrics['characters'])
                        && !isset($metrics['tokens'])
                        && !isset($metrics['cost']),
                ),
                null,
                0,
                'gpt-5.2',
                0,
                // Ambient fallback: no beUserUid option key was passed.
                null,
            );

        $subject = new LlmTranslator(
            $this->llmManager,
            $usageTrackerMock,
        );

        $subject->translate('Test text', 'de', 'en', ['provider' => 'openai']);
    }

    #[Test]
    public function translateForwardsBeUserUidOptionToTracker(): void
    {
        // ADR-052: the `beUserUid` options key (attached by
        // TranslationService) must reach the tracker for attribution.
        $this->setResponse('Translated text');

        $usageTrackerMock = $this->createMock(UsageTrackerServiceInterface::class);
        $usageTrackerMock
            ->expects(self::once())
            ->method('trackUsage')
            ->with(
                'translation',
                self::stringStartsWith('llm:'),
                self::anything(),
                null,
                0,
                'gpt-5.2',
                0,
                42,
            );

        $subject = new LlmTranslator(
            $this->llmManager,
            $usageTrackerMock,
        );

        $subject->translate('Test text', 'de', 'en', ['provider' => 'openai', 'beUserUid' => 42]);
    }

    #[Test]
    public function translateForwardsBeUserUidToUnderlyingChatOptions(): void
    {
        // ADR-052: the underlying chat call carries all tokens and cost, so
        // it must be attributed to the same be_user as the translation row —
        // otherwise BudgetMiddleware skips enforcement (uid 0) and the chat
        // row lands in the ambient bucket.
        [$llmManager, $captured] = $this->createChatCapturingManager('Hallo Welt');

        $subject = new LlmTranslator($llmManager, $this->usageTrackerStub);
        $subject->translate('Hello World', 'de', 'en', ['beUserUid' => 42]);

        self::assertCount(1, $captured->list);
        self::assertSame(42, $captured->list[0]->getBeUserUid());
    }

    #[Test]
    public function translateWithAutoDetectAttributesDetectionChatCall(): void
    {
        // The language-detection chat call is billed like any other request;
        // it must carry the caller's uid, not fall back to ambient.
        [$llmManager, $captured] = $this->createChatCapturingManager('en');

        $subject = new LlmTranslator($llmManager, $this->usageTrackerStub);
        $subject->translate('Hello World', 'de', null, ['beUserUid' => 42]);

        self::assertCount(2, $captured->list);
        self::assertSame(42, $captured->list[0]->getBeUserUid(), 'detection call');
        self::assertSame(42, $captured->list[1]->getBeUserUid(), 'translation call');
    }

    #[Test]
    public function detectLanguageWithoutCallerContextStaysAmbient(): void
    {
        // Public TranslatorInterface::detectLanguage() has no options
        // parameter — a direct call keeps the previous ambient behavior.
        [$llmManager, $captured] = $this->createChatCapturingManager('de');

        $subject = new LlmTranslator($llmManager, $this->usageTrackerStub);
        $subject->detectLanguage('Hallo Welt');

        self::assertCount(1, $captured->list);
        self::assertNull($captured->list[0]->getBeUserUid());
    }

    #[Test]
    public function translateTreatsNegativeBeUserUidAsAbsent(): void
    {
        // A negative uid from the untrusted options array must not reach the
        // ChatOptions constructor, whose budget-field validation throws.
        [$llmManager, $captured] = $this->createChatCapturingManager('Hallo Welt');

        $subject = new LlmTranslator($llmManager, $this->usageTrackerStub);
        $subject->translate('Hello World', 'de', 'en', ['beUserUid' => -5]);

        self::assertCount(1, $captured->list);
        self::assertNull($captured->list[0]->getBeUserUid());
    }

    /**
     * Stub the manager interface so every ChatOptions passed to chat() is
     * captured and answered with a fixed response.
     *
     * @return array{0: LlmServiceManagerInterface&Stub, 1: object{list: array<int, ChatOptions>}}
     */
    private function createChatCapturingManager(string $responseContent): array
    {
        $captured = new class {
            /** @var array<int, ChatOptions> */
            public array $list = [];
        };

        $response = new CompletionResponse(
            content: $responseContent,
            model: 'gpt-5.2',
            usage: new UsageStatistics(100, 50, 150),
            finishReason: 'stop',
            provider: 'openai',
        );

        $llmManager = self::createStub(LlmServiceManagerInterface::class);
        $llmManager
            ->method('chat')
            ->willReturnCallback(
                static function (array $messages, ?ChatOptions $options = null) use ($captured, $response): CompletionResponse {
                    self::assertNotSame([], $messages);
                    self::assertInstanceOf(ChatOptions::class, $options);
                    $captured->list[] = $options;

                    return $response;
                },
            );

        return [$llmManager, $captured];
    }

    #[Test]
    public function translateCalculatesHighConfidenceForStopFinishReason(): void
    {
        $this->setResponse('Result', 'stop');

        $result = $this->subject->translate('Test', 'de', 'en', ['provider' => 'openai']);

        self::assertEquals(0.9, $result->confidence);
    }

    #[Test]
    public function translateCalculatesLowerConfidenceForLengthFinishReason(): void
    {
        $this->setResponse('Result', 'length');

        $result = $this->subject->translate('Test', 'de', 'en', ['provider' => 'openai']);

        self::assertEquals(0.6, $result->confidence);
    }

    #[Test]
    public function translateCalculatesDefaultConfidenceForOtherFinishReasons(): void
    {
        $this->setResponse('Result', 'unknown');

        $result = $this->subject->translate('Test', 'de', 'en', ['provider' => 'openai']);

        self::assertEquals(0.5, $result->confidence);
    }

    #[Test]
    public function translateIncludesMetadata(): void
    {
        $this->setResponse('Translated');

        $result = $this->subject->translate('Test', 'de', 'en', ['provider' => 'openai']);

        self::assertNotNull($result->metadata);
        self::assertArrayHasKey('model', $result->metadata);
        self::assertArrayHasKey('usage', $result->metadata);
        self::assertEquals('gpt-5.2', $result->metadata['model']);
        /** @var array{prompt_tokens: int, completion_tokens: int, total_tokens: int} $usage */
        $usage = $result->metadata['usage'];
        self::assertEquals(100, $usage['prompt_tokens']);
        self::assertEquals(50, $usage['completion_tokens']);
        self::assertEquals(150, $usage['total_tokens']);
    }

    #[Test]
    public function translateDetectsSourceLanguageWhenNotProvided(): void
    {
        // Provider returns 'de' for detection, then translated text
        $this->provider->responseQueue = [
            new CompletionResponse('de', 'gpt-5.2', new UsageStatistics(10, 5, 15), 'stop', 'openai'),
            new CompletionResponse('Translated', 'gpt-5.2', new UsageStatistics(100, 50, 150), 'stop', 'openai'),
        ];

        $result = $this->subject->translate('Das ist ein Test', 'en', null, ['provider' => 'openai']);

        self::assertEquals('de', $result->sourceLanguage);
        self::assertEquals('Translated', $result->translatedText);
    }

    // ==================== translateBatch tests ====================

    #[Test]
    public function translateBatchReturnsEmptyArrayForEmptyInput(): void
    {
        $result = $this->subject->translateBatch([], 'de');

        self::assertEmpty($result);
    }

    #[Test]
    public function translateBatchTranslatesAllTexts(): void
    {
        $this->provider->responseQueue = [
            new CompletionResponse('First', 'gpt-5.2', new UsageStatistics(10, 5, 15), 'stop', 'openai'),
            new CompletionResponse('Second', 'gpt-5.2', new UsageStatistics(10, 5, 15), 'stop', 'openai'),
            new CompletionResponse('Third', 'gpt-5.2', new UsageStatistics(10, 5, 15), 'stop', 'openai'),
        ];

        $result = $this->subject->translateBatch(['Text 1', 'Text 2', 'Text 3'], 'de', 'en', ['provider' => 'openai']);

        self::assertCount(3, $result);
    }

    // ==================== detectLanguage tests ====================

    #[Test]
    public function detectLanguageReturnsDetectedCode(): void
    {
        $this->setResponse('de');

        $result = $this->subject->detectLanguage('Das ist ein Test');

        self::assertEquals('de', $result);
    }

    #[Test]
    public function detectLanguageTrimsAndLowercasesResult(): void
    {
        $this->setResponse('  FR  ');

        $result = $this->subject->detectLanguage('Bonjour le monde');

        self::assertEquals('fr', $result);
    }

    #[Test]
    public function detectLanguageFallsBackToEnglishForInvalidResponse(): void
    {
        $this->setResponse('This is English text');

        $result = $this->subject->detectLanguage('Test text');

        self::assertEquals('en', $result);
    }

    // ==================== TranslatorResult tests ====================

    #[Test]
    public function translatorResultHasCorrectProperties(): void
    {
        $result = new TranslatorResult(
            translatedText: 'Hallo Welt',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            translator: 'llm:openai',
            confidence: 0.95,
            metadata: ['key' => 'value'],
        );

        self::assertEquals('Hallo Welt', $result->translatedText);
        self::assertEquals('en', $result->sourceLanguage);
        self::assertEquals('de', $result->targetLanguage);
        self::assertEquals('llm:openai', $result->translator);
        self::assertEquals(0.95, $result->confidence);
        self::assertEquals(['key' => 'value'], $result->metadata);
    }

    #[Test]
    public function translatorResultWithDefaultValues(): void
    {
        $result = new TranslatorResult(
            translatedText: 'Text',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            translator: 'test',
        );

        self::assertNull($result->confidence);
        self::assertNull($result->metadata);
    }

    // ==================== TranslatorResult method tests ====================

    #[Test]
    public function translatorResultGetTextReturnsTranslatedText(): void
    {
        $result = new TranslatorResult(
            translatedText: 'Hello World',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            translator: 'test',
        );

        self::assertEquals('Hello World', $result->getText());
    }

    #[Test]
    public function translatorResultIsFromLlmReturnsTrueForLlmPrefixedTranslator(): void
    {
        $result = new TranslatorResult(
            translatedText: 'Text',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            translator: 'llm:openai',
        );

        self::assertTrue($result->isFromLlm());
    }

    #[Test]
    public function translatorResultIsFromLlmReturnsFalseForNonLlmTranslator(): void
    {
        $result = new TranslatorResult(
            translatedText: 'Text',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            translator: 'deepl',
        );

        self::assertFalse($result->isFromLlm());
    }

    #[Test]
    public function translatorResultIsFromDeepLReturnsTrueForDeepL(): void
    {
        $result = new TranslatorResult(
            translatedText: 'Text',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            translator: 'deepl',
        );

        self::assertTrue($result->isFromDeepL());
    }

    #[Test]
    public function translatorResultIsFromDeepLReturnsFalseForOthers(): void
    {
        $result = new TranslatorResult(
            translatedText: 'Text',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            translator: 'llm:openai',
        );

        self::assertFalse($result->isFromDeepL());
    }

    #[Test]
    public function translatorResultGetTranslatorNameReturnsLlmWithProvider(): void
    {
        $result = new TranslatorResult(
            translatedText: 'Text',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            translator: 'llm:openai',
        );

        self::assertEquals('LLM (openai)', $result->getTranslatorName());
    }

    #[Test]
    public function translatorResultGetTranslatorNameReturnsLlmWithDefaultForEmptyProvider(): void
    {
        $result = new TranslatorResult(
            translatedText: 'Text',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            translator: 'llm:',
        );

        self::assertEquals('LLM (default)', $result->getTranslatorName());
    }

    #[Test]
    public function translatorResultGetTranslatorNameReturnsUcfirstForNonLlm(): void
    {
        $result = new TranslatorResult(
            translatedText: 'Text',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            translator: 'deepl',
        );

        self::assertEquals('Deepl', $result->getTranslatorName());
    }

    #[Test]
    public function translatorResultHasAlternativesReturnsTrueWhenAlternativesExist(): void
    {
        $result = new TranslatorResult(
            translatedText: 'Text',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            translator: 'test',
            alternatives: ['Alt 1', 'Alt 2'],
        );

        self::assertTrue($result->hasAlternatives());
    }

    #[Test]
    public function translatorResultHasAlternativesReturnsFalseWhenNull(): void
    {
        $result = new TranslatorResult(
            translatedText: 'Text',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            translator: 'test',
        );

        self::assertFalse($result->hasAlternatives());
    }

    #[Test]
    public function translatorResultHasAlternativesReturnsFalseWhenEmpty(): void
    {
        $result = new TranslatorResult(
            translatedText: 'Text',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            translator: 'test',
            alternatives: [],
        );

        self::assertFalse($result->hasAlternatives());
    }

    #[Test]
    public function translatorResultGetConfidencePercentReturnsFormattedString(): void
    {
        $result = new TranslatorResult(
            translatedText: 'Text',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            translator: 'test',
            confidence: 0.856,
        );

        self::assertEquals('85.6%', $result->getConfidencePercent());
    }

    #[Test]
    public function translatorResultGetConfidencePercentReturnsNullWhenNoConfidence(): void
    {
        $result = new TranslatorResult(
            translatedText: 'Text',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            translator: 'test',
        );

        self::assertNull($result->getConfidencePercent());
    }

    // ==================== buildPrompt options tests ====================

    #[Test]
    public function translateWithFormalityOption(): void
    {
        $this->setResponse('Sehr geehrte Damen und Herren');

        $result = $this->subject->translate(
            'Dear Sir or Madam',
            'de',
            'en',
            ['formality' => 'formal', 'provider' => 'openai'],
        );

        self::assertInstanceOf(TranslatorResult::class, $result);
        self::assertEquals('Sehr geehrte Damen und Herren', $result->translatedText);
    }

    #[Test]
    public function translateWithDomainOption(): void
    {
        $this->setResponse('Medical translation');

        $result = $this->subject->translate(
            'Test medical text',
            'de',
            'en',
            ['domain' => 'medical', 'provider' => 'openai'],
        );

        self::assertInstanceOf(TranslatorResult::class, $result);
    }

    #[Test]
    public function translateWithGlossaryOption(): void
    {
        $this->setResponse('Translation with glossary');

        $result = $this->subject->translate(
            'Original text',
            'de',
            'en',
            ['glossary' => ['term1' => 'Begriff1', 'term2' => 'Begriff2'], 'provider' => 'openai'],
        );

        self::assertInstanceOf(TranslatorResult::class, $result);
    }

    #[Test]
    public function translateWithContextOption(): void
    {
        $this->setResponse('Contextual translation');

        $result = $this->subject->translate(
            'Text to translate',
            'de',
            'en',
            ['context' => 'This is an informal chat message', 'provider' => 'openai'],
        );

        self::assertInstanceOf(TranslatorResult::class, $result);
    }

    #[Test]
    public function translateWithPreserveFormattingFalse(): void
    {
        $this->setResponse('Plain text translation');

        $result = $this->subject->translate(
            '<p>Text with HTML</p>',
            'de',
            'en',
            ['preserve_formatting' => false, 'provider' => 'openai'],
        );

        self::assertInstanceOf(TranslatorResult::class, $result);
    }

    #[Test]
    public function translateWithAllOptions(): void
    {
        $this->setResponse('Comprehensive translation');

        $result = $this->subject->translate(
            'Complex text',
            'de',
            'en',
            [
                'formality' => 'informal',
                'domain' => 'technical',
                'glossary' => ['API' => 'Schnittstelle'],
                'context' => 'Technical documentation',
                'preserve_formatting' => true,
                'temperature' => 0.5,
                'max_tokens' => 1000,
                'provider' => 'openai',
                'model' => 'gpt-5.2',
            ],
        );

        self::assertInstanceOf(TranslatorResult::class, $result);
    }

    #[Test]
    public function translateWithNumericOptionsAsStrings(): void
    {
        $this->setResponse('Translation');

        // These should fall back to defaults because they're strings, not correct types
        $result = $this->subject->translate(
            'Text',
            'de',
            'en',
            [
                'temperature' => 'invalid',
                'max_tokens' => 'invalid',
                'provider' => 'openai',
            ],
        );

        self::assertInstanceOf(TranslatorResult::class, $result);
    }

    #[Test]
    public function translateWithUnknownLanguageUsesCodeDirectly(): void
    {
        $this->setResponse('Translated');

        $result = $this->subject->translate(
            'Hello',
            'xyz', // Unknown language code
            'abc', // Unknown language code
            ['provider' => 'openai'],
        );

        self::assertEquals('abc', $result->sourceLanguage);
        self::assertEquals('xyz', $result->targetLanguage);
    }

    // ==================== request-shape tests (messages + options) ====================

    /**
     * Stub the manager interface so every chat() call's messages AND options
     * are captured; answers are served from a queue of fixed responses (one
     * per expected chat() call).
     *
     * @param array<int, string> $responseContents
     *
     * @return array{0: LlmServiceManagerInterface&Stub, 1: object{messages: list<mixed>, options: array<int, ChatOptions>}}
     */
    private function createMessageCapturingManager(array $responseContents): array
    {
        $captured = new class {
            /** @var list<mixed> */
            public array $messages = [];
            /** @var array<int, ChatOptions> */
            public array $options = [];
        };

        $responses = [];
        foreach ($responseContents as $content) {
            $responses[] = new CompletionResponse(
                content: $content,
                model: 'gpt-5.2',
                usage: new UsageStatistics(100, 50, 150),
                finishReason: 'stop',
                provider: 'openai',
            );
        }

        $index = 0;
        $llmManager = self::createStub(LlmServiceManagerInterface::class);
        $llmManager
            ->method('chat')
            ->willReturnCallback(
                static function (array $messages, ?ChatOptions $options = null) use ($captured, $responses, &$index): CompletionResponse {
                    self::assertInstanceOf(ChatOptions::class, $options);
                    $captured->messages[] = $messages;
                    $captured->options[]  = $options;
                    $response = $responses[$index] ?? $responses[count($responses) - 1];
                    ++$index;

                    return $response;
                },
            );

        return [$llmManager, $captured];
    }

    /**
     * @param array<mixed> $messages
     */
    private function assertMessageRole(array $messages, int $index, string $expectedRole): void
    {
        $message = $messages[$index];
        self::assertIsArray($message);
        self::assertSame($expectedRole, $message['role'] ?? null);
    }

    /**
     * @param array<mixed> $messages
     */
    private function messageContentAt(array $messages, int $index): string
    {
        $message = $messages[$index];
        self::assertIsArray($message);
        $content = $message['content'] ?? null;
        self::assertIsString($content);

        return $content;
    }

    #[Test]
    public function translateSendsSystemAndUserMessages(): void
    {
        [$llmManager, $captured] = $this->createMessageCapturingManager(['Hallo Welt']);

        $subject = new LlmTranslator($llmManager, $this->usageTrackerStub);
        $subject->translate('Hello World', 'de', 'en', ['provider' => 'openai']);

        self::assertCount(1, $captured->messages);
        $messages = $captured->messages[0];
        self::assertIsArray($messages);
        self::assertCount(2, $messages);
        $this->assertMessageRole($messages, 0, 'system');
        $this->assertMessageRole($messages, 1, 'user');
        self::assertSame(
            "Translate this text:\n\n" . 'Hello World',
            $this->messageContentAt($messages, 1),
        );
    }

    #[Test]
    public function translateBuildsDefaultSystemPrompt(): void
    {
        [$llmManager, $captured] = $this->createMessageCapturingManager(['Hallo']);

        $subject = new LlmTranslator($llmManager, $this->usageTrackerStub);
        $subject->translate('Hello World', 'de', 'en', ['provider' => 'openai']);

        $messages = $captured->messages[0];
        self::assertIsArray($messages);
        $system = $this->messageContentAt($messages, 0);

        // Language names resolved from the code map, domain default 'general'.
        self::assertStringContainsString(
            'You are a professional general translator. Translate the following text from English to German.',
            $system,
        );
        // preserve_formatting defaults to true → the preserve line is present.
        self::assertStringContainsString(
            'Preserve all formatting, HTML tags, markdown, and special characters.',
            $system,
        );
        // The trailing instruction is appended, not overwriting the prompt.
        self::assertStringContainsString(
            'Provide ONLY the translation, no explanations or notes.',
            $system,
        );
        // formality defaults to 'default' → NO "Maintain … tone" line.
        self::assertStringNotContainsString('Maintain', $system);
    }

    #[Test]
    public function translateOmitsPreserveLineWhenPreserveFormattingFalse(): void
    {
        [$llmManager, $captured] = $this->createMessageCapturingManager(['Plain']);

        $subject = new LlmTranslator($llmManager, $this->usageTrackerStub);
        $subject->translate('<p>Text</p>', 'de', 'en', ['provider' => 'openai', 'preserve_formatting' => false]);

        $messages = $captured->messages[0];
        self::assertIsArray($messages);
        $system = $this->messageContentAt($messages, 0);

        self::assertStringNotContainsString('Preserve all formatting', $system);
    }

    #[Test]
    public function translateIncludesPreserveLineWhenPreserveFormattingExplicitlyTrue(): void
    {
        [$llmManager, $captured] = $this->createMessageCapturingManager(['Kept']);

        $subject = new LlmTranslator($llmManager, $this->usageTrackerStub);
        $subject->translate('Hello', 'de', 'en', ['provider' => 'openai', 'preserve_formatting' => true]);

        $messages = $captured->messages[0];
        self::assertIsArray($messages);
        $system = $this->messageContentAt($messages, 0);

        self::assertStringContainsString('You are a professional general translator', $system);
        self::assertStringContainsString('Preserve all formatting, HTML tags, markdown, and special characters.', $system);
    }

    #[Test]
    public function translateAddsFormalityLineToSystemPrompt(): void
    {
        [$llmManager, $captured] = $this->createMessageCapturingManager(['Formell']);

        $subject = new LlmTranslator($llmManager, $this->usageTrackerStub);
        $subject->translate('Hello', 'de', 'en', ['provider' => 'openai', 'formality' => 'formal']);

        $messages = $captured->messages[0];
        self::assertIsArray($messages);
        $system = $this->messageContentAt($messages, 0);

        self::assertStringContainsString('Maintain formal tone.', $system);
        // The line is appended (.=), not overwriting the professional intro.
        self::assertStringContainsString('You are a professional', $system);
    }

    #[Test]
    public function translateUsesDomainInSystemPrompt(): void
    {
        [$llmManager, $captured] = $this->createMessageCapturingManager(['Medizin']);

        $subject = new LlmTranslator($llmManager, $this->usageTrackerStub);
        $subject->translate('Hello', 'de', 'en', ['provider' => 'openai', 'domain' => 'medical']);

        $messages = $captured->messages[0];
        self::assertIsArray($messages);
        $system = $this->messageContentAt($messages, 0);

        self::assertStringContainsString('You are a professional medical translator', $system);
    }

    #[Test]
    public function translateRendersGlossaryTermsInSystemPrompt(): void
    {
        [$llmManager, $captured] = $this->createMessageCapturingManager(['Glossar']);

        $subject = new LlmTranslator($llmManager, $this->usageTrackerStub);
        $subject->translate(
            'Hello',
            'de',
            'en',
            ['provider' => 'openai', 'glossary' => ['term1' => 'Begriff1', 'term2' => 'Begriff2']],
        );

        $messages = $captured->messages[0];
        self::assertIsArray($messages);
        $system = $this->messageContentAt($messages, 0);

        self::assertStringContainsString('Use these exact term translations:', $system);
        self::assertStringContainsString('- term1 → Begriff1', $system);
        self::assertStringContainsString('- term2 → Begriff2', $system);
        // Header + terms are appended (.=), not overwriting the professional intro.
        self::assertStringContainsString('You are a professional', $system);
    }

    #[Test]
    public function translateRendersContextInSystemPrompt(): void
    {
        [$llmManager, $captured] = $this->createMessageCapturingManager(['Kontext']);

        $subject = new LlmTranslator($llmManager, $this->usageTrackerStub);
        $subject->translate('Hello', 'de', 'en', ['provider' => 'openai', 'context' => 'Informal chat message']);

        $messages = $captured->messages[0];
        self::assertIsArray($messages);
        $system = $this->messageContentAt($messages, 0);

        self::assertStringContainsString('Context (for reference only):', $system);
        self::assertStringContainsString('Informal chat message', $system);
        self::assertStringContainsString('You are a professional', $system);
    }

    #[Test]
    public function translateForwardsResolvedOptionsToChat(): void
    {
        [$llmManager, $captured] = $this->createMessageCapturingManager(['Hallo']);

        $subject = new LlmTranslator($llmManager, $this->usageTrackerStub);
        $subject->translate('Hello', 'de', 'en', ['provider' => 'openai', 'model' => 'gpt-5.2']);

        self::assertCount(1, $captured->options);
        $options = $captured->options[0];
        self::assertSame(2000, $options->getMaxTokens());
        self::assertSame(0.3, $options->getTemperature());
        self::assertSame('openai', $options->getProvider());
        self::assertSame('gpt-5.2', $options->getModel());
    }

    #[Test]
    public function translateKeepsZeroBeUserUidOnChatOptions(): void
    {
        // uid 0 is the "anonymous / skip budget" sentinel and must survive
        // (>= 0), not be coerced to null (> 0).
        [$llmManager, $captured] = $this->createMessageCapturingManager(['Hallo']);

        $subject = new LlmTranslator($llmManager, $this->usageTrackerStub);
        $subject->translate('Hello', 'de', 'en', ['provider' => 'openai', 'beUserUid' => 0]);

        self::assertSame(0, $captured->options[0]->getBeUserUid());
    }

    #[Test]
    public function translateResultTranslatorPinsResolvedProvider(): void
    {
        $this->setResponse('Hallo Welt');

        $result = $this->subject->translate('Hello World', 'de', 'en', ['provider' => 'openai']);

        self::assertSame('llm:openai', $result->translator);
    }

    #[Test]
    public function translateTracksUsageWithExactServiceAndMultibyteCharacterCount(): void
    {
        // 'Café!' is 5 characters but 6 bytes — mb_strlen (not strlen) must be
        // recorded; the service string must carry the resolved provider.
        $this->setResponse('Übersetzt');

        $usageTrackerMock = $this->createMock(UsageTrackerServiceInterface::class);
        $usageTrackerMock
            ->expects(self::once())
            ->method('trackUsage')
            ->with(
                'translation',
                'llm:openai',
                ['characters' => 5],
                null,
                0,
                'gpt-5.2',
                0,
                null,
            );

        $subject = new LlmTranslator($this->llmManager, $usageTrackerMock);
        $subject->translate('Café!', 'de', 'en', ['provider' => 'openai']);
    }

    #[Test]
    public function detectLanguageSendsExactDetectionMessages(): void
    {
        [$llmManager, $captured] = $this->createMessageCapturingManager(['de']);

        $subject = new LlmTranslator($llmManager, $this->usageTrackerStub);
        $subject->detectLanguage('Hallo Welt');

        self::assertCount(1, $captured->messages);
        $messages = $captured->messages[0];
        self::assertIsArray($messages);
        self::assertCount(2, $messages);
        $this->assertMessageRole($messages, 0, 'system');
        $this->assertMessageRole($messages, 1, 'user');
        self::assertSame(
            'You are a language detection expert. Respond with ONLY the ISO 639-1 language code (e.g., "en", "de", "fr"). No explanation.',
            $this->messageContentAt($messages, 0),
        );
        self::assertSame(
            "Detect the language of this text:\n\n" . 'Hallo Welt',
            $this->messageContentAt($messages, 1),
        );
    }

    #[Test]
    public function detectLanguageTruncatesTextToFiveHundredCharacters(): void
    {
        $longText = str_repeat('A', 600);

        [$llmManager, $captured] = $this->createMessageCapturingManager(['de']);

        $subject = new LlmTranslator($llmManager, $this->usageTrackerStub);
        $subject->detectLanguage($longText);

        $messages = $captured->messages[0];
        self::assertIsArray($messages);
        self::assertSame(
            "Detect the language of this text:\n\n" . substr($longText, 0, 500),
            $this->messageContentAt($messages, 1),
        );
    }

    #[Test]
    public function detectLanguageUsesTenTokenBudget(): void
    {
        [$llmManager, $captured] = $this->createMessageCapturingManager(['de']);

        $subject = new LlmTranslator($llmManager, $this->usageTrackerStub);
        $subject->detectLanguage('Hallo Welt');

        $options = $captured->options[0];
        self::assertSame(10, $options->getMaxTokens());
        self::assertSame(0.1, $options->getTemperature());
    }
}

/**
 * Test provider for LlmTranslator tests.
 */
class TranslatorTestProvider extends AbstractProvider
{
    public ?CompletionResponse $nextResponse = null;
    /** @var array<CompletionResponse> */
    public array $responseQueue = [];

    public function __construct()
    {
        // Skip parent constructor
    }

    public function getName(): string
    {
        return 'OpenAI';
    }

    public function getIdentifier(): string
    {
        return 'openai';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function chatCompletion(array $messages, array $options = []): CompletionResponse
    {
        if ($this->responseQueue !== []) {
            return array_shift($this->responseQueue);
        }

        return $this->nextResponse ?? new CompletionResponse(
            content: 'Default response',
            model: 'gpt-5.2',
            usage: new UsageStatistics(100, 50, 150),
            finishReason: 'stop',
            provider: 'openai',
        );
    }

    public function complete(string $prompt, array $options = []): CompletionResponse
    {
        return $this->chatCompletion([['role' => 'user', 'content' => $prompt]], $options);
    }

    public function embeddings(string|array $input, array $options = []): EmbeddingResponse
    {
        throw new LogicException('Not implemented', 1441271664);
    }

    protected function getDefaultBaseUrl(): string
    {
        return 'https://api.openai.com/v1';
    }

    /**
     * @return array<string, string>
     */
    public function getAvailableModels(): array
    {
        return ['gpt-5.2' => 'GPT 5.2', 'gpt-5.1' => 'GPT 5.1'];
    }
}
