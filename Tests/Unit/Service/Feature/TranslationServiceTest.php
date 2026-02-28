<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

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
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(TranslationService::class)]
class TranslationServiceTest extends AbstractUnitTestCase
{
    private LlmServiceManagerInterface&Stub $llmManagerStub;
    private TranslatorRegistryInterface&MockObject $translatorRegistryMock;
    private LlmConfigurationService&Stub $configServiceStub;
    private TranslationService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->llmManagerStub = self::createStub(LlmServiceManagerInterface::class);
        $this->translatorRegistryMock = $this->createMock(TranslatorRegistryInterface::class);
        $this->configServiceStub = self::createStub(LlmConfigurationService::class);

        $this->subject = new TranslationService(
            $this->llmManagerStub,
            $this->translatorRegistryMock,
            $this->configServiceStub,
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
        $this->llmManagerStub
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
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects(self::exactly(2))
            ->method('chat')
            ->willReturnOnConsecutiveCalls(
                $this->createChatResponse('en'),
                $this->createChatResponse('Hallo'),
            );

        $subject = new TranslationService(
            $llmManagerMock,
            $this->translatorRegistryMock,
            $this->configServiceStub,
        );

        $result = $subject->translate('Hello', 'de');

        self::assertEquals('en', $result->sourceLanguage);
    }

    #[Test]
    public function translateCalculatesConfidenceFromFinishReason(): void
    {
        $this->llmManagerStub
            ->method('chat')
            ->willReturn($this->createChatResponse('Translated', 'stop'));

        $result = $this->subject->translate('Text', 'de', 'en');

        self::assertEquals(0.9, $result->confidence);
    }

    #[Test]
    public function translateCalculatesLowerConfidenceForLengthFinishReason(): void
    {
        $this->llmManagerStub
            ->method('chat')
            ->willReturn($this->createChatResponse('Translated', 'length'));

        $result = $this->subject->translate('Text', 'de', 'en');

        self::assertEquals(0.6, $result->confidence);
    }

    #[Test]
    public function translateCalculatesDefaultConfidenceForUnknownFinishReason(): void
    {
        $this->llmManagerStub
            ->method('chat')
            ->willReturn($this->createChatResponse('Translated', 'unknown'));

        $result = $this->subject->translate('Text', 'de', 'en');

        self::assertEquals(0.5, $result->confidence);
    }

    #[Test]
    public function translateAcceptsLanguageCodeWithRegion(): void
    {
        $this->llmManagerStub
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
        $this->llmManagerStub
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
        $this->llmManagerStub
            ->method('chat')
            ->willReturn($this->createChatResponse('Translated'));

        $options = new TranslationOptions(domain: 'technical');

        $result = $this->subject->translate('Hello', 'de', 'en', $options);

        self::assertEquals('Translated', $result->translation);
    }

    #[Test]
    public function translateWithGlossary(): void
    {
        $this->llmManagerStub
            ->method('chat')
            ->willReturn($this->createChatResponse('Hallo Welt'));

        $options = new TranslationOptions(glossary: ['Hello' => 'Hallo']);

        $result = $this->subject->translate('Hello', 'de', 'en', $options);

        self::assertEquals('Hallo Welt', $result->translation);
    }

    #[Test]
    public function translateWithContext(): void
    {
        $this->llmManagerStub
            ->method('chat')
            ->willReturn($this->createChatResponse('Translated'));

        $options = new TranslationOptions(context: 'Software documentation');

        $result = $this->subject->translate('Hello', 'de', 'en', $options);

        self::assertEquals('Translated', $result->translation);
    }

    #[Test]
    public function translateWithPreserveFormattingFalse(): void
    {
        $this->llmManagerStub
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
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects(self::exactly(3))
            ->method('chat')
            ->willReturnOnConsecutiveCalls(
                $this->createChatResponse('Eins'),
                $this->createChatResponse('Zwei'),
                $this->createChatResponse('Drei'),
            );

        $subject = new TranslationService(
            $llmManagerMock,
            $this->translatorRegistryMock,
            $this->configServiceStub,
        );

        $result = $subject->translateBatch(['One', 'Two', 'Three'], 'de', 'en');

        self::assertCount(3, $result);
        self::assertEquals('Eins', $result[0]->translation);
        self::assertEquals('Zwei', $result[1]->translation);
        self::assertEquals('Drei', $result[2]->translation);
    }

    // ==================== detectLanguage tests ====================

    #[Test]
    public function detectLanguageReturnsDetectedCode(): void
    {
        $this->llmManagerStub
            ->method('chat')
            ->willReturn($this->createChatResponse('de'));

        $result = $this->subject->detectLanguage('Das ist ein Test');

        self::assertEquals('de', $result);
    }

    #[Test]
    public function detectLanguageTrimsAndLowercasesResult(): void
    {
        $this->llmManagerStub
            ->method('chat')
            ->willReturn($this->createChatResponse('  FR  '));

        $result = $this->subject->detectLanguage('Bonjour');

        self::assertEquals('fr', $result);
    }

