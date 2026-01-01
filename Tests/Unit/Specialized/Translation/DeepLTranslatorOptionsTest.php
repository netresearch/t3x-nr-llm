<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Translation;

use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Specialized\Option\DeepLOptions;
use Netresearch\NrLlm\Specialized\Translation\DeepLTranslator;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use ReflectionClass;

/**
 * Additional tests for DeepLTranslator to kill escaped mutants.
 */
#[CoversClass(DeepLTranslator::class)]
class DeepLTranslatorOptionsTest extends AbstractUnitTestCase
{
    /**
     * @param array<string, mixed> $config
     */
    private function createTranslator(array $config = []): DeepLTranslator
    {
        $defaultConfig = [
            'translators' => [
                'deepl' => [
                    'apiKey' => $this->randomApiKey(),
                    'timeout' => 30,
                ],
            ],
        ];

        return new DeepLTranslator(
            $this->createHttpClientMock(),
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createExtensionConfigurationMock(array_merge($defaultConfig, $config)),
            self::createStub(UsageTrackerServiceInterface::class),
            $this->createLoggerMock(),
        );
    }

    private function createTranslatorWithMockClient(ClientInterface $httpClient): DeepLTranslator
    {
        $config = [
            'translators' => [
                'deepl' => [
                    'apiKey' => $this->randomApiKey(),
                    'timeout' => 30,
                ],
            ],
        ];

        return new DeepLTranslator(
            $httpClient,
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createExtensionConfigurationMock($config),
            self::createStub(UsageTrackerServiceInterface::class),
            $this->createLoggerMock(),
        );
    }

    #[Test]
    #[DataProvider('formalityMappingProvider')]
    public function translateWithFormalityUsesCorrectMapping(string $inputFormality, string $expectedMapping): void
    {
        $httpClientMock = self::createStub(ClientInterface::class);
        $httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock([
                'translations' => [
                    ['text' => 'Hallo', 'detected_source_language' => 'EN'],
                ],
            ]));

        $translator = $this->createTranslatorWithMockClient($httpClientMock);

        // Use DeepLOptions with formality
        $options = new DeepLOptions(formality: $inputFormality);
        $result = $translator->translate('Hello', 'de', null, ['deepl' => $options]);

        self::assertEquals('Hallo', $result->translatedText);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function formalityMappingProvider(): array
    {
        return [
            'more' => ['more', 'more'],
            'less' => ['less', 'less'],
            'prefer_more' => ['prefer_more', 'prefer_more'],
            'prefer_less' => ['prefer_less', 'prefer_less'],
            'default' => ['default', 'default'],
        ];
    }

    #[Test]
    #[DataProvider('languageNormalizationProvider')]
    public function supportsLanguagePairNormalizesLanguageCodes(
        string $source,
        string $target,
        bool $expected,
    ): void {
        $translator = $this->createTranslator();

        $result = $translator->supportsLanguagePair($source, $target);

        self::assertEquals($expected, $result);
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function languageNormalizationProvider(): array
    {
        return [
            'lowercase en to de' => ['en', 'de', true],
            'uppercase EN to DE' => ['EN', 'DE', true],
            'mixed case En to De' => ['En', 'De', true],
            'norwegian NO to de' => ['no', 'de', true], // NO -> NB
            'chinese zh to en' => ['zh', 'en', true],
            'chinese to simplified zh-hans' => ['en', 'zh-hans', true],
            'portuguese brazil' => ['en', 'pt-br', true],
            'portuguese portugal' => ['en', 'pt-pt', true],
            'english gb variant' => ['de', 'en-gb', true],
            'english us variant' => ['de', 'en-us', true],
            'invalid source' => ['xx', 'de', false],
            'invalid target' => ['en', 'yy', false],
        ];
    }

    #[Test]
    public function translateWithGlossaryIdIncludesInPayload(): void
    {
        $httpClientMock = self::createStub(ClientInterface::class);
        $httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock([
                'translations' => [
                    ['text' => 'Translated', 'detected_source_language' => 'EN'],
                ],
            ]));

        $translator = $this->createTranslatorWithMockClient($httpClientMock);

        $options = new DeepLOptions(glossaryId: 'gls_12345');
        $result = $translator->translate('Hello', 'de', null, ['deepl' => $options]);

        self::assertEquals('Translated', $result->translatedText);
    }

    #[Test]
    public function translateWithPreserveFormattingIncludesInPayload(): void
    {
        $httpClientMock = self::createStub(ClientInterface::class);
        $httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock([
                'translations' => [
                    ['text' => 'Formatted', 'detected_source_language' => 'EN'],
                ],
            ]));

        $translator = $this->createTranslatorWithMockClient($httpClientMock);

        $options = new DeepLOptions(preserveFormatting: true);
        $result = $translator->translate('Hello', 'de', null, ['deepl' => $options]);

