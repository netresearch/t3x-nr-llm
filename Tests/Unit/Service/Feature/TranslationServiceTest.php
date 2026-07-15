<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Feature;

use Closure;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Exception\ConfigurationNotFoundException;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Budget\BackendUserContextResolverInterface;
use Netresearch\NrLlm\Service\Feature\TranslationService;
use Netresearch\NrLlm\Service\LlmConfigurationServiceInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
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
use ReflectionClass;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(TranslationService::class)]
class TranslationServiceTest extends AbstractUnitTestCase
{
    private LlmServiceManagerInterface&Stub $llmManagerStub;
    private TranslatorRegistryInterface&MockObject $translatorRegistryMock;
    private LlmConfigurationServiceInterface&Stub $configServiceStub;
    private TranslationService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->llmManagerStub = self::createStub(LlmServiceManagerInterface::class);
        $this->translatorRegistryMock = $this->createMock(TranslatorRegistryInterface::class);
        $this->configServiceStub = self::createStub(LlmConfigurationServiceInterface::class);

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

        self::assertInstanceOf(TranslationOptions::class, new TranslationOptions(formality: 'invalid'));
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

        self::assertInstanceOf(TranslationOptions::class, new TranslationOptions(domain: 'invalid'));
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
            ->expects(self::once())->method('get')
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
            ->expects(self::once())->method('has')
            ->with('deepl')
            ->willReturn(true);