    #[Test]
    public function detectLanguageFallsBackToEnglishForInvalidResponse(): void
    {
        $this->llmManagerStub
            ->method('chat')
            ->willReturn($this->createChatResponse('This is English text, not a code'));

        $result = $this->subject->detectLanguage('Test text');

        self::assertEquals('en', $result);
    }

    // ==================== scoreTranslationQuality tests ====================

    #[Test]
    public function scoreTranslationQualityReturnsScore(): void
    {
        $this->llmManagerStub
            ->method('chat')
            ->willReturn($this->createChatResponse('0.85'));

        $result = $this->subject->scoreTranslationQuality('Hello', 'Hallo', 'de');

        self::assertEquals(0.85, $result);
    }

    #[Test]
    public function scoreTranslationQualityClampsHighValues(): void
    {
        $this->llmManagerStub
            ->method('chat')
            ->willReturn($this->createChatResponse('1.5'));

        $result = $this->subject->scoreTranslationQuality('Hello', 'Hallo', 'de');

        self::assertEquals(1.0, $result);
    }

    #[Test]
    public function scoreTranslationQualityClampsLowValues(): void
    {
        $this->llmManagerStub
            ->method('chat')
            ->willReturn($this->createChatResponse('-0.5'));

        $result = $this->subject->scoreTranslationQuality('Hello', 'Hallo', 'de');

        self::assertEquals(0.0, $result);
    }

    // ==================== translateWithTranslator tests ====================

    #[Test]
    public function translateWithTranslatorUsesLlmByDefault(): void
    {
        $translatorStub = self::createStub(TranslatorInterface::class);
        $translatorStub
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
            ->willReturn($translatorStub);

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
        $translatorStub = self::createStub(TranslatorInterface::class);
        $translatorStub
            ->method('translateBatch')
            ->willReturn([
                new TranslatorResult('Eins', 'en', 'de', 'llm:openai'),
                new TranslatorResult('Zwei', 'en', 'de', 'llm:openai'),
            ]);

        $this->translatorRegistryMock
            ->method('get')
            ->willReturn($translatorStub);

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
        $translatorStub = self::createStub(TranslatorInterface::class);

        $this->translatorRegistryMock
            ->method('get')
            ->with('deepl')
            ->willReturn($translatorStub);

        $result = $this->subject->getTranslator('deepl');

        self::assertSame($translatorStub, $result);
    }

    #[Test]
    public function findBestTranslatorDelegatesToRegistry(): void
    {
        $translatorStub = self::createStub(TranslatorInterface::class);

        $this->translatorRegistryMock
            ->method('findBestTranslator')
            ->with('en', 'de')
            ->willReturn($translatorStub);

        $result = $this->subject->findBestTranslator('en', 'de');

        self::assertSame($translatorStub, $result);
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
        $this->llmManagerStub
            ->method('chat')
            ->willReturn($this->createChatResponse('Translated'));

        $options = TranslationOptions::formal();

        $result = $this->subject->translate('Hello', 'de', 'en', $options);

        self::assertEquals('Translated', $result->translation);
    }

    #[Test]
    public function translateWithInformalPreset(): void
    {
        $this->llmManagerStub
            ->method('chat')
            ->willReturn($this->createChatResponse('Translated'));

        $options = TranslationOptions::informal();

        $result = $this->subject->translate('Hello', 'de', 'en', $options);

        self::assertEquals('Translated', $result->translation);
    }

    #[Test]
    public function translateWithTechnicalPreset(): void
    {
        $this->llmManagerStub
            ->method('chat')
            ->willReturn($this->createChatResponse('Translated'));

        $options = TranslationOptions::technical();

        $result = $this->subject->translate('Hello', 'de', 'en', $options);

        self::assertEquals('Translated', $result->translation);
    }

    #[Test]
    public function translateWithMedicalPreset(): void
    {
        $this->llmManagerStub
            ->method('chat')
            ->willReturn($this->createChatResponse('Translated'));

        $options = TranslationOptions::medical();

        $result = $this->subject->translate('Hello', 'de', 'en', $options);

        self::assertEquals('Translated', $result->translation);
    }

    #[Test]
    public function translateWithMarketingPreset(): void
    {
        $this->llmManagerStub
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
        $translatorStub = self::createStub(TranslatorInterface::class);
        $translatorStub
            ->method('translate')
            ->willReturn(new TranslatorResult(
                translatedText: 'Translated',
                sourceLanguage: 'auto',
                targetLanguage: 'de',
                translator: 'llm:openai',
            ));

        $this->translatorRegistryMock
            ->method('get')
            ->willReturn($translatorStub);

        $result = $this->subject->translateWithTranslator('Hello', 'de');

        self::assertEquals('Translated', $result->translatedText);
    }