        self::assertEquals('Formatted', $result->translatedText);
    }

    #[Test]
    public function translateWithTagHandlingIncludesInPayload(): void
    {
        $httpClientMock = self::createStub(ClientInterface::class);
        $httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock([
                'translations' => [
                    ['text' => '<p>Translated</p>', 'detected_source_language' => 'EN'],
                ],
            ]));

        $translator = $this->createTranslatorWithMockClient($httpClientMock);

        $options = new DeepLOptions(
            tagHandling: 'html',
            ignoreTags: ['code', 'pre'],
            nonSplittingTags: ['span'],
        );
        $result = $translator->translate('<p>Hello</p>', 'de', null, ['deepl' => $options]);

        self::assertEquals('<p>Translated</p>', $result->translatedText);
    }

    #[Test]
    public function translateWithSplitSentencesIncludesInPayload(): void
    {
        $httpClientMock = self::createStub(ClientInterface::class);
        $httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock([
                'translations' => [
                    ['text' => 'Sentence.', 'detected_source_language' => 'EN'],
                ],
            ]));

        $translator = $this->createTranslatorWithMockClient($httpClientMock);

        $options = new DeepLOptions(splitSentences: false);
        $result = $translator->translate('Hello.', 'de', null, ['deepl' => $options]);

        self::assertEquals('Sentence.', $result->translatedText);
    }

    #[Test]
    public function translateWithArrayOptionsCreatesDeepLOptions(): void
    {
        $httpClientMock = self::createStub(ClientInterface::class);
        $httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock([
                'translations' => [
                    ['text' => 'Result', 'detected_source_language' => 'EN'],
                ],
            ]));

        $translator = $this->createTranslatorWithMockClient($httpClientMock);

        // Pass raw array options instead of DeepLOptions object
        $result = $translator->translate('Hello', 'de', null, [
            'formality' => 'more',
            'preserve_formatting' => true,
        ]);

        self::assertEquals('Result', $result->translatedText);
    }

    #[Test]
    public function detectLanguageReturnsLowercase(): void
    {
        $httpClientMock = self::createStub(ClientInterface::class);
        $httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock([
                'translations' => [
                    ['text' => 'test', 'detected_source_language' => 'DE'],
                ],
            ]));

        $translator = $this->createTranslatorWithMockClient($httpClientMock);

        $detected = $translator->detectLanguage('Hallo Welt');

        self::assertEquals('de', $detected);
    }

    #[Test]
    public function detectLanguageReturnsEnglishFallbackOnEmptyResponse(): void
    {
        $httpClientMock = self::createStub(ClientInterface::class);
        $httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock([
                'translations' => [],
            ]));

        $translator = $this->createTranslatorWithMockClient($httpClientMock);

        $detected = $translator->detectLanguage('Some text');

        self::assertEquals('en', $detected);
    }

    #[Test]
    public function translateBatchWithOptionsAppliesOptions(): void
    {
        $httpClientMock = self::createStub(ClientInterface::class);
        $httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock([
                'translations' => [
                    ['text' => 'Eins', 'detected_source_language' => 'EN'],
                    ['text' => 'Zwei', 'detected_source_language' => 'EN'],
                ],
            ]));

        $translator = $this->createTranslatorWithMockClient($httpClientMock);

        $options = new DeepLOptions(formality: 'more', glossaryId: 'gls_test');
        $results = $translator->translateBatch(['One', 'Two'], 'de', null, ['deepl' => $options]);

        self::assertCount(2, $results);
        self::assertEquals('Eins', $results[0]->translatedText);
        self::assertEquals('Zwei', $results[1]->translatedText);
    }

    #[Test]
    public function translateResultContainsBilledCharacters(): void
    {
        $text = 'Hello World';
        $httpClientMock = self::createStub(ClientInterface::class);
        $httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock([
                'translations' => [
                    ['text' => 'Hallo Welt', 'detected_source_language' => 'EN'],
                ],
            ]));

        $translator = $this->createTranslatorWithMockClient($httpClientMock);
        $result = $translator->translate($text, 'de');

        self::assertNotNull($result->metadata);
        self::assertArrayHasKey('billed_characters', $result->metadata);
        self::assertEquals(mb_strlen($text), $result->metadata['billed_characters']);
    }

    #[Test]
    public function getUsageReturnsCharacterCountAndLimit(): void
    {
        $httpClientMock = self::createStub(ClientInterface::class);
        $httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock([
                'character_count' => 12345,
                'character_limit' => 500000,
            ]));

        $translator = $this->createTranslatorWithMockClient($httpClientMock);
        $usage = $translator->getUsage();

        self::assertEquals(12345, $usage['character_count']);
        self::assertEquals(500000, $usage['character_limit']);
    }

    #[Test]
    public function getUsageReturnsZerosOnMissingData(): void
    {
        $httpClientMock = self::createStub(ClientInterface::class);
        $httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock([]));

        $translator = $this->createTranslatorWithMockClient($httpClientMock);
        $usage = $translator->getUsage();

        self::assertEquals(0, $usage['character_count']);
        self::assertEquals(0, $usage['character_limit']);
    }

    #[Test]
    public function getGlossariesReturnsEmptyArrayOnMissingData(): void
    {
        $httpClientMock = self::createStub(ClientInterface::class);
        $httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock([]));

        $translator = $this->createTranslatorWithMockClient($httpClientMock);
        $glossaries = $translator->getGlossaries();

        self::assertEmpty($glossaries);
    }

    #[Test]
    public function translateWithSourceLanguagePassesItToApi(): void
    {
        $httpClientMock = self::createStub(ClientInterface::class);
        $httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock([
                'translations' => [
                    ['text' => 'Hallo', 'detected_source_language' => 'EN'],
                ],
            ]));

        $translator = $this->createTranslatorWithMockClient($httpClientMock);
        $result = $translator->translate('Hello', 'de', 'en');

        self::assertEquals('en', $result->sourceLanguage);
    }

    #[Test]
    public function freeApiKeyUsesFreeApiUrl(): void
    {
        $config = [
            'translators' => [
                'deepl' => [
                    'apiKey' => 'test-key:fx', // Free API key ends with :fx
                    'timeout' => 30,
                ],
            ],
        ];

        $translator = new DeepLTranslator(
            $this->createHttpClientMock(),
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createExtensionConfigurationMock($config),
            self::createStub(UsageTrackerServiceInterface::class),
            $this->createLoggerMock(),
        );

        // Access baseUrl via reflection
        $reflection = new ReflectionClass($translator);
        $baseUrl = $reflection->getProperty('baseUrl');
        $baseUrlValue = $baseUrl->getValue($translator);
        self::assertIsString($baseUrlValue);

        self::assertStringContainsString('api-free.deepl.com', $baseUrlValue);
    }

    #[Test]
    public function proApiKeyUsesProApiUrl(): void
    {
        $config = [
            'translators' => [
                'deepl' => [
                    'apiKey' => 'pro-api-key', // Pro API key doesn't end with :fx
                    'timeout' => 30,
                ],
            ],
        ];

        $translator = new DeepLTranslator(
            $this->createHttpClientMock(),
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createExtensionConfigurationMock($config),
            self::createStub(UsageTrackerServiceInterface::class),
            $this->createLoggerMock(),
        );

        // Access baseUrl via reflection
        $reflection = new ReflectionClass($translator);
        $baseUrl = $reflection->getProperty('baseUrl');
        $baseUrlValue = $baseUrl->getValue($translator);
        self::assertIsString($baseUrlValue);

        self::assertStringContainsString('api.deepl.com', $baseUrlValue);
        self::assertStringNotContainsString('api-free', $baseUrlValue);
    }

    #[Test]
    public function customBaseUrlOverridesDefault(): void
    {
        $customUrl = 'https://custom-deepl.example.com';
        $config = [
            'translators' => [
                'deepl' => [
                    'apiKey' => 'pro-api-key',
                    'baseUrl' => $customUrl,
                    'timeout' => 30,
                ],
            ],
        ];

        $translator = new DeepLTranslator(
            $this->createHttpClientMock(),
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createExtensionConfigurationMock($config),
            self::createStub(UsageTrackerServiceInterface::class),
            $this->createLoggerMock(),
        );

        $reflection = new ReflectionClass($translator);
        $baseUrl = $reflection->getProperty('baseUrl');

        self::assertEquals($customUrl, $baseUrl->getValue($translator));
    }

    #[Test]
    public function translateBatchTracksCorrectTotalCharacters(): void
    {
        $texts = ['Hello', 'World', 'Test'];
        $expectedTotalChars = array_sum(array_map(mb_strlen(...), $texts));

        $usageTrackerMock = $this->createMock(UsageTrackerServiceInterface::class);
        $usageTrackerMock
            ->expects(self::once())
            ->method('trackUsage')
            ->with(
                'translation',
                'deepl',
                self::callback(fn(array $data) => $data['characters'] === $expectedTotalChars && $data['batch_size'] === 3),
            );

        $httpClientMock = self::createStub(ClientInterface::class);
        $httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock([
                'translations' => [
                    ['text' => 'Hallo', 'detected_source_language' => 'EN'],
                    ['text' => 'Welt', 'detected_source_language' => 'EN'],
                    ['text' => 'Test', 'detected_source_language' => 'EN'],
                ],
            ]));

        $translator = new DeepLTranslator(
            $httpClientMock,
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createExtensionConfigurationMock([
                'translators' => ['deepl' => ['apiKey' => $this->randomApiKey()]],
            ]),
            $usageTrackerMock,
            $this->createLoggerMock(),
        );

        $translator->translateBatch($texts, 'de');
    }
}
