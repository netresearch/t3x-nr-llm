<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Translation;

use Exception;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Specialized\Exception\ServiceConfigurationException;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Option\DeepLOptions;
use Netresearch\NrLlm\Specialized\Translation\DeepLTranslator;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use ReflectionClass;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Mutation-killing tests for DeepLTranslator.
 *
 * These tests specifically target escaped mutants identified by Infection.
 */
#[CoversClass(DeepLTranslator::class)]
class DeepLTranslatorMutationTest extends AbstractUnitTestCase
{
    /** @var array<string, array<string, array<string, int|string>>> */
    private array $defaultConfig;
    private UsageTrackerServiceInterface $usageTrackerStub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usageTrackerStub = self::createStub(UsageTrackerServiceInterface::class);

        $this->defaultConfig = [
            'translators' => [
                'deepl' => [
                    'apiKey' => $this->randomApiKey(),
                    'timeout' => 30,
                ],
            ],
        ];
    }

    /**
     * @param array<string, array<string, array<string, int|string>>>|null $config
     */
    private function createTranslator(?array $config = null): DeepLTranslator
    {
        return new DeepLTranslator(
            $this->createHttpClientMock(),
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createExtensionConfigurationMock($config ?? $this->defaultConfig),
            $this->usageTrackerStub,
            $this->createLoggerMock(),
        );
    }

    /**
     * @param array<string, array<string, array<string, int|string>>>|null $config
     */
    private function createTranslatorWithHttpClient(ClientInterface $httpClient, ?array $config = null): DeepLTranslator
    {
        return new DeepLTranslator(
            $httpClient,
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createExtensionConfigurationMock($config ?? $this->defaultConfig),
            $this->usageTrackerStub,
            $this->createLoggerMock(),
        );
    }

    // ===== Tests for mapFormality match expression =====

    #[Test]
    #[DataProvider('formalityMappingProvider')]
    public function mapFormalityReturnsCorrectValue(string $input, string $expected): void
    {
        $translator = $this->createTranslator();

        // Use reflection to test private method
        $reflection = new ReflectionClass($translator);
        $method = $reflection->getMethod('mapFormality');

        $result = $method->invoke($translator, $input);

        self::assertEquals($expected, $result);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function formalityMappingProvider(): array
    {
        return [
            'formal maps to more' => ['formal', 'more'],
            'more maps to more' => ['more', 'more'],
            'informal maps to less' => ['informal', 'less'],
            'less maps to less' => ['less', 'less'],
            'prefer_more maps to prefer_more' => ['prefer_more', 'prefer_more'],
            'prefer_less maps to prefer_less' => ['prefer_less', 'prefer_less'],
            'unknown maps to default' => ['unknown', 'default'],
            'empty maps to default' => ['', 'default'],
            'any other value maps to default' => ['random_value', 'default'],
        ];
    }

    // ===== Tests for normalizeLanguageCode =====

    #[Test]
    #[DataProvider('languageCodeNormalizationProvider')]
    public function normalizeLanguageCodeReturnsCorrectValue(
        string $input,
        bool $isSource,
        string $expected,
    ): void {
        $translator = $this->createTranslator();

        $reflection = new ReflectionClass($translator);
        $method = $reflection->getMethod('normalizeLanguageCode');

        $result = $method->invoke($translator, $input, $isSource);

        self::assertEquals($expected, $result);
    }

    /**
     * @return array<string, array{string, bool, string}>
     */
    public static function languageCodeNormalizationProvider(): array
    {
        return [
            // Norwegian normalization
            'NO source becomes NB' => ['NO', true, 'NB'],
            'NO target becomes NB' => ['NO', false, 'NB'],
            'no lowercase source becomes NB' => ['no', true, 'NB'],

            // Chinese normalization - different for source vs target
            'ZH source stays ZH' => ['ZH', true, 'ZH'],
            'ZH target becomes ZH-HANS' => ['ZH', false, 'ZH-HANS'],
            'zh lowercase source stays ZH' => ['zh', true, 'ZH'],
            'zh lowercase target becomes ZH-HANS' => ['zh', false, 'ZH-HANS'],

            // Regular codes pass through uppercased
            'de source stays DE' => ['de', true, 'DE'],
            'de target stays DE' => ['de', false, 'DE'],
            'EN source stays EN' => ['en', true, 'EN'],
            'EN target stays EN' => ['en', false, 'EN'],
            'FR source stays FR' => ['fr', true, 'FR'],

            // Regional variants
            'en-gb becomes EN-GB' => ['en-gb', false, 'EN-GB'],
            'PT-BR stays PT-BR' => ['pt-br', false, 'PT-BR'],
        ];
    }

    // ===== Tests for countBilledCharacters =====

    #[Test]
    public function countBilledCharactersUsesMultibyteLength(): void
    {
        $translator = $this->createTranslator();

        $reflection = new ReflectionClass($translator);
        $method = $reflection->getMethod('countBilledCharacters');

        // ASCII text
        $asciiResult = $method->invoke($translator, 'Hello');
        self::assertEquals(5, $asciiResult);

        // Multibyte text (Japanese)
        $multibyteResult = $method->invoke($translator, 'こんにちは');
        self::assertEquals(5, $multibyteResult);

        // Mixed text
        $mixedResult = $method->invoke($translator, 'Hello世界');
        self::assertEquals(7, $mixedResult);

        // Emoji (multibyte characters)
        $emojiResult = $method->invoke($translator, '😀😁😂');
        self::assertEquals(3, $emojiResult);
    }

    // ===== Tests for configuration loading =====

    #[Test]
    public function freeApiKeyUsesFreelEndpoint(): void
    {
        $config = [
            'translators' => [
                'deepl' => [
                    'apiKey' => 'test-api-key:fx', // Free API key ends with :fx
                    'timeout' => 30,
                ],
            ],
        ];

        $translator = $this->createTranslator($config);

        $reflection = new ReflectionClass($translator);
        $baseUrl = $reflection->getProperty('baseUrl');
        $baseUrlValue = $baseUrl->getValue($translator);
        self::assertIsString($baseUrlValue);

        self::assertStringContainsString('api-free.deepl.com', $baseUrlValue);
    }

    #[Test]
    public function proApiKeyUsesProEndpoint(): void
    {
        $config = [
            'translators' => [
                'deepl' => [
                    'apiKey' => 'test-api-key-pro', // Pro API key doesn't end with :fx
                    'timeout' => 30,
                ],
            ],
        ];

        $translator = $this->createTranslator($config);

        $reflection = new ReflectionClass($translator);
        $baseUrl = $reflection->getProperty('baseUrl');
        $baseUrlValue = $baseUrl->getValue($translator);
        self::assertIsString($baseUrlValue);

        self::assertStringContainsString('api.deepl.com', $baseUrlValue);
        self::assertStringNotContainsString('api-free', $baseUrlValue);
    }

    #[Test]
    public function customBaseUrlIsRespected(): void
    {
        $config = [
            'translators' => [
                'deepl' => [
                    'apiKey' => 'test-api-key-pro',
                    'baseUrl' => 'https://custom-deepl-api.example.com',
                    'timeout' => 30,
                ],
            ],
        ];

        $translator = $this->createTranslator($config);

        $reflection = new ReflectionClass($translator);
        $baseUrl = $reflection->getProperty('baseUrl');

        self::assertEquals('https://custom-deepl-api.example.com', $baseUrl->getValue($translator));
    }

    // ===== Tests for translate with options =====

    #[Test]
    public function translateWithFormalityOption(): void
    {
        $apiResponse = [
            'translations' => [
                ['text' => 'Guten Tag', 'detected_source_language' => 'EN'],
            ],
        ];

        $httpClientMock = $this->createMock(ClientInterface::class);
        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $translator = $this->createTranslatorWithHttpClient($httpClientMock);

        $result = $translator->translate('Hello', 'de', null, ['formality' => 'more']);

        self::assertEquals('Guten Tag', $result->translatedText);
    }

    #[Test]
    public function translateWithDeepLOptionsObject(): void
    {
        $apiResponse = [
            'translations' => [
                ['text' => 'Hallo', 'detected_source_language' => 'EN'],
            ],
        ];

        $httpClientMock = $this->createMock(ClientInterface::class);
        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $translator = $this->createTranslatorWithHttpClient($httpClientMock);

        $options = new DeepLOptions(
            formality: 'more',
            preserveFormatting: true,
            glossaryId: 'gls_123',
        );

        $result = $translator->translate('Hello', 'de', null, ['deepl' => $options]);

        self::assertEquals('Hallo', $result->translatedText);
    }

    #[Test]
    public function translateWithPreserveFormattingOption(): void
    {
        $apiResponse = [
            'translations' => [
                ['text' => '<b>Hallo</b>', 'detected_source_language' => 'EN'],
            ],
        ];

        $httpClientMock = $this->createMock(ClientInterface::class);
        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $translator = $this->createTranslatorWithHttpClient($httpClientMock);

        $result = $translator->translate('<b>Hello</b>', 'de', null, ['preserve_formatting' => true]);

        self::assertEquals('<b>Hallo</b>', $result->translatedText);
    }

    #[Test]
    public function translateWithTagHandlingOptions(): void
    {
        $apiResponse = [
            'translations' => [
                ['text' => '<p>Hallo</p>', 'detected_source_language' => 'EN'],
            ],
        ];

        $httpClientMock = $this->createMock(ClientInterface::class);
        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $translator = $this->createTranslatorWithHttpClient($httpClientMock);

        $result = $translator->translate(
            '<p>Hello</p>',
            'de',
            null,
            [
                'tag_handling' => 'html',
                'ignore_tags' => ['script', 'style'],
                'non_splitting_tags' => ['span'],
            ],
        );

        self::assertEquals('<p>Hallo</p>', $result->translatedText);
    }

    #[Test]
    public function translateWithSplitSentencesOption(): void
    {
        $apiResponse = [
            'translations' => [
                ['text' => 'Hallo. Welt.', 'detected_source_language' => 'EN'],
            ],
        ];

        $httpClientMock = $this->createMock(ClientInterface::class);
        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $translator = $this->createTranslatorWithHttpClient($httpClientMock);

        $result = $translator->translate('Hello. World.', 'de', null, ['split_sentences' => false]);

        self::assertEquals('Hallo. Welt.', $result->translatedText);
    }

    #[Test]
    public function translateWithGlossaryIdOption(): void
    {
        $apiResponse = [
            'translations' => [
                ['text' => 'Hallo', 'detected_source_language' => 'EN'],
            ],
        ];

        $httpClientMock = $this->createMock(ClientInterface::class);
        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $translator = $this->createTranslatorWithHttpClient($httpClientMock);

        $result = $translator->translate('Hello', 'de', null, ['glossary_id' => 'gls_test123']);

        self::assertEquals('Hallo', $result->translatedText);
    }

    // ===== Tests for batch translation =====

    #[Test]
    public function translateBatchWithSourceLanguage(): void
    {
        $apiResponse = [
            'translations' => [
                ['text' => 'Hallo', 'detected_source_language' => 'EN'],
                ['text' => 'Welt', 'detected_source_language' => 'EN'],
            ],
        ];

        $httpClientMock = $this->createMock(ClientInterface::class);
        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $usageTrackerMock = $this->createMock(UsageTrackerServiceInterface::class);
        $usageTrackerMock
            ->expects(self::once())
            ->method('trackUsage')
            ->with(
                'translation',
                'deepl',
                self::callback(fn(array $data) => $data['batch_size'] === 2 && $data['characters'] === 10),
            );

        $translator = new DeepLTranslator(
            $httpClientMock,
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createExtensionConfigurationMock($this->defaultConfig),
            $usageTrackerMock,
            $this->createLoggerMock(),
        );

        $results = $translator->translateBatch(['Hello', 'World'], 'de', 'en');

        self::assertCount(2, $results);
        self::assertEquals('en', $results[0]->sourceLanguage);
        self::assertEquals('en', $results[1]->sourceLanguage);
    }

    #[Test]
    public function translateBatchWithOptions(): void
    {
        $apiResponse = [
            'translations' => [
                ['text' => 'Guten Tag', 'detected_source_language' => 'EN'],
            ],
        ];

        $httpClientMock = $this->createMock(ClientInterface::class);
        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $translator = $this->createTranslatorWithHttpClient($httpClientMock);

        $results = $translator->translateBatch(
            ['Hello'],
            'de',
            null,
            ['formality' => 'more', 'preserve_formatting' => true],
        );

        self::assertCount(1, $results);
        self::assertEquals('Guten Tag', $results[0]->translatedText);
    }

    // ===== Tests for detected source language fallback =====

    #[Test]
    public function translateUsesProvidedSourceLanguageWhenNotDetected(): void
    {
        $apiResponse = [
            'translations' => [
                ['text' => 'Hallo'], // No detected_source_language
            ],
        ];

        $httpClientMock = $this->createMock(ClientInterface::class);
        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $translator = $this->createTranslatorWithHttpClient($httpClientMock);

        $result = $translator->translate('Hello', 'de', 'en');

        self::assertEquals('en', $result->sourceLanguage);
    }

    #[Test]
    public function translateFallsBackToEnglishWhenNoSourceLanguage(): void
    {
        $apiResponse = [
            'translations' => [
                ['text' => 'Hallo'], // No detected_source_language and no source provided
            ],
        ];

        $httpClientMock = $this->createMock(ClientInterface::class);
        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $translator = $this->createTranslatorWithHttpClient($httpClientMock);

        $result = $translator->translate('Hello', 'de');

        self::assertEquals('en', $result->sourceLanguage);
    }

    // ===== Tests for API error handling =====

    #[Test]
    public function translateHandles403Error(): void
    {
        $httpClientMock = self::createStub(ClientInterface::class);
        $httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock(['message' => 'Forbidden'], 403));

        $translator = $this->createTranslatorWithHttpClient($httpClientMock);

        $this->expectException(ServiceConfigurationException::class);

        $translator->translate('Hello', 'de');
    }

    #[Test]
    public function translateHandlesGenericError(): void
    {
        $httpClientMock = self::createStub(ClientInterface::class);
        $httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock(['message' => 'Internal error'], 500));

        $translator = $this->createTranslatorWithHttpClient($httpClientMock);

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('Internal error');

        $translator->translate('Hello', 'de');
    }

    #[Test]
    public function translateHandlesEmptyErrorMessage(): void
    {
        $httpClientMock = self::createStub(ClientInterface::class);
        $httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock([], 500));

        $translator = $this->createTranslatorWithHttpClient($httpClientMock);

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('Unknown DeepL API error');

        $translator->translate('Hello', 'de');
    }

    // ===== Tests for detectLanguage =====

    #[Test]
    public function detectLanguageFallsBackToEnglishOnEmptyResponse(): void
    {
        $httpClientMock = self::createStub(ClientInterface::class);
        $httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock(['translations' => []]));

        $translator = $this->createTranslatorWithHttpClient($httpClientMock);

        $result = $translator->detectLanguage('Some text');

        self::assertEquals('en', $result);
    }

    #[Test]
    public function detectLanguageFallsBackToEnglishOnMissingField(): void
    {
        $httpClientMock = self::createStub(ClientInterface::class);
        $httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock([
                'translations' => [
                    ['text' => 'test'], // No detected_source_language
                ],
            ]));

        $translator = $this->createTranslatorWithHttpClient($httpClientMock);

        $result = $translator->detectLanguage('Some text');

        self::assertEquals('en', $result);
    }

    // ===== Tests for getUsage default values =====

    #[Test]
    public function getUsageReturnsZerosOnMissingFields(): void
    {
        $httpClientMock = self::createStub(ClientInterface::class);
        $httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock([]));

        $translator = $this->createTranslatorWithHttpClient($httpClientMock);

        $usage = $translator->getUsage();

        self::assertEquals(0, $usage['character_count']);
        self::assertEquals(0, $usage['character_limit']);
    }

    // ===== Tests for getGlossaries default values =====

    #[Test]
    public function getGlossariesReturnsEmptyArrayOnMissingField(): void
    {
        $httpClientMock = self::createStub(ClientInterface::class);
        $httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock([]));

        $translator = $this->createTranslatorWithHttpClient($httpClientMock);

        $glossaries = $translator->getGlossaries();

        self::assertSame([], $glossaries);
    }

    // ===== Tests for getSupportedLanguages =====

    #[Test]
    public function getSupportedLanguagesReturnsUniqueValues(): void
    {
        $translator = $this->createTranslator();

        $languages = $translator->getSupportedLanguages();

        // Check no duplicates
        $unique = array_unique($languages);
        self::assertCount(count($unique), $languages);
    }

    #[Test]
    public function getSupportedLanguagesIncludesAllMajorLanguages(): void
    {
        $translator = $this->createTranslator();

        $languages = $translator->getSupportedLanguages();

        $majorLanguages = ['en', 'de', 'fr', 'es', 'it', 'nl', 'pl', 'pt', 'ru', 'ja', 'zh', 'ko', 'ar'];

        foreach ($majorLanguages as $lang) {
            self::assertContains($lang, $languages, "Missing major language: $lang");
        }
    }

    // ===== Tests for configuration exception handling =====

    #[Test]
    public function constructorHandlesConfigurationException(): void
    {
        $extensionConfigStub = self::createStub(ExtensionConfiguration::class);
        $extensionConfigStub
            ->method('get')
            ->willThrowException(new Exception('Config error'));

        $translator = new DeepLTranslator(
            $this->createHttpClientMock(),
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $extensionConfigStub,
            $this->usageTrackerStub,
            $this->createLoggerMock(),
        );

        // Should not throw, but translator should not be available
        self::assertFalse($translator->isAvailable());
    }

    // ===== Tests for HTTP client exception handling =====

    #[Test]
    public function translateHandlesHttpClientException(): void
    {
        $httpClientMock = self::createStub(ClientInterface::class);
        $httpClientMock
            ->method('sendRequest')
            ->willThrowException(new Exception('Connection failed'));

        $translator = $this->createTranslatorWithHttpClient($httpClientMock);

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('Connection failed');

        $translator->translate('Hello', 'de');
    }

    // ===== Tests for metadata in batch results =====

    #[Test]
    public function translateBatchIncludesCorrectBilledCharacters(): void
    {
        $apiResponse = [
            'translations' => [
                ['text' => 'Hallo', 'detected_source_language' => 'EN'],
                ['text' => 'Welt Welt Welt', 'detected_source_language' => 'EN'],
            ],
        ];

        $httpClientMock = self::createStub(ClientInterface::class);
        $httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $translator = $this->createTranslatorWithHttpClient($httpClientMock);

        $results = $translator->translateBatch(['Hi', 'Hello World Test'], 'de');

        // First result should have billed_characters matching first input
        self::assertIsArray($results[0]->metadata);
        self::assertArrayHasKey('billed_characters', $results[0]->metadata);
        self::assertEquals(2, $results[0]->metadata['billed_characters']);

        // Second result should have billed_characters matching second input
        self::assertIsArray($results[1]->metadata);
        self::assertArrayHasKey('billed_characters', $results[1]->metadata);
        self::assertEquals(16, $results[1]->metadata['billed_characters']);
    }
}
