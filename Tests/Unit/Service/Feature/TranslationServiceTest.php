<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Feature;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Feature\TranslationService;
use Netresearch\NrLlm\Service\LlmConfigurationService;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\TranslationOptions;
use Netresearch\NrLlm\Specialized\Translation\TranslatorInterface;
use Netresearch\NrLlm\Specialized\Translation\TranslatorRegistryInterface;
use Netresearch\NrLlm\Specialized\Translation\TranslatorResult;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;

#[CoversClass(TranslationService::class)]
class TranslationServiceTest extends AbstractUnitTestCase
{
    private LlmServiceManagerInterface&MockObject $llmManagerMock;
    private TranslatorRegistryInterface&MockObject $translatorRegistryMock;
    private LlmConfigurationService&MockObject $configServiceMock;
    private TranslationService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $this->translatorRegistryMock = $this->createMock(TranslatorRegistryInterface::class);
        $this->configServiceMock = $this->createMock(LlmConfigurationService::class);

        $this->subject = new TranslationService(
            $this->llmManagerMock,
            $this->translatorRegistryMock,
            $this->configServiceMock,
        );
    }

    private function createChatResponse(string $content, string $finishReason = 'stop'): CompletionResponse
    {
        return new CompletionResponse(
            content: $content,
            model: 'gpt-5.2',
            usage: new UsageStatistics(100, 50, 150),
            finishReason: $finishReason,
            provider: 'openai',
        );
    }

    // ==================== translate tests ====================

    #[Test]
    public function translateReturnsTranslationResult(): void
    {
        $this->llmManagerMock
            ->method('chat')
            ->willReturn($this->createChatResponse('Hallo Welt'));

        $result = $this->subject->translate('Hello World', 'de', 'en');

        self::assertEquals('Hallo Welt', $result->translation);
        self::assertEquals('en', $result->sourceLanguage);
        self::assertEquals('de', $result->targetLanguage);
    }

    #[Test]
    public function translateThrowsOnEmptyText(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1478981390);

        $this->subject->translate('', 'de', 'en');
    }

    #[Test]
    public function translateThrowsOnInvalidTargetLanguageCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(8727807751);

        $this->subject->translate('Hello', 'invalid', 'en');
    }

    #[Test]
    public function translateThrowsOnInvalidSourceLanguageCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(8727807751);

        $this->subject->translate('Hello', 'de', 'invalid');
    }

    #[Test]
    public function translateAutoDetectsSourceLanguage(): void
    {
        $this->llmManagerMock
            ->expects(self::exactly(2))
            ->method('chat')
            ->willReturnOnConsecutiveCalls(
                $this->createChatResponse('en'),
                $this->createChatResponse('Hallo'),
            );

        $result = $this->subject->translate('Hello', 'de');

        self::assertEquals('en', $result->sourceLanguage);
    }

    #[Test]
    public function translateCalculatesConfidenceFromFinishReason(): void
    {
        $this->llmManagerMock
            ->method('chat')
            ->willReturn($this->createChatResponse('Translated', 'stop'));

        $result = $this->subject->translate('Text', 'de', 'en');

        self::assertEquals(0.9, $result->confidence);
    }

    #[Test]
    public function translateCalculatesLowerConfidenceForLengthFinishReason(): void
    {
        $this->llmManagerMock
            ->method('chat')
            ->willReturn($this->createChatResponse('Translated', 'length'));

        $result = $this->subject->translate('Text', 'de', 'en');

        self::assertEquals(0.6, $result->confidence);
    }

    #[Test]
    public function translateCalculatesDefaultConfidenceForUnknownFinishReason(): void
    {
        $this->llmManagerMock
            ->method('chat')
            ->willReturn($this->createChatResponse('Translated', 'unknown'));

        $result = $this->subject->translate('Text', 'de', 'en');

        self::assertEquals(0.5, $result->confidence);
    }

    #[Test]
    public function translateAcceptsLanguageCodeWithRegion(): void
    {
        $this->llmManagerMock
            ->method('chat')
            ->willReturn($this->createChatResponse('Translated'));

        $result = $this->subject->translate('Hello', 'de-DE', 'en-US');

        self::assertEquals('en-US', $result->sourceLanguage);
        self::assertEquals('de-DE', $result->targetLanguage);
    }

    // ==================== translate with options tests ====================

    #[Test]
    public function translateThrowsOnInvalidFormalityViaConstructor(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TranslationOptions(formality: 'invalid');
    }

    #[Test]
    public function translateAcceptsValidFormality(): void
    {
        $this->llmManagerMock
            ->method('chat')
            ->willReturn($this->createChatResponse('Translated'));

        $options = new TranslationOptions(formality: 'formal');

        $result = $this->subject->translate('Hello', 'de', 'en', $options);

        self::assertEquals('Translated', $result->translation);
    }

    #[Test]
    public function translateThrowsOnInvalidDomainViaConstructor(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TranslationOptions(domain: 'invalid');
    }

    #[Test]
    public function translateAcceptsValidDomain(): void
    {
        $this->llmManagerMock
            ->method('chat')
            ->willReturn($this->createChatResponse('Translated'));

        $options = new TranslationOptions(domain: 'technical');

        $result = $this->subject->translate('Hello', 'de', 'en', $options);

        self::assertEquals('Translated', $result->translation);
    }

    #[Test]
    public function translateWithGlossary(): void
    {
        $this->llmManagerMock
            ->method('chat')
            ->willReturn($this->createChatResponse('Hallo Welt'));

        $options = new TranslationOptions(glossary: ['Hello' => 'Hallo']);

        $result = $this->subject->translate('Hello', 'de', 'en', $options);

        self::assertEquals('Hallo Welt', $result->translation);
    }

    #[Test]
    public function translateWithContext(): void
    {
        $this->llmManagerMock
            ->method('chat')
            ->willReturn($this->createChatResponse('Translated'));

        $options = new TranslationOptions(context: 'Software documentation');

        $result = $this->subject->translate('Hello', 'de', 'en', $options);

        self::assertEquals('Translated', $result->translation);
    }

    #[Test]
    public function translateWithPreserveFormattingFalse(): void
    {
        $this->llmManagerMock
            ->method('chat')
            ->willReturn($this->createChatResponse('Translated'));

        $options = new TranslationOptions(preserveFormatting: false);

        $result = $this->subject->translate('<b>Hello</b>', 'de', 'en', $options);

        self::assertEquals('Translated', $result->translation);
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
        $this->llmManagerMock
            ->expects(self::exactly(3))
            ->method('chat')
            ->willReturnOnConsecutiveCalls(
                $this->createChatResponse('Eins'),
                $this->createChatResponse('Zwei'),
                $this->createChatResponse('Drei'),
            );

        $result = $this->subject->translateBatch(['One', 'Two', 'Three'], 'de', 'en');

        self::assertCount(3, $result);
        self::assertEquals('Eins', $result[0]->translation);
        self::assertEquals('Zwei', $result[1]->translation);
        self::assertEquals('Drei', $result[2]->translation);
    }

    // ==================== detectLanguage tests ====================

    #[Test]
    public function detectLanguageReturnsDetectedCode(): void
    {
        $this->llmManagerMock
            ->method('chat')
            ->willReturn($this->createChatResponse('de'));

        $result = $this->subject->detectLanguage('Das ist ein Test');

        self::assertEquals('de', $result);
    }

    #[Test]
    public function detectLanguageTrimsAndLowercasesResult(): void
    {
        $this->llmManagerMock
            ->method('chat')
            ->willReturn($this->createChatResponse('  FR  '));

        $result = $this->subject->detectLanguage('Bonjour');

        self::assertEquals('fr', $result);
    }

    #[Test]
    public function detectLanguageFallsBackToEnglishForInvalidResponse(): void
    {
        $this->llmManagerMock
            ->method('chat')
            ->willReturn($this->createChatResponse('This is English text, not a code'));

        $result = $this->subject->detectLanguage('Test text');

        self::assertEquals('en', $result);
    }

    // ==================== scoreTranslationQuality tests ====================

    #[Test]
    public function scoreTranslationQualityReturnsScore(): void
    {
        $this->llmManagerMock
            ->method('chat')
            ->willReturn($this->createChatResponse('0.85'));

        $result = $this->subject->scoreTranslationQuality('Hello', 'Hallo', 'de');

        self::assertEquals(0.85, $result);
    }

    #[Test]
    public function scoreTranslationQualityClampsHighValues(): void
    {
        $this->llmManagerMock
            ->method('chat')
            ->willReturn($this->createChatResponse('1.5'));

        $result = $this->subject->scoreTranslationQuality('Hello', 'Hallo', 'de');

        self::assertEquals(1.0, $result);
    }

    #[Test]
    public function scoreTranslationQualityClampsLowValues(): void
    {
        $this->llmManagerMock
            ->method('chat')
            ->willReturn($this->createChatResponse('-0.5'));

        $result = $this->subject->scoreTranslationQuality('Hello', 'Hallo', 'de');

        self::assertEquals(0.0, $result);
    }

    // ==================== translateWithTranslator tests ====================

    #[Test]
    public function translateWithTranslatorUsesLlmByDefault(): void
    {
        $translatorMock = $this->createMock(TranslatorInterface::class);
        $translatorMock
            ->method('translate')
            ->willReturn(new TranslatorResult(
                translatedText: 'Hallo Welt',
                sourceLanguage: 'en',
                targetLanguage: 'de',
                translator: 'llm:openai',
            ));

        $this->translatorRegistryMock
            ->method('get')
            ->with('llm')
            ->willReturn($translatorMock);

        $result = $this->subject->translateWithTranslator('Hello World', 'de', 'en');

        self::assertEquals('Hallo Welt', $result->translatedText);
        self::assertEquals('llm:openai', $result->translator);
    }

    #[Test]
    public function translateWithTranslatorThrowsOnEmptyText(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(3459949413);

        $this->subject->translateWithTranslator('', 'de', 'en');
    }

    #[Test]
    public function translateWithTranslatorValidatesTargetLanguage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(8727807751);

        $this->subject->translateWithTranslator('Hello', 'invalid', 'en');
    }

    #[Test]
    public function translateWithTranslatorValidatesSourceLanguage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(8727807751);

        $this->subject->translateWithTranslator('Hello', 'de', 'invalid');
    }

    // ==================== translateBatchWithTranslator tests ====================

    #[Test]
    public function translateBatchWithTranslatorReturnsEmptyArrayForEmptyInput(): void
    {
        $result = $this->subject->translateBatchWithTranslator([], 'de');

        self::assertEmpty($result);
    }

    #[Test]
    public function translateBatchWithTranslatorCallsTranslatorBatch(): void
    {
        $translatorMock = $this->createMock(TranslatorInterface::class);
        $translatorMock
            ->method('translateBatch')
            ->willReturn([
                new TranslatorResult('Eins', 'en', 'de', 'llm:openai'),
                new TranslatorResult('Zwei', 'en', 'de', 'llm:openai'),
            ]);

        $this->translatorRegistryMock
            ->method('get')
            ->willReturn($translatorMock);

        $result = $this->subject->translateBatchWithTranslator(['One', 'Two'], 'de', 'en');

        self::assertCount(2, $result);
    }

    // ==================== Registry delegation tests ====================

    #[Test]
    public function getAvailableTranslatorsReturnsFromRegistry(): void
    {
        $info = [
            'llm' => ['identifier' => 'llm', 'name' => 'LLM', 'available' => true],
            'deepl' => ['identifier' => 'deepl', 'name' => 'DeepL', 'available' => false],
        ];

        $this->translatorRegistryMock
            ->method('getTranslatorInfo')
            ->willReturn($info);

        $result = $this->subject->getAvailableTranslators();

        self::assertEquals($info, $result);
    }

    #[Test]
    public function hasTranslatorDelegatesToRegistry(): void
    {
        $this->translatorRegistryMock
            ->method('has')
            ->with('deepl')
            ->willReturn(true);

        self::assertTrue($this->subject->hasTranslator('deepl'));
    }

    #[Test]
    public function getTranslatorDelegatesToRegistry(): void
    {
        $translatorMock = $this->createMock(TranslatorInterface::class);

        $this->translatorRegistryMock
            ->method('get')
            ->with('deepl')
            ->willReturn($translatorMock);

        $result = $this->subject->getTranslator('deepl');

        self::assertSame($translatorMock, $result);
    }

    #[Test]
    public function findBestTranslatorDelegatesToRegistry(): void
    {
        $translatorMock = $this->createMock(TranslatorInterface::class);

        $this->translatorRegistryMock
            ->method('findBestTranslator')
            ->with('en', 'de')
            ->willReturn($translatorMock);

        $result = $this->subject->findBestTranslator('en', 'de');

        self::assertSame($translatorMock, $result);
    }

    #[Test]
    public function findBestTranslatorReturnsNullWhenNoneFound(): void
    {
        $this->translatorRegistryMock
            ->method('findBestTranslator')
            ->willReturn(null);

        $result = $this->subject->findBestTranslator('xx', 'yy');

        self::assertNull($result);
    }

    // ==================== Factory presets tests ====================

    #[Test]
    public function translateWithFormalPreset(): void
    {
        $this->llmManagerMock
            ->method('chat')
            ->willReturn($this->createChatResponse('Translated'));

        $options = TranslationOptions::formal();

        $result = $this->subject->translate('Hello', 'de', 'en', $options);

        self::assertEquals('Translated', $result->translation);
    }

    #[Test]
    public function translateWithInformalPreset(): void
    {
        $this->llmManagerMock
            ->method('chat')
            ->willReturn($this->createChatResponse('Translated'));

        $options = TranslationOptions::informal();

        $result = $this->subject->translate('Hello', 'de', 'en', $options);

        self::assertEquals('Translated', $result->translation);
    }

    #[Test]
    public function translateWithTechnicalPreset(): void
    {
        $this->llmManagerMock
            ->method('chat')
            ->willReturn($this->createChatResponse('Translated'));

        $options = TranslationOptions::technical();

        $result = $this->subject->translate('Hello', 'de', 'en', $options);

        self::assertEquals('Translated', $result->translation);
    }

    #[Test]
    public function translateWithMedicalPreset(): void
    {
        $this->llmManagerMock
            ->method('chat')
            ->willReturn($this->createChatResponse('Translated'));

        $options = TranslationOptions::medical();

        $result = $this->subject->translate('Hello', 'de', 'en', $options);

        self::assertEquals('Translated', $result->translation);
    }

    #[Test]
    public function translateWithMarketingPreset(): void
    {
        $this->llmManagerMock
            ->method('chat')
            ->willReturn($this->createChatResponse('Translated'));

        $options = TranslationOptions::marketing();

        $result = $this->subject->translate('Hello', 'de', 'en', $options);

        self::assertEquals('Translated', $result->translation);
    }

    // ==================== translateWithTranslator additional tests ====================

    #[Test]
    public function translateWithTranslatorWithNullSourceLanguage(): void
    {
        $translatorMock = $this->createMock(TranslatorInterface::class);
        $translatorMock
            ->method('translate')
            ->willReturn(new TranslatorResult(
                translatedText: 'Translated',
                sourceLanguage: 'auto',
                targetLanguage: 'de',
                translator: 'llm:openai',
            ));

        $this->translatorRegistryMock
            ->method('get')
            ->willReturn($translatorMock);

        $result = $this->subject->translateWithTranslator('Hello', 'de');

        self::assertEquals('Translated', $result->translatedText);
    }

    #[Test]
    public function translateBatchWithOptionsPassesOptions(): void
    {
        $this->llmManagerMock
            ->expects(self::exactly(2))
            ->method('chat')
            ->willReturnOnConsecutiveCalls(
                $this->createChatResponse('Eins'),
                $this->createChatResponse('Zwei'),
            );

        $options = new TranslationOptions(formality: 'formal');

        $result = $this->subject->translateBatch(['One', 'Two'], 'de', 'en', $options);

        self::assertCount(2, $result);
    }

    #[Test]
    public function translateWithGlossaryFiltersNonScalarValues(): void
    {
        $this->llmManagerMock
            ->method('chat')
            ->willReturn($this->createChatResponse('Translated'));

        // Glossary with non-scalar value (array) - should be skipped
        $options = new TranslationOptions(glossary: [
            'Hello' => 'Hallo',
            'World' => ['nested' => 'array'], // This should be skipped
            'Test' => 123, // Numeric value should work
        ]);

        $result = $this->subject->translate('Hello World Test', 'de', 'en', $options);

        self::assertEquals('Translated', $result->translation);
    }

    #[Test]
    public function detectLanguageWithOptions(): void
    {
        $this->llmManagerMock
            ->method('chat')
            ->willReturn($this->createChatResponse('fr'));

        $options = new TranslationOptions(provider: 'claude');

        $result = $this->subject->detectLanguage('Bonjour le monde', $options);

        self::assertEquals('fr', $result);
    }

    #[Test]
    public function scoreTranslationQualityWithOptions(): void
    {
        $this->llmManagerMock
            ->method('chat')
            ->willReturn($this->createChatResponse('0.95'));

        $options = new TranslationOptions(provider: 'claude');

        $result = $this->subject->scoreTranslationQuality('Hello', 'Hallo', 'de', $options);

        self::assertEquals(0.95, $result);
    }

    #[Test]
    public function translateWithCustomTemperatureAndMaxTokens(): void
    {
        $this->llmManagerMock
            ->method('chat')
            ->willReturn($this->createChatResponse('Translated'));

        $options = new TranslationOptions(
            temperature: 0.5,
            maxTokens: 1000,
        );

        $result = $this->subject->translate('Hello', 'de', 'en', $options);

        self::assertEquals('Translated', $result->translation);
    }

    #[Test]
    public function translateWithLegalDomain(): void
    {
        $this->llmManagerMock
            ->method('chat')
            ->willReturn($this->createChatResponse('Translated'));

        $options = new TranslationOptions(domain: 'legal');

        $result = $this->subject->translate('Hello', 'de', 'en', $options);

        self::assertEquals('Translated', $result->translation);
    }
}