    #[Test]
    public function translateBatchWithOptionsPassesOptions(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects(self::exactly(2))
            ->method('chat')
            ->willReturnOnConsecutiveCalls(
                $this->createChatResponse('Eins'),
                $this->createChatResponse('Zwei'),
            );

        $subject = new TranslationService(
            $llmManagerMock,
            $this->translatorRegistryMock,
            $this->configServiceStub,
        );

        $options = new TranslationOptions(formality: 'formal');

        $result = $subject->translateBatch(['One', 'Two'], 'de', 'en', $options);

        self::assertCount(2, $result);
    }

    #[Test]
    public function translateWithGlossaryFiltersNonScalarValues(): void
    {
        $this->llmManagerStub
            ->method('chat')
            ->willReturn($this->createChatResponse('Translated'));

        // Glossary - string values only
        $options = new TranslationOptions(glossary: [
            'Hello' => 'Hallo',
            'World' => 'Welt',
            'Test' => 'Test',
        ]);

        $result = $this->subject->translate('Hello World Test', 'de', 'en', $options);

        self::assertEquals('Translated', $result->translation);
    }

    #[Test]
    public function detectLanguageWithOptions(): void
    {
        $this->llmManagerStub
            ->method('chat')
            ->willReturn($this->createChatResponse('fr'));

        $options = new TranslationOptions(provider: 'claude');

        $result = $this->subject->detectLanguage('Bonjour le monde', $options);

        self::assertEquals('fr', $result);
    }

    #[Test]
    public function scoreTranslationQualityWithOptions(): void
    {
        $this->llmManagerStub
            ->method('chat')
            ->willReturn($this->createChatResponse('0.95'));

        $options = new TranslationOptions(provider: 'claude');

        $result = $this->subject->scoreTranslationQuality('Hello', 'Hallo', 'de', $options);

        self::assertEquals(0.95, $result);
    }

    #[Test]
    public function translateWithCustomTemperatureAndMaxTokens(): void
    {
        $this->llmManagerStub
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
        $this->llmManagerStub
            ->method('chat')
            ->willReturn($this->createChatResponse('Translated'));

        $options = new TranslationOptions(domain: 'legal');

        $result = $this->subject->translate('Hello', 'de', 'en', $options);

        self::assertEquals('Translated', $result->translation);
    }

    // ==================== additional coverage tests ====================

    #[Test]
    public function translateWithAllSupportedDomains(): void
    {
        $this->llmManagerStub
            ->method('chat')
            ->willReturn($this->createChatResponse('Translated'));

        // Test medical domain (another supported domain)
        $options = new TranslationOptions(domain: 'medical');
        $result = $this->subject->translate('Hello', 'de', 'en', $options);
        self::assertEquals('Translated', $result->translation);
    }

    #[Test]
    public function translateWithNumericGlossaryValues(): void
    {
        $this->llmManagerStub
            ->method('chat')
            ->willReturn($this->createChatResponse('Translated'));

        // Glossary with mixed value types - only strings should work
        $options = new TranslationOptions(glossary: [
            'version' => '2.0',
            'api' => 'Schnittstelle',
        ]);

        $result = $this->subject->translate('API version', 'de', 'en', $options);
        self::assertEquals('Translated', $result->translation);
    }

    #[Test]
    public function translateWithEmptyGlossary(): void
    {
        $this->llmManagerStub
            ->method('chat')
            ->willReturn($this->createChatResponse('Translated'));

        $options = new TranslationOptions(glossary: []);

        $result = $this->subject->translate('Hello', 'de', 'en', $options);
        self::assertEquals('Translated', $result->translation);
    }

    #[Test]
    public function translateBatchWithTranslatorOptions(): void
    {
        $translatorStub = self::createStub(TranslatorInterface::class);
        $translatorStub
            ->method('translateBatch')
            ->willReturn([
                new TranslatorResult('Eins', 'en', 'de', 'llm:openai'),
                new TranslatorResult('Zwei', 'en', 'de', 'llm:openai'),
            ]);

        $this->translatorRegistryMock
            ->method('get')
            ->willReturn($translatorStub);

        $options = new TranslationOptions(formality: 'formal');

        $result = $this->subject->translateBatchWithTranslator(['One', 'Two'], 'de', 'en', $options);

        self::assertCount(2, $result);
    }

    // ==================== getLanguageName tests ====================

    #[Test]
    public function translateHandlesUnknownLanguageCode(): void
    {
        $this->llmManagerStub
            ->method('chat')
            ->willReturn($this->createChatResponse('Translated'));

        // Use a language code not in the predefined list
        $result = $this->subject->translate('Hello', 'sw', 'tl');

        // Should still work - unknown codes should be used as-is
        self::assertEquals('Translated', $result->translation);
        self::assertEquals('tl', $result->sourceLanguage);
        self::assertEquals('sw', $result->targetLanguage);
    }
}
