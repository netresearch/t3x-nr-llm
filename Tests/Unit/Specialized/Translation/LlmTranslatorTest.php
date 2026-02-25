<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Translation;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Provider\AbstractProvider;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Specialized\Translation\LlmTranslator;
use Netresearch\NrLlm\Specialized\Translation\TranslatorResult;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\LoggerInterface;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(LlmTranslator::class)]
#[CoversClass(TranslatorResult::class)]
class LlmTranslatorTest extends AbstractUnitTestCase
{
    private LlmServiceManager $llmManager;
    private UsageTrackerServiceInterface&Stub $usageTrackerStub;
    private LlmTranslator $subject;
    private TranslatorTestProvider $provider;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $extensionConfigStub = self::createStub(ExtensionConfiguration::class);
        $extensionConfigStub
            ->method('get')
            ->willReturn([
                'defaultProvider' => 'openai',
                'providers' => [],
            ]);

        $loggerStub = self::createStub(LoggerInterface::class);
        $adapterRegistryStub = self::createStub(ProviderAdapterRegistry::class);

        $this->llmManager = new LlmServiceManager(
            $extensionConfigStub,
            $loggerStub,
            $adapterRegistryStub,
        );

        $this->provider = new TranslatorTestProvider();
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

        $result = $this->subject->translate('Hello World', 'de', 'en');

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
                self::callback(fn($data) => is_array($data) && isset($data['tokens'], $data['characters'])),
            );

        $subject = new LlmTranslator(
            $this->llmManager,
            $usageTrackerMock,
        );

        $subject->translate('Test text', 'de', 'en');
    }

    #[Test]
    public function translateCalculatesHighConfidenceForStopFinishReason(): void
    {
        $this->setResponse('Result', 'stop');

        $result = $this->subject->translate('Test', 'de', 'en');

        self::assertEquals(0.9, $result->confidence);
    }

    #[Test]
    public function translateCalculatesLowerConfidenceForLengthFinishReason(): void
    {
        $this->setResponse('Result', 'length');

        $result = $this->subject->translate('Test', 'de', 'en');

        self::assertEquals(0.6, $result->confidence);
    }

    #[Test]
    public function translateCalculatesDefaultConfidenceForOtherFinishReasons(): void
    {
        $this->setResponse('Result', 'unknown');

        $result = $this->subject->translate('Test', 'de', 'en');

        self::assertEquals(0.5, $result->confidence);
    }

    #[Test]
    public function translateIncludesMetadata(): void
    {
        $this->setResponse('Translated');

        $result = $this->subject->translate('Test', 'de', 'en');

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

        $result = $this->subject->translate('Das ist ein Test', 'en');

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

        $result = $this->subject->translateBatch(['Text 1', 'Text 2', 'Text 3'], 'de', 'en');

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
            ['formality' => 'formal'],
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
            ['domain' => 'medical'],
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
            ['glossary' => ['term1' => 'Begriff1', 'term2' => 'Begriff2']],
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
            ['context' => 'This is an informal chat message'],
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
            ['preserve_formatting' => false],
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
        );

        self::assertEquals('abc', $result->sourceLanguage);
        self::assertEquals('xyz', $result->targetLanguage);
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

    #[Override]
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

    #[Override]
    public function complete(string $prompt, array $options = []): CompletionResponse
    {
        return $this->chatCompletion([['role' => 'user', 'content' => $prompt]], $options);
    }

    public function embeddings(string|array $input, array $options = []): EmbeddingResponse
    {
        throw new RuntimeException('Not implemented', 1441271664);
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