        self::assertTrue($this->subject->hasTranslator('deepl'));
    }

    #[Test]
    public function getTranslatorDelegatesToRegistry(): void
    {
        $translatorStub = self::createStub(TranslatorInterface::class);

        $this->translatorRegistryMock
            ->expects(self::once())->method('get')
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
            ->expects(self::once())->method('findBestTranslator')
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

    // ==================== resolveTranslator preset path tests ====================

    #[Test]
    public function resolveTranslatorUsesExplicitTranslatorKeyInOptions(): void
    {
        $translatorStub = self::createStub(TranslatorInterface::class);
        $translatorStub
            ->method('translate')
            ->willReturn(new TranslatorResult(
                translatedText: 'Tiefe Übersetzung',
                sourceLanguage: 'en',
                targetLanguage: 'de',
                translator: 'deepl',
            ));

        $this->translatorRegistryMock
            ->expects(self::once())
            ->method('get')
            ->with('deepl')
            ->willReturn($translatorStub);

        // Use reflection to call resolveTranslator directly with 'translator' key
        $reflection = new ReflectionClass($this->subject);
        $method = $reflection->getMethod('resolveTranslator');
        $result = $method->invoke($this->subject, ['translator' => 'deepl']);

        self::assertSame($translatorStub, $result);
    }

    #[Test]
    public function resolveTranslatorUsesPresetTranslatorWhenConfigurationFound(): void
    {
        $configServiceMock = $this->createMock(LlmConfigurationServiceInterface::class);
        $translatorRegistryMock = $this->createMock(TranslatorRegistryInterface::class);

        $configurationStub = self::createStub(LlmConfiguration::class);
        $configurationStub->method('getTranslator')->willReturn('deepl');

        $configServiceMock
            ->expects(self::once())->method('getConfiguration')
            ->with('my-preset')
            ->willReturn($configurationStub);

        $translatorStub = self::createStub(TranslatorInterface::class);

        $translatorRegistryMock
            ->expects(self::once())
            ->method('get')
            ->with('deepl')
            ->willReturn($translatorStub);

        $subject = new TranslationService(
            $this->llmManagerStub,
            $translatorRegistryMock,
            $configServiceMock,
        );

        $reflection = new ReflectionClass($subject);
        $method = $reflection->getMethod('resolveTranslator');
        $result = $method->invoke($subject, ['preset' => 'my-preset']);

        self::assertSame($translatorStub, $result);
    }

    #[Test]
    public function resolveTranslatorFallsBackToLlmWhenPresetConfigurationNotFound(): void
    {
        $configServiceMock = $this->createMock(LlmConfigurationServiceInterface::class);
        $translatorRegistryMock = $this->createMock(TranslatorRegistryInterface::class);

        $configServiceMock
            ->method('getConfiguration')
            ->willThrowException(new ConfigurationNotFoundException('Not found', 1234));

        $translatorStub = self::createStub(TranslatorInterface::class);

        $translatorRegistryMock
            ->expects(self::once())
            ->method('get')
            ->with('llm')
            ->willReturn($translatorStub);

        $subject = new TranslationService(
            $this->llmManagerStub,
            $translatorRegistryMock,
            $configServiceMock,
        );

        $reflection = new ReflectionClass($subject);
        $method = $reflection->getMethod('resolveTranslator');
        $result = $method->invoke($subject, ['preset' => 'missing-preset']);

        self::assertSame($translatorStub, $result);
    }

    #[Test]
    public function resolveTranslatorFallsBackToLlmWhenPresetHasNoTranslator(): void
    {
        $configServiceMock = $this->createMock(LlmConfigurationServiceInterface::class);
        $translatorRegistryMock = $this->createMock(TranslatorRegistryInterface::class);

        // Configuration found but translator field is empty string
        $configurationStub = self::createStub(LlmConfiguration::class);
        $configurationStub->method('getTranslator')->willReturn('');

        $configServiceMock
            ->method('getConfiguration')
            ->willReturn($configurationStub);

        $translatorStub = self::createStub(TranslatorInterface::class);

        $translatorRegistryMock
            ->expects(self::once())
            ->method('get')
            ->with('llm')
            ->willReturn($translatorStub);

        $subject = new TranslationService(
            $this->llmManagerStub,
            $translatorRegistryMock,
            $configServiceMock,
        );

        $reflection = new ReflectionClass($subject);
        $method = $reflection->getMethod('resolveTranslator');
        $result = $method->invoke($subject, ['preset' => 'preset-without-translator']);

        self::assertSame($translatorStub, $result);
    }

    #[Test]
    public function translateBatchWithTranslatorWithNullSourceLanguage(): void
    {
        $translatorStub = self::createStub(TranslatorInterface::class);
        $translatorStub
            ->method('translateBatch')
            ->willReturn([
                new TranslatorResult('Hallo', 'auto', 'de', 'llm'),
                new TranslatorResult('Welt', 'auto', 'de', 'llm'),
            ]);

        $this->translatorRegistryMock
            ->method('get')
            ->willReturn($translatorStub);

        $result = $this->subject->translateBatchWithTranslator(['Hello', 'World'], 'de', null);

        self::assertCount(2, $result);
    }

    #[Test]
    public function translateWithInformalFormalityProducesCorrectPromptTone(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->method('chat')
            ->willReturn($this->createChatResponse('Translated'));

        $subject = new TranslationService(
            $llmManagerMock,
            $this->translatorRegistryMock,
            $this->configServiceStub,
        );

        $options = new TranslationOptions(formality: 'informal');
        $result = $subject->translate('Hello', 'de', 'en', $options);

        self::assertEquals('Translated', $result->translation);
    }

    #[Test]
    public function translateWithGeneralDomainProducesResult(): void
    {
        $this->llmManagerStub
            ->method('chat')
            ->willReturn($this->createChatResponse('Translated'));

        $options = new TranslationOptions(domain: 'general');
        $result = $this->subject->translate('Hello', 'de', 'en', $options);

        self::assertEquals('Translated', $result->translation);
    }

    // ==================== validateOptions private method coverage ====================

    #[Test]
    public function validateOptionsThrowsOnInvalidFormality(): void
    {
        // TranslationOptions validates formality at construction, so invalid values
        // cannot be passed through it. We use reflection to call validateOptions()
        // directly with a raw options array containing an unsupported formality value,
        // covering lines 463-469 in TranslationService.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(6448506079);

        $reflection = new ReflectionClass($this->subject);
        $method = $reflection->getMethod('validateOptions');
        $method->invoke($this->subject, ['formality' => 'very-formal']);
    }

    #[Test]
    public function validateOptionsThrowsOnInvalidDomain(): void
    {
        // TranslationOptions validates domain at construction, so invalid values
        // cannot be passed through it. We use reflection to call validateOptions()
        // directly with a raw options array containing an unsupported domain value,
        // covering lines 475-481 in TranslationService.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(3885497401);

        $reflection = new ReflectionClass($this->subject);
        $method = $reflection->getMethod('validateOptions');
        $method->invoke($this->subject, ['domain' => 'unknown-domain']);
    }

    #[Test]
    public function validateOptionsThrowsOnNonArrayGlossary(): void
    {
        // TranslationOptions only accepts array glossary at construction, so a
        // non-array glossary cannot reach validateOptions() through normal flow.
        // We use reflection to invoke validateOptions() with a non-array glossary,
        // covering line 486 in TranslationService.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(8571915742);

        $reflection = new ReflectionClass($this->subject);
        $method = $reflection->getMethod('validateOptions');
        $method->invoke($this->subject, ['glossary' => 'not-an-array']);
    }

    // ==================== budget pre-flight tests (REC #4 slice 15b) ====================

    #[Test]
    public function translateForwardsResolvedBeUserUidOnInternalChatOptions(): void
    {
        // The LLM-based translation path internally builds a ChatOptions
        // for the manager. The resolver-supplied uid must reach the
        // internal options so BudgetMiddleware sees it.
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $resolver = $this->createMock(BackendUserContextResolverInterface::class);
        $resolver->expects(self::atLeastOnce())
            ->method('resolveBeUserUid')
            ->willReturn(42);

        $subject = new TranslationService(
            $llmManagerMock,
            $this->translatorRegistryMock,
            $this->configServiceStub,
            $resolver,
        );

        $llmManagerMock->expects(self::atLeastOnce())
            ->method('chat')
            ->with(
                self::anything(),
                self::callback(static fn(ChatOptions $opts): bool
                    => $opts->getBeUserUid() === 42),
            )
            ->willReturn($this->createChatResponse('Hallo Welt'));

        $subject->translate('Hello World', 'de', 'en');
    }

    #[Test]
    public function translateRespectsExplicitBeUserUidOverResolver(): void
    {
        // Caller-supplied uid wins; resolver must NOT be called.
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $resolver = $this->createMock(BackendUserContextResolverInterface::class);
        $resolver->expects(self::never())
            ->method('resolveBeUserUid');

        $subject = new TranslationService(
            $llmManagerMock,
            $this->translatorRegistryMock,
            $this->configServiceStub,
            $resolver,
        );

        $llmManagerMock->expects(self::atLeastOnce())
            ->method('chat')
            ->with(
                self::anything(),
                self::callback(static fn(ChatOptions $opts): bool
                    => $opts->getBeUserUid() === 99
                    && $opts->getPlannedCost() === 0.05),
            )
            ->willReturn($this->createChatResponse('Hallo Welt'));

        $opts = (new TranslationOptions())
            ->withBeUserUid(99)
            ->withPlannedCost(0.05);

        $subject->translate('Hello', 'de', 'en', $opts);
    }

    #[Test]
    public function detectLanguageForwardsBudgetFieldsToInternalChatOptions(): void
    {
        // Coverage for the second internal ChatOptions construction
        // site (language detection).
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $resolver = $this->createMock(BackendUserContextResolverInterface::class);
        $resolver->method('resolveBeUserUid')->willReturn(7);

        $subject = new TranslationService(
            $llmManagerMock,
            $this->translatorRegistryMock,
            $this->configServiceStub,
            $resolver,
        );

        $llmManagerMock->expects(self::once())
            ->method('chat')
            ->with(
                self::anything(),
                self::callback(static fn(ChatOptions $opts): bool
                    => $opts->getBeUserUid() === 7),
            )
            ->willReturn($this->createChatResponse('en'));

        $subject->detectLanguage('hello world');
    }

    #[Test]
    public function scoreTranslationQualityForwardsBudgetFieldsToInternalChatOptions(): void
    {
        // Coverage for the third internal ChatOptions construction
        // site (quality scoring).
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $resolver = $this->createMock(BackendUserContextResolverInterface::class);
        $resolver->method('resolveBeUserUid')->willReturn(11);

        $subject = new TranslationService(
            $llmManagerMock,
            $this->translatorRegistryMock,
            $this->configServiceStub,
            $resolver,
        );

        $llmManagerMock->expects(self::once())
            ->method('chat')
            ->with(
                self::anything(),
                self::callback(static fn(ChatOptions $opts): bool
                    => $opts->getBeUserUid() === 11),
            )
            ->willReturn($this->createChatResponse('0.9'));

        $subject->scoreTranslationQuality('Hello', 'Hallo', 'de');
    }

    // ==================== translator-path attribution tests (ADR-052) ====================

    #[Test]
    public function translateWithTranslatorAttachesBeUserUidToTranslatorOptions(): void
    {
        // Budget fields are excluded from TranslationOptions::toArray(),
        // so the service re-attaches the uid as the `beUserUid` metadata
        // key for the translator's trackUsage() call (ADR-052).
        $translatorMock = $this->createMock(TranslatorInterface::class);
        $translatorMock
            ->expects(self::once())
            ->method('translate')
            ->with(
                'Hello World',
                'de',
                'en',
                self::callback(static fn(array $options): bool => ($options['beUserUid'] ?? null) === 42),
            )
            ->willReturn(new TranslatorResult(
                translatedText: 'Hallo Welt',
                sourceLanguage: 'en',
                targetLanguage: 'de',
                translator: 'llm:openai',
            ));

        $this->translatorRegistryMock
            ->method('get')
            ->willReturn($translatorMock);

        $this->subject->translateWithTranslator(
            'Hello World',
            'de',
            'en',
            (new TranslationOptions())->withBeUserUid(42),
        );
    }

    #[Test]
    public function translateWithTranslatorAttachesResolverUidWhenOptionsCarryNone(): void
    {
        $resolver = $this->createMock(BackendUserContextResolverInterface::class);
        $resolver->method('resolveBeUserUid')->willReturn(7);

        $subject = new TranslationService(
            $this->llmManagerStub,
            $this->translatorRegistryMock,
            $this->configServiceStub,
            $resolver,
        );

        $translatorMock = $this->createMock(TranslatorInterface::class);
        $translatorMock
            ->expects(self::once())
            ->method('translate')
            ->with(
                'Hello World',
                'de',
                'en',
                self::callback(static fn(array $options): bool => ($options['beUserUid'] ?? null) === 7),
            )
            ->willReturn(new TranslatorResult(
                translatedText: 'Hallo Welt',
                sourceLanguage: 'en',
                targetLanguage: 'de',
                translator: 'llm:openai',
            ));

        $this->translatorRegistryMock
            ->method('get')
            ->willReturn($translatorMock);

        $subject->translateWithTranslator('Hello World', 'de', 'en');
    }

    #[Test]
    public function translateWithTranslatorOmitsBeUserUidKeyWithoutUidSource(): void
    {
        // No options uid and no resolver: the key stays absent so the
        // tracker's own ambient fallback applies.
        $translatorMock = $this->createMock(TranslatorInterface::class);
        $translatorMock
            ->expects(self::once())
            ->method('translate')
            ->with(
                'Hello World',
                'de',
                'en',
                self::callback(static fn(array $options): bool => !array_key_exists('beUserUid', $options)),
            )
            ->willReturn(new TranslatorResult(
                translatedText: 'Hallo Welt',
                sourceLanguage: 'en',
                targetLanguage: 'de',
                translator: 'llm:openai',
            ));

        $this->translatorRegistryMock
            ->method('get')
            ->willReturn($translatorMock);

        $this->subject->translateWithTranslator('Hello World', 'de', 'en');
    }

    #[Test]
    public function translateBatchWithTranslatorAttachesBeUserUidToTranslatorOptions(): void
    {
        $translatorMock = $this->createMock(TranslatorInterface::class);
        $translatorMock
            ->expects(self::once())
            ->method('translateBatch')
            ->with(
                ['Hello', 'World'],
                'de',
                'en',
                self::callback(static fn(array $options): bool => ($options['beUserUid'] ?? null) === 42),
            )
            ->willReturn([]);

        $this->translatorRegistryMock
            ->method('get')
            ->willReturn($translatorMock);

        $this->subject->translateBatchWithTranslator(
            ['Hello', 'World'],
            'de',
            'en',
            (new TranslationOptions())->withBeUserUid(42),
        );
    }

    // ==================== request-transformation pinning tests ====================
    // These strengthen the suite against mutations in the messages / ChatOptions
    // that the service constructs and hands to LlmServiceManager::chat(), and in
    // the prompt built by buildTranslationPrompt(). They capture the exact
    // arguments rather than only asserting the happy-path return value.

    /**
     * Capture the messages + ChatOptions handed to LlmServiceManager::chat()
     * for a single-turn feature call.
     *
     * @param Closure(TranslationService): mixed $call
     *
     * @return array{messages: array<array-key, mixed>, options: ChatOptions}
     */
    private function captureSingleChat(string $responseContent, Closure $call): array
    {
        $capturedMessages = [];
        $capturedOptions = null;

        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->method('chat')
            ->willReturnCallback(
                function (array $messages, ChatOptions $chatOptions) use (
                    &$capturedMessages,
                    &$capturedOptions,
                    $responseContent,
                ): CompletionResponse {
                    $capturedMessages = $messages;
                    $capturedOptions = $chatOptions;

                    return $this->createChatResponse($responseContent);
                },
            );

        $subject = new TranslationService(
            $llmManagerMock,
            $this->translatorRegistryMock,
            $this->configServiceStub,
        );

        $call($subject);

        self::assertInstanceOf(ChatOptions::class, $capturedOptions);

        return ['messages' => $capturedMessages, 'options' => $capturedOptions];
    }

    /**
     * Invoke the private buildTranslationPrompt() with a fully-controlled raw
     * options array and return the ['system', 'user'] strings.
     *
     * @param array<string, mixed> $options
     *
     * @return array{system: string, user: string}
     */
    private function invokeBuildPrompt(string $text, string $source, string $target, array $options): array
    {
        $reflection = new ReflectionClass($this->subject);
        $method = $reflection->getMethod('buildTranslationPrompt');
        $prompt = $method->invoke($this->subject, $text, $source, $target, $options);

        self::assertIsArray($prompt);
        $system = $prompt['system'] ?? null;
        $user = $prompt['user'] ?? null;
        self::assertIsString($system);
        self::assertIsString($user);

        return ['system' => $system, 'user' => $user];
    }

    #[Test]
    public function translateBuildsSystemAndUserMessagesWithDefaultChatOptions(): void
    {
        $captured = $this->captureSingleChat(
            'Hallo Welt',
            static fn(TranslationService $subject): object => $subject->translate('Hello World', 'de', 'en'),
        );

        $messages = $captured['messages'];
        self::assertCount(2, $messages);

        $system = $messages[0];
        $user = $messages[1];
        self::assertInstanceOf(ChatMessage::class, $system);
        self::assertInstanceOf(ChatMessage::class, $user);
        self::assertTrue($system->isSystem());
        self::assertTrue($user->isUser());
        self::assertStringContainsString('You are a professional', $system->content);
        self::assertSame("Translate this text:\n\nHello World", $user->content);

        // Default ChatOptions coalesce targets: temperature 0.3, maxTokens 2000,
        // no provider (TranslationOptions defaults are all null there).
        $options = $captured['options'];
        self::assertSame(0.3, $options->getTemperature());
        self::assertSame(2000, $options->getMaxTokens());
        self::assertNull($options->getProvider());
    }

    #[Test]
    public function translateForwardsCustomTemperatureAndMaxTokensOntoChatOptions(): void
    {
        $captured = $this->captureSingleChat(
            'Translated',
            static fn(TranslationService $subject): object => $subject->translate(
                'Hello',
                'de',
                'en',
                new TranslationOptions(temperature: 0.5, maxTokens: 1000),
            ),
        );

        $options = $captured['options'];
        self::assertSame(0.5, $options->getTemperature());
        self::assertSame(1000, $options->getMaxTokens());
    }

    #[Test]
    public function translateBatchPassesOptionsThroughToPerTextTranslateCall(): void
    {
        $captured = $this->captureSingleChat(
            'Eins',
            static fn(TranslationService $subject): array => $subject->translateBatch(
                ['One'],
                'de',
                'en',
                new TranslationOptions(formality: 'formal'),
            ),
        );

        $system = $captured['messages'][0];
        self::assertInstanceOf(ChatMessage::class, $system);
        // If the batch discarded the passed options, formality would fall back to
        // 'default' and the tone line would disappear.
        self::assertStringContainsString('Maintain formal tone.', $system->content);
    }

    #[Test]
    public function detectLanguagePinsMessagesAndChatOptions(): void
    {
        $captured = $this->captureSingleChat(
            'fr',
            static fn(TranslationService $subject): string => $subject->detectLanguage(
                'Bonjour',
                new TranslationOptions(provider: 'claude'),
            ),
        );

        $messages = $captured['messages'];
        self::assertCount(2, $messages);

        $system = $messages[0];
        $user = $messages[1];
        self::assertInstanceOf(ChatMessage::class, $system);
        self::assertInstanceOf(ChatMessage::class, $user);
        self::assertSame(
            'You are a language detection expert. Respond with ONLY the ISO 639-1 language code (e.g., "en", "de", "fr"). No explanation.',
            $system->content,
        );
        self::assertSame("Detect the language of this text:\n\nBonjour", $user->content);

        $options = $captured['options'];
        self::assertSame(10, $options->getMaxTokens());
        self::assertSame('claude', $options->getProvider());
    }

    #[Test]
    public function scoreTranslationQualityPinsMessagesAndChatOptions(): void
    {
        $captured = $this->captureSingleChat(
            '0.85',
            static fn(TranslationService $subject): float => $subject->scoreTranslationQuality(
                'Hello',
                'Hallo',
                'de',
                new TranslationOptions(provider: 'claude'),
            ),
        );

        $messages = $captured['messages'];
        self::assertCount(2, $messages);

        $system = $messages[0];
        $user = $messages[1];
        self::assertInstanceOf(ChatMessage::class, $system);
        self::assertInstanceOf(ChatMessage::class, $user);
        self::assertSame(
            'You are a translation quality expert. Evaluate the translation quality based on accuracy, fluency, and consistency. Respond with ONLY a number between 0.0 and 1.0 (e.g., "0.85"). No explanation.',
            $system->content,
        );
        self::assertSame(
            "Source text:\nHello\n\nTranslation to de:\nHallo\n\nQuality score:",
            $user->content,
        );

        $options = $captured['options'];
        self::assertSame(10, $options->getMaxTokens());
        self::assertSame('claude', $options->getProvider());
    }

    #[Test]
    public function translateBatchWithTranslatorSkipsTranslatorResolutionForEmptyInput(): void
    {
        // The empty-input early return must short-circuit before any translator
        // lookup — removing the `return []` would fall through to resolveTranslator().
        $this->translatorRegistryMock
            ->expects(self::never())
            ->method('get');

        $result = $this->subject->translateBatchWithTranslator([], 'de');

        self::assertSame([], $result);
    }

    #[Test]
    public function resolveTranslatorSkipsPresetLookupForEmptyPresetString(): void
    {
        // preset === '' must NOT trigger a configuration lookup: the guard is
        // `is_string($preset) && $preset !== ''`. Swapping && for || would enter
        // the branch and call getConfiguration('').
        $configServiceMock = $this->createMock(LlmConfigurationServiceInterface::class);
        $configServiceMock
            ->expects(self::never())
            ->method('getConfiguration');

        $translatorRegistryMock = $this->createMock(TranslatorRegistryInterface::class);
        $translatorStub = self::createStub(TranslatorInterface::class);
        $translatorRegistryMock
            ->expects(self::once())
            ->method('get')
            ->with('llm')
            ->willReturn($translatorStub);

        $subject = new TranslationService(
            $this->llmManagerStub,
            $translatorRegistryMock,
            $configServiceMock,
        );

        $reflection = new ReflectionClass($subject);
        $method = $reflection->getMethod('resolveTranslator');
        $result = $method->invoke($subject, ['preset' => '']);

        self::assertSame($translatorStub, $result);
    }

    #[Test]
    public function buildTranslationPromptRendersMinimalPrompt(): void
    {
        $prompt = $this->invokeBuildPrompt('Hello World', 'en', 'de', [
            'formality' => 'default',
            'domain' => 'general',
            'glossary' => [],
            'context' => '',
            'preserve_formatting' => true,
        ]);

        $system = $prompt['system'];
        // Domain + language-name resolution (en -> English, de -> German).
        self::assertStringContainsString(
            'You are a professional general translator. Translate the following text from English to German.',
            $system,
        );
        // formality 'default' => no tone line.
        self::assertStringNotContainsString('Maintain', $system);
        // preserve_formatting true => formatting line present.
        self::assertStringContainsString(
            'Preserve all formatting, HTML tags, markdown, and special characters.',
            $system,
        );
        // Empty glossary / context => their sections are absent.
        self::assertStringNotContainsString('Use these exact term translations:', $system);
        self::assertStringNotContainsString('Context (for reference only):', $system);
        // Trailing instruction is appended, not replacing the prompt.
        self::assertStringContainsString('Provide ONLY the translation, no explanations or notes.', $system);

        self::assertSame("Translate this text:\n\nHello World", $prompt['user']);
    }

    #[Test]
    public function buildTranslationPromptDefaultsPreserveFormattingToTrueWhenKeyAbsent(): void
    {
        // No 'preserve_formatting' key => the `?? true` default applies.
        $prompt = $this->invokeBuildPrompt('Hello', 'en', 'de', [
            'formality' => 'default',
            'domain' => 'general',
        ]);

        self::assertStringContainsString(
            'Preserve all formatting, HTML tags, markdown, and special characters.',
            $prompt['system'],
        );
    }

    #[Test]
    public function buildTranslationPromptOmitsFormattingLineWhenPreserveFormattingFalse(): void
    {
        $prompt = $this->invokeBuildPrompt('Hello', 'en', 'de', [
            'formality' => 'default',
            'domain' => 'general',
            'preserve_formatting' => false,
        ]);

        self::assertStringNotContainsString('Preserve all formatting', $prompt['system']);
    }

    #[Test]
    public function buildTranslationPromptRendersAllSectionsWithFullOptions(): void
    {
        $prompt = $this->invokeBuildPrompt('Hello', 'en', 'de', [
            'formality' => 'formal',
            'domain' => 'legal',
            'glossary' => [
                'Hello' => 'Hallo',   // string value
                'count' => 5,         // int value
                'ratio' => 1.5,       // float value
                'bad' => ['x'],       // non-scalar => filtered out
            ],
            'context' => 'Software docs',
            'preserve_formatting' => false,
        ]);

        $system = $prompt['system'];

        // Intro must survive every section append (guards against `.=` -> `=`).
        self::assertStringContainsString(
            'You are a professional legal translator. Translate the following text from English to German.',
            $system,
        );
        // formality 'formal' => tone line.
        self::assertStringContainsString('Maintain formal tone.', $system);
        // preserve false => no formatting line.
        self::assertStringNotContainsString('Preserve all formatting', $system);
        // Glossary header + one line per scalar term.
        self::assertStringContainsString('Use these exact term translations:', $system);
        self::assertStringContainsString('- Hello → Hallo', $system);
        self::assertStringContainsString('- count → 5', $system);
        self::assertStringContainsString('- ratio → 1.5', $system);
        // Non-scalar glossary value is filtered out entirely.
        self::assertStringNotContainsString('- bad', $system);
        // Context section rendered.
        self::assertStringContainsString("Context (for reference only):\nSoftware docs", $system);
        // Closing instruction still appended.
        self::assertStringContainsString('Provide ONLY the translation, no explanations or notes.', $system);
    }

    #[Test]
    public function buildTranslationPromptResolvesKnownLanguageNames(): void
    {
        // getLanguageName maps known codes; an unknown code falls back to itself.
        $prompt = $this->invokeBuildPrompt('Hi', 'fr', 'zz', [
            'formality' => 'default',
            'domain' => 'general',
        ]);

        self::assertStringContainsString('from French to zz.', $prompt['system']);
    }
}
