<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Translation;

use LogicException;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Specialized\Exception\ServiceConfigurationException;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Pricing\SpecializedCostCalculatorInterface;
use Netresearch\NrLlm\Specialized\Translation\DeepLTranslator;
use Netresearch\NrLlm\Specialized\Translation\TranslatorResult;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrVault\Http\SecretPlacement;
use Netresearch\NrVault\Http\VaultHttpClientInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use ReflectionClass;

#[CoversClass(DeepLTranslator::class)]
class DeepLTranslatorTest extends AbstractUnitTestCase
{
    private DeepLTranslator $subject;
    private UsageTrackerServiceInterface $usageTrackerStub;

    /** @var array{translators: array{deepl: array{apiKeyIdentifier: string, timeout: int}}} */
    private array $defaultConfig;

    /** The vault identifier the default config points at (resolved Pro endpoint). */
    private string $apiKeyIdentifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usageTrackerStub = self::createStub(UsageTrackerServiceInterface::class);
        $this->apiKeyIdentifier = $this->randomApiKey();

        $this->defaultConfig = [
            'translators' => [
                'deepl' => [
                    'apiKeyIdentifier' => $this->apiKeyIdentifier,
                    'timeout' => 30,
                ],
            ],
        ];

        $this->subject = $this->createSubjectWithResponse(
            $this->createJsonResponseMock(['translations' => []]),
        );
    }

    /**
     * Create a DeepLTranslator with a pre-configured HTTP client response.
     * The translator is wired to a vault mock and the response client is
     * injected through the test seam (bypassing the vault secure client).
     */
    private function createSubjectWithResponse(ResponseInterface $response): DeepLTranslator
    {
        /** @var ClientInterface&MockObject $httpClientStub */
        $httpClientStub = self::createStub(ClientInterface::class);
        $httpClientStub->method('sendRequest')->willReturn($response);

        $translator = new DeepLTranslator(
            $this->createVaultServiceMock(),
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createExtensionConfigurationMock($this->defaultConfig),
            $this->usageTrackerStub,
            $this->createLoggerMock(),
            self::createStub(SpecializedCostCalculatorInterface::class),
        );
        $translator->setHttpClient($httpClientStub);

        return $translator;
    }

    /**
     * Build a DeepLTranslator wired to a vault mock (with the given config) and
     * inject the supplied plain HTTP client through the test seam.
     *
     * @param array<string, mixed> $config
     */
    private function buildTranslator(ClientInterface $httpClient, array $config): DeepLTranslator
    {
        $translator = new DeepLTranslator(
            $this->createVaultServiceMock(),
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createExtensionConfigurationMock($config),
            $this->usageTrackerStub,
            $this->createLoggerMock(),
            self::createStub(SpecializedCostCalculatorInterface::class),
        );
        $translator->setHttpClient($httpClient);

        return $translator;
    }

    #[Test]
    public function getIdentifierReturnsDeepL(): void
    {
        self::assertEquals('deepl', $this->subject->getIdentifier());
    }

    #[Test]
    public function getNameReturnsDeepLTranslation(): void
    {
        self::assertEquals('DeepL Translation', $this->subject->getName());
    }

    /**
     * Assert DeepL exposes the Header placement, `DeepL-Auth-Key ` prefix, and
     * User-Agent the secure client uses to authenticate
     * (DeepL: `Authorization: DeepL-Auth-Key <secret>`). This is the exact
     * non-Bearer scheme the nr-vault prefix option was added for.
     */
    #[Test]
    public function getSecretPlacementUsesHeaderWithDeepLAuthKeyPrefix(): void
    {
        $reflection = new ReflectionClass($this->subject);

        $placementMethod = $reflection->getMethod('getSecretPlacement');
        self::assertSame(SecretPlacement::Header, $placementMethod->invoke($this->subject));

        $optionsMethod = $reflection->getMethod('getSecretPlacementOptions');
        self::assertSame(
            ['headerName' => 'Authorization', 'prefix' => 'DeepL-Auth-Key '],
            $optionsMethod->invoke($this->subject),
        );

        $headersMethod = $reflection->getMethod('getAdditionalHeaders');
        self::assertSame(
            ['User-Agent' => 'TYPO3-NrLlm/1.0'],
            $headersMethod->invoke($this->subject),
        );
    }

    /**
     * Regression: resolveBaseUrl() must not mark itself resolved until baseUrl is
     * actually set. If vault->retrieve() throws, the flag stays false so the next
     * request retries resolution instead of being stuck with an empty base URL.
     */
    #[Test]
    public function resolveBaseUrlRetriesAfterVaultRetrieveFailure(): void
    {
        $vault = $this->createMock(VaultServiceInterface::class);
        $vault->method('exists')->willReturn(true);
        $vault->method('http')->willReturn(self::createStub(VaultHttpClientInterface::class));
        $calls = 0;
        $vault->method('retrieve')->willReturnCallback(static function () use (&$calls): string {
            $calls++;
            if ($calls === 1) {
                throw new LogicException('vault unavailable', 5331512677);
            }

            return 'free-key:fx';
        });

        $config = ['translators' => ['deepl' => ['apiKeyIdentifier' => 'deepl-id']]];
        $translator = new DeepLTranslator(
            $vault,
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createExtensionConfigurationMock($config),
            $this->usageTrackerStub,
            $this->createLoggerMock(),
            self::createStub(SpecializedCostCalculatorInterface::class),
        );

        $reflection = new ReflectionClass($translator);
        $resolve = $reflection->getMethod('resolveBaseUrl');

        // First attempt: retrieve() throws → exception propagates and the base
        // URL must NOT be marked resolved.
        try {
            $resolve->invoke($translator);
            self::fail('Expected the vault failure to propagate');
        } catch (LogicException $e) {
            self::assertSame('vault unavailable', $e->getMessage());
        }
        self::assertFalse($reflection->getProperty('baseUrlResolved')->getValue($translator));

        // Second attempt retries retrieve() and succeeds (Free key → free host).
        $resolve->invoke($translator);
        self::assertTrue($reflection->getProperty('baseUrlResolved')->getValue($translator));
        self::assertSame('https://api-free.deepl.com', $reflection->getProperty('baseUrl')->getValue($translator));
    }

    #[Test]
    public function isAvailableReturnsTrueWhenApiKeyConfigured(): void
    {
        self::assertTrue($this->subject->isAvailable());
    }

    #[Test]
    public function isAvailableReturnsFalseWhenNoApiKey(): void
    {
        $config = [
            'translators' => [
                'deepl' => [
                    'apiKeyIdentifier' => '',
                ],
            ],
        ];

        $translator = $this->buildTranslator($this->createHttpClientMock(), $config);

        self::assertFalse($translator->isAvailable());
    }

    #[Test]
    public function translateReturnsValidResult(): void
    {
        $apiResponse = [
            'translations' => [
                [
                    'text' => 'Hallo Welt',
                    'detected_source_language' => 'EN',
                ],
            ],
        ];

        $httpClientMock = $this->createMock(ClientInterface::class);
        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $subject = $this->buildTranslator($httpClientMock, $this->defaultConfig);

        $result = $subject->translate('Hello World', 'de');

        self::assertInstanceOf(TranslatorResult::class, $result);
        self::assertEquals('Hallo Welt', $result->translatedText);
        self::assertEquals('en', $result->sourceLanguage);
        self::assertEquals('de', $result->targetLanguage);
        self::assertEquals('deepl', $result->translator);
    }

    #[Test]
    public function translateWithSourceLanguage(): void
    {
        $apiResponse = [
            'translations' => [
                [
                    'text' => 'Bonjour le monde',
                    'detected_source_language' => 'EN',
                ],
            ],
        ];

        $httpClientMock = $this->createMock(ClientInterface::class);
        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $subject = $this->buildTranslator($httpClientMock, $this->defaultConfig);

        $result = $subject->translate('Hello World', 'fr', 'en');

        self::assertEquals('Bonjour le monde', $result->translatedText);
        self::assertEquals('fr', $result->targetLanguage);
    }

    #[Test]
    public function translateTracksUsage(): void
    {
        $text = 'Hello World';

        $apiResponse = [
            'translations' => [
                ['text' => 'Hallo Welt', 'detected_source_language' => 'EN'],
            ],
        ];

        $httpClientStub = self::createStub(ClientInterface::class);
        $httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $usageTrackerMock = $this->createMock(UsageTrackerServiceInterface::class);
        $usageTrackerMock
            ->expects(self::once())
            ->method('trackUsage')
            ->with(
                'translation',
                'deepl',
                self::callback(fn(array $data) => $data['characters'] === mb_strlen($text)),
            );

        $subject = new DeepLTranslator(
            $this->createVaultServiceMock(),
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createExtensionConfigurationMock($this->defaultConfig),
            $usageTrackerMock,
            $this->createLoggerMock(),
            self::createStub(SpecializedCostCalculatorInterface::class),
        );
        $subject->setHttpClient($httpClientStub);

        $subject->translate($text, 'de');
    }

    #[Test]
    public function translateThrowsWhenNotAvailable(): void
    {
        $config = ['translators' => ['deepl' => ['apiKeyIdentifier' => '']]];

        $translator = $this->buildTranslator($this->createHttpClientMock(), $config);

        $this->expectException(ServiceUnavailableException::class);

        $translator->translate('Hello', 'de');
    }

    #[Test]
    public function translateBatchReturnsMultipleResults(): void
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

        $subject = $this->buildTranslator($httpClientMock, $this->defaultConfig);

        $results = $subject->translateBatch(['Hello', 'World'], 'de');

        self::assertCount(2, $results);
        self::assertEquals('Hallo', $results[0]->translatedText);
        self::assertEquals('Welt', $results[1]->translatedText);
    }

    #[Test]
    public function translateBatchReturnsEmptyForEmptyInput(): void
    {
        $results = $this->subject->translateBatch([], 'de');

        self::assertEmpty($results);
    }

    #[Test]
    public function getSupportedLanguagesReturnsNonEmptyArray(): void
    {
        $languages = $this->subject->getSupportedLanguages();

        self::assertNotEmpty($languages);
        self::assertContains('en', $languages);
        self::assertContains('de', $languages);
        self::assertContains('fr', $languages);
    }

    #[Test]
    public function detectLanguageReturnsLanguageCode(): void
    {
        $apiResponse = [
            'translations' => [
                ['text' => 'test', 'detected_source_language' => 'DE'],
            ],
        ];

        $subject = $this->createSubjectWithResponse($this->createJsonResponseMock($apiResponse));

        $detected = $subject->detectLanguage('Hallo Welt, wie geht es dir?');

        self::assertEquals('de', $detected);
    }

    #[Test]
    #[DataProvider('languagePairProvider')]
    public function supportsLanguagePairValidatesCorrectly(
        string $source,
        string $target,
        bool $expected,
    ): void {
        $result = $this->subject->supportsLanguagePair($source, $target);

        self::assertEquals($expected, $result);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function languagePairProvider(): array
    {
        return [
            'en to de' => ['en', 'de', true],
            'de to fr' => ['de', 'fr', true],
            'en to en-gb' => ['en', 'en-gb', true],
            'invalid source' => ['xx', 'de', false],
            'invalid target' => ['en', 'xx', false],
        ];
    }

    #[Test]
    #[DataProvider('formalityLanguageProvider')]
    public function supportsFormalityReturnsCorrectValue(string $language, bool $expected): void
    {
        $result = $this->subject->supportsFormality($language);

        self::assertEquals($expected, $result);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function formalityLanguageProvider(): array
    {
        return [
            'german supports' => ['de', true],
            'french supports' => ['fr', true],
            'spanish supports' => ['es', true],
            'english does not' => ['en', false],
            'chinese does not' => ['zh', false],
            'portuguese-br supports' => ['pt-br', true],
        ];
    }

    #[Test]
    public function getUsageReturnsUsageStatistics(): void
    {
        $apiResponse = [
            'character_count' => 50000,
            'character_limit' => 500000,
        ];

        $subject = $this->createSubjectWithResponse($this->createJsonResponseMock($apiResponse));

        $usage = $subject->getUsage();

        self::assertEquals(50000, $usage['character_count']);
        self::assertEquals(500000, $usage['character_limit']);
    }

    #[Test]
    public function getGlossariesReturnsGlossaryList(): void
    {
        $apiResponse = [
            'glossaries' => [
                [
                    'glossary_id' => 'gls_123',
                    'name' => 'Technical Terms',
                    'source_lang' => 'en',
                    'target_lang' => 'de',
                ],
            ],
        ];

        $subject = $this->createSubjectWithResponse($this->createJsonResponseMock($apiResponse));

        $glossaries = $subject->getGlossaries();

        self::assertCount(1, $glossaries);
        self::assertEquals('gls_123', $glossaries[0]['glossary_id']);
    }

    #[Test]
    public function translateHandles401Error(): void
    {
        $subject = $this->createSubjectWithResponse(
            $this->createJsonResponseMock(['error' => 'Unauthorized'], 401),
        );

        $this->expectException(ServiceConfigurationException::class);

        $subject->translate('Hello', 'de');
    }

    #[Test]
    public function translateHandles429RateLimitError(): void
    {
        $subject = $this->createSubjectWithResponse(
            $this->createJsonResponseMock(['message' => 'Rate limit exceeded'], 429),
        );

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('rate limit');

        $subject->translate('Hello', 'de');
    }

    #[Test]
    public function translateHandles456QuotaError(): void
    {
        $subject = $this->createSubjectWithResponse(
            $this->createJsonResponseMock(['message' => 'Quota exceeded'], 456),
        );

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('quota exceeded');

        $subject->translate('Hello', 'de');
    }

    #[Test]
    public function translateThrowsOnEmptyResponse(): void
    {
        $apiResponse = ['translations' => []];

        $subject = $this->createSubjectWithResponse($this->createJsonResponseMock($apiResponse));

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('empty translation');

        $subject->translate('Hello', 'de');
    }

    #[Test]
    public function resultIncludesMetadata(): void
    {
        $apiResponse = [
            'translations' => [
                ['text' => 'Hallo', 'detected_source_language' => 'EN'],
            ],
        ];

        $subject = $this->createSubjectWithResponse($this->createJsonResponseMock($apiResponse));

        $result = $subject->translate('Hello', 'de');

        self::assertNotNull($result->metadata);
        self::assertArrayHasKey('detected_source_language', $result->metadata);
        self::assertArrayHasKey('billed_characters', $result->metadata);
    }

    #[Test]
    public function confidenceIsSetToHighValue(): void
    {
        $apiResponse = [
            'translations' => [
                ['text' => 'Hallo', 'detected_source_language' => 'EN'],
            ],
        ];

        $subject = $this->createSubjectWithResponse($this->createJsonResponseMock($apiResponse));

        $result = $subject->translate('Hello', 'de');

        self::assertEquals(0.95, $result->confidence);
    }
}
