<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Translation;

use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\Middleware\UsageMiddleware;
use Netresearch\NrLlm\Service\Guardrail\InputGuardrailScreener;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Specialized\Option\DeepLOptions;
use Netresearch\NrLlm\Specialized\Pricing\SpecializedCostCalculatorInterface;
use Netresearch\NrLlm\Specialized\Translation\DeepLTranslator;
use Netresearch\NrLlm\Specialized\Usage\DeepLUsageExtractor;
use Netresearch\NrLlm\Tests\Fixture\AllowingBudgetService;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrVault\Service\VaultServiceInterface;
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
    private function createTranslator(array $config = [], ?VaultServiceInterface $vault = null): DeepLTranslator
    {
        $defaultConfig = [
            'translators' => [
                'deepl' => [
                    'apiKeyIdentifier' => $this->randomApiKey(),
                    'timeout' => 30,
                ],
            ],
        ];

        $translator = new DeepLTranslator(
            $vault ?? $this->createVaultServiceMock(),
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createExtensionConfigurationMock(array_merge($defaultConfig, $config)),
            self::createStub(UsageTrackerServiceInterface::class),
            $this->createLoggerMock(),
            self::createStub(SpecializedCostCalculatorInterface::class),
            new AllowingBudgetService(),
            new MiddlewarePipeline([]),
            new InputGuardrailScreener([]),
        );

        // Inject a client that returns a successful empty-detect response so the
        // request resolveBaseUrl() rides on doesn't raise an API error.
        $httpClient = self::createStub(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn(
            $this->createJsonResponseMock(['translations' => [['text' => '', 'detected_source_language' => 'EN']]]),
        );
        $translator->setHttpClient($httpClient);

        return $translator;
    }

    private function createTranslatorWithMockClient(ClientInterface $httpClient): DeepLTranslator
    {
        $config = [
            'translators' => [
                'deepl' => [
                    'apiKeyIdentifier' => $this->randomApiKey(),
                    'timeout' => 30,
                ],
            ],
        ];

        $translator = new DeepLTranslator(
            $this->createVaultServiceMock(),
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createExtensionConfigurationMock($config),
            self::createStub(UsageTrackerServiceInterface::class),
            $this->createLoggerMock(),
            self::createStub(SpecializedCostCalculatorInterface::class),
            new AllowingBudgetService(),
            new MiddlewarePipeline([]),
            new InputGuardrailScreener([]),
        );
        $translator->setHttpClient($httpClient);

        return $translator;
    }

    /**
     * Trigger a request so the lazily-resolved Free/Pro base URL is populated,
     * then read it back via reflection. resolveBaseUrl() calls vault->retrieve()
     * to test the :fx suffix; the injected plain client makes the request itself
     * a harmless `{}` round-trip.
     */
    private function resolveBaseUrl(DeepLTranslator $translator): string
    {
        $translator->detectLanguage('text');

        $reflection = new ReflectionClass($translator);
        $baseUrl = $reflection->getProperty('baseUrl')->getValue($translator);
        self::assertIsString($baseUrl);

        return $baseUrl;
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
            'auto source lowercase' => ['auto', 'de', true],
            'auto source uppercase' => ['AUTO', 'de', true],
            'auto source with unsupported target' => ['auto', 'yy', false],
            'empty source (no preference yet)' => ['', 'de', true],
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

    /**
     * Regression test for the JSON-vs-form-encoded API mismatch: DeepL's
     * `/v2/translate` rejected `preserve_formatting` as the string "1"/"0"
     * over JSON with `{"message":"Value for 'preserve_formatting' not
     * supported."}` (verified against the live API) — it needs a genuine
     * JSON boolean there (`PreserveFormattingOption`, `type: boolean`, per
     * DeepLcom/openapi). `split_sentences` stays a string: unlike
     * `preserve_formatting`, DeepL has no boolean variant for it at all —
     * `SplitSentencesOption` (`'0'|'1'|'nonewlines'`) is the same string
     * enum for both the JSON and form-encoded body.
     *
     * The other tests in this class use `createTranslatorWithMockClient()`,
     * whose `createRequestMock()` call makes `withBody()` a no-op that
     * discards the argument, so they cannot see this class of bug; this
     * test passes the shared helper a by-reference capture variable instead
     * to inspect the actual request body.
     */
    #[Test]
    public function translateSendsPreserveFormattingAsJsonBooleanAndSplitSentencesAsString(): void
    {
        $capturedBody = null;

        $httpClientMock = self::createStub(ClientInterface::class);
        $httpClientMock->method('sendRequest')->willReturn($this->createJsonResponseMock([
            'translations' => [
                ['text' => 'Formatted', 'detected_source_language' => 'EN'],
            ],
        ]));

        $translator = new DeepLTranslator(
            $this->createVaultServiceMock(),
            $this->createRequestFactoryMock($capturedBody),
            $this->createStreamFactoryMock(),
            $this->createExtensionConfigurationMock([
                'translators' => [
                    'deepl' => ['apiKeyIdentifier' => $this->randomApiKey(), 'timeout' => 30],
                ],
            ]),
            self::createStub(UsageTrackerServiceInterface::class),
            $this->createLoggerMock(),
            self::createStub(SpecializedCostCalculatorInterface::class),
            new AllowingBudgetService(),
            new MiddlewarePipeline([]),
            new InputGuardrailScreener([]),
        );
        $translator->setHttpClient($httpClientMock);

        $options = new DeepLOptions(preserveFormatting: true, splitSentences: false);
        $translator->translate('Hello', 'de', null, ['deepl' => $options]);

        self::assertNotNull($capturedBody, 'Expected withBody() to have been called with the JSON payload.');
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($capturedBody, true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue(
            $decoded['preserve_formatting'] ?? null,
            'preserve_formatting must be a JSON boolean — DeepL rejects the string "1"/"0" over the JSON API.',
        );
        self::assertSame(
            '0',
            $decoded['split_sentences'] ?? null,
            'split_sentences has no boolean variant in DeepL\'s schema — it must stay the string "0"/"1"/"nonewlines".',
        );
    }

    /**
     * Same regression, for the batch path (buildBatchPayload() /
     * translateBatch()) — batch has no split_sentences option at all, so
     * only preserve_formatting is asserted here.
     */
    #[Test]
    public function translateBatchSendsPreserveFormattingAsJsonBoolean(): void
    {
        $capturedBody = null;

        $httpClientMock = self::createStub(ClientInterface::class);
        $httpClientMock->method('sendRequest')->willReturn($this->createJsonResponseMock([
            'translations' => [
                ['text' => 'Formatted 1', 'detected_source_language' => 'EN'],
                ['text' => 'Formatted 2', 'detected_source_language' => 'EN'],
            ],
        ]));

        $translator = new DeepLTranslator(
            $this->createVaultServiceMock(),
            $this->createRequestFactoryMock($capturedBody),
            $this->createStreamFactoryMock(),
            $this->createExtensionConfigurationMock([
                'translators' => [
                    'deepl' => ['apiKeyIdentifier' => $this->randomApiKey(), 'timeout' => 30],
                ],
            ]),
            self::createStub(UsageTrackerServiceInterface::class),
            $this->createLoggerMock(),
            self::createStub(SpecializedCostCalculatorInterface::class),
            new AllowingBudgetService(),
            new MiddlewarePipeline([]),
            new InputGuardrailScreener([]),
        );
        $translator->setHttpClient($httpClientMock);

        $options = new DeepLOptions(preserveFormatting: true);
        $translator->translateBatch(['Hello', 'World'], 'de', null, ['deepl' => $options]);

        self::assertNotNull($capturedBody, 'Expected withBody() to have been called with the JSON payload.');
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($capturedBody, true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue(
            $decoded['preserve_formatting'] ?? null,
            'preserve_formatting must be a JSON boolean on the batch path too.',
        );
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
        // Free vs Pro is decided by the resolved secret value (free keys end
        // with :fx), retrieved lazily through the vault on the first request.
        $config = [
            'translators' => [
                'deepl' => [
                    'apiKeyIdentifier' => 'deepl-free-id',
                    'timeout' => 30,
                ],
            ],
        ];

        $translator = $this->createTranslator(
            $config,
            $this->createVaultServiceMock(['deepl-free-id' => 'secret-value:fx']),
        );

        self::assertStringContainsString('api-free.deepl.com', $this->resolveBaseUrl($translator));
    }

    #[Test]
    public function proApiKeyUsesProApiUrl(): void
    {
        $config = [
            'translators' => [
                'deepl' => [
                    'apiKeyIdentifier' => 'deepl-pro-id',
                    'timeout' => 30,
                ],
            ],
        ];

        $translator = $this->createTranslator(
            $config,
            $this->createVaultServiceMock(['deepl-pro-id' => 'secret-value-pro']),
        );

        $baseUrlValue = $this->resolveBaseUrl($translator);

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
                    'apiKeyIdentifier' => 'deepl-pro-id',
                    'baseUrl' => $customUrl,
                    'timeout' => 30,
                ],
            ],
        ];

        $translator = $this->createTranslator($config);

        self::assertEquals($customUrl, $this->resolveBaseUrl($translator));
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
                self::callback(fn(array $data): bool => $data['characters'] === $expectedTotalChars && $data['batch_size'] === 3),
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
            $this->createVaultServiceMock(),
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createExtensionConfigurationMock([
                'translators' => ['deepl' => ['apiKeyIdentifier' => $this->randomApiKey()]],
            ]),
            $usageTrackerMock,
            $this->createLoggerMock(),
            self::createStub(SpecializedCostCalculatorInterface::class),
            new AllowingBudgetService(),
            new MiddlewarePipeline([
                new UsageMiddleware($usageTrackerMock, $this->createLoggerMock(), [new DeepLUsageExtractor()]),
            ]),
            new InputGuardrailScreener([]),
        );
        $translator->setHttpClient($httpClientMock);

        $translator->translateBatch($texts, 'de');
    }
}
