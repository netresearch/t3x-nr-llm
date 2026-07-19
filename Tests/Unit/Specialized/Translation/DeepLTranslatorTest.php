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
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
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

    /**
     * Build a DeepLTranslator whose request factory records the outgoing
     * method, URI, and (POST) JSON body into $captured so a test can assert the
     * exact request DeepLTranslator constructs. Uses the default config
     * (non-`:fx` vault secret → Pro host) unless a config override is supplied.
     *
     * @param array<string, mixed>      $captured Populated in place; initialize to [] at the call site.
     * @param array<string, mixed>|null $config
     */
    private function createCapturingSubject(
        ResponseInterface $response,
        array &$captured,
        ?array $config = null,
    ): DeepLTranslator {
        $httpClientStub = self::createStub(ClientInterface::class);
        $httpClientStub->method('sendRequest')->willReturn($response);

        $translator = new DeepLTranslator(
            $this->createVaultServiceMock(),
            $this->createCapturingRequestFactory($captured),
            $this->createStreamFactoryMock(),
            $this->createExtensionConfigurationMock($config ?? $this->defaultConfig),
            $this->usageTrackerStub,
            $this->createLoggerMock(),
            self::createStub(SpecializedCostCalculatorInterface::class),
        );
        $translator->setHttpClient($httpClientStub);

        return $translator;
    }

    /**
     * A request factory that mirrors the base createRequestFactoryMock() but
     * records the method/URI it is asked to build and the body streamed onto
     * the request. This is the seam the exact-transformation assertions read.
     *
     * @param array<string, mixed> $captured Populated in place; initialize to [] at the call site.
     */
    private function createCapturingRequestFactory(array &$captured): RequestFactoryInterface
    {
        $stub = self::createStub(RequestFactoryInterface::class);
        $stub->method('createRequest')->willReturnCallback(
            function (string $method, string $uri) use (&$captured): RequestInterface {
                $captured['method'] = $method;
                $captured['uri'] = $uri;

                $uriStub = self::createStub(UriInterface::class);
                $uriStub->method('__toString')->willReturn($uri);
                $uriStub->method('getHost')->willReturn(parse_url($uri, PHP_URL_HOST) ?? '');
                $uriStub->method('getPath')->willReturn(parse_url($uri, PHP_URL_PATH) ?? '');

                $request = self::createStub(RequestInterface::class);
                $request->method('withHeader')->willReturnCallback(fn() => $request);
                $request->method('withoutHeader')->willReturnCallback(fn() => $request);
                $request->method('withBody')->willReturnCallback(
                    function (StreamInterface $body) use (&$captured, &$request): RequestInterface {
                        $captured['body'] = (string)$body;

                        return $request;
                    },
                );
                $request->method('getMethod')->willReturn($method);
                $request->method('getUri')->willReturn($uriStub);

                return $request;
            },
        );

        return $stub;
    }

    /**
     * Decode the captured JSON request body into an associative array.
     *
     * @param array<string, mixed> $captured
     *
     * @return array<string, mixed>
     */
    private function decodeCapturedBody(array $captured): array
    {
        self::assertArrayHasKey('body', $captured);
        $body = $captured['body'];
        self::assertIsString($body);

        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
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

    /**
     * A malformed 2xx body (from a broken upstream or an intermediary proxy)
     * whose translation entry is not an object or lacks a string `text` must
     * raise the typed service error, not a raw TypeError -> 500.
     *
     * @param mixed $translationEntry the untrusted `translations[0]` value
     */
    #[Test]
    #[DataProvider('malformedTranslationEntryProvider')]
    public function translateThrowsServiceUnavailableOnMalformedEntry(mixed $translationEntry): void
    {
        $httpClientStub = self::createStub(ClientInterface::class);
        $httpClientStub->method('sendRequest')->willReturn(
            $this->createJsonResponseMock(['translations' => [$translationEntry]]),
        );
        $subject = $this->buildTranslator($httpClientStub, $this->defaultConfig);

        $this->expectException(ServiceUnavailableException::class);

        $subject->translate('Hello World', 'de');
    }

    #[Test]
    #[DataProvider('malformedTranslationEntryProvider')]
    public function translateBatchThrowsServiceUnavailableOnMalformedEntry(mixed $translationEntry): void
    {
        $httpClientStub = self::createStub(ClientInterface::class);
        $httpClientStub->method('sendRequest')->willReturn(
            $this->createJsonResponseMock(['translations' => [$translationEntry]]),
        );
        $subject = $this->buildTranslator($httpClientStub, $this->defaultConfig);

        $this->expectException(ServiceUnavailableException::class);

        $subject->translateBatch(['Hello World'], 'de');
    }

    #[Test]
    public function translateThrowsServiceUnavailableWhenTranslationsContainerIsNotArray(): void
    {
        $httpClientStub = self::createStub(ClientInterface::class);
        $httpClientStub->method('sendRequest')->willReturn(
            $this->createJsonResponseMock(['translations' => 'not-an-array']),
        );
        $subject = $this->buildTranslator($httpClientStub, $this->defaultConfig);

        $this->expectException(ServiceUnavailableException::class);

        $subject->translate('Hello World', 'de');
    }

    #[Test]
    public function translateBatchThrowsServiceUnavailableWhenTranslationsContainerIsNotArray(): void
    {
        $httpClientStub = self::createStub(ClientInterface::class);
        $httpClientStub->method('sendRequest')->willReturn(
            $this->createJsonResponseMock(['translations' => 'not-an-array']),
        );
        $subject = $this->buildTranslator($httpClientStub, $this->defaultConfig);

        $this->expectException(ServiceUnavailableException::class);

        $subject->translateBatch(['Hello World'], 'de');
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function malformedTranslationEntryProvider(): array
    {
        return [
            'entry missing text'    => [['detected_source_language' => 'EN']],
            'entry text not string' => [['text' => 123]],
            'entry is a scalar'     => ['plain-string-entry'],
        ];
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
                null,
                0,
                '',
                0,
                // Ambient fallback: no beUserUid option key was passed.
                null,
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
    public function translateForwardsBeUserUidOptionToTracker(): void
    {
        // ADR-052: the `beUserUid` options key (attached by
        // TranslationService) must reach the tracker for attribution.
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
                self::anything(),
                null,
                0,
                '',
                0,
                42,
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

        $subject->translate('Hello World', 'de', null, ['beUserUid' => 42]);
    }

    #[Test]
    public function translateBatchForwardsBeUserUidOptionToTracker(): void
    {
        $apiResponse = [
            'translations' => [
                ['text' => 'Hallo', 'detected_source_language' => 'EN'],
                ['text' => 'Welt', 'detected_source_language' => 'EN'],
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
                self::anything(),
                null,
                0,
                '',
                0,
                42,
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

        $subject->translateBatch(['Hello', 'World'], 'de', null, ['beUserUid' => 42]);
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
            // Base lang 'fr' is formality-supported though 'fr-ca' is not listed:
            // the `||` on base OR full code must hold (an `&&` mutant would fail).
            'french-ca supports via base' => ['fr-ca', true],
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

    #[Test]
    public function translatePostsExactPayloadToProV2TranslateEndpoint(): void
    {
        $captured = [];
        $subject = $this->createCapturingSubject(
            $this->createJsonResponseMock([
                'translations' => [['text' => 'Hallo Welt', 'detected_source_language' => 'DE']],
            ]),
            $captured,
        );

        $subject->translate('Hello World', 'de');

        self::assertSame('POST', $captured['method'] ?? null);
        self::assertSame('https://api.deepl.com/v2/translate', $captured['uri'] ?? null);
        self::assertSame(
            ['text' => ['Hello World'], 'target_lang' => 'DE'],
            $this->decodeCapturedBody($captured),
        );
    }

    #[Test]
    public function translateNormalizesChineseTargetToZhHans(): void
    {
        $captured = [];
        $subject = $this->createCapturingSubject(
            $this->createJsonResponseMock([
                'translations' => [['text' => 'x', 'detected_source_language' => 'EN']],
            ]),
            $captured,
        );

        $result = $subject->translate('Hello', 'zh');

        $body = $this->decodeCapturedBody($captured);
        self::assertSame('ZH-HANS', $body['target_lang'] ?? null);
        self::assertSame('zh-hans', $result->targetLanguage);
    }

    #[Test]
    public function translateNormalizesChineseSourceToZh(): void
    {
        $captured = [];
        $subject = $this->createCapturingSubject(
            $this->createJsonResponseMock([
                'translations' => [['text' => 'x', 'detected_source_language' => 'EN']],
            ]),
            $captured,
        );

        $subject->translate('Hello', 'de', 'zh');

        $body = $this->decodeCapturedBody($captured);
        self::assertSame('ZH', $body['source_lang'] ?? null);
    }

    #[Test]
    public function translateFallsBackToProvidedSourceWhenNotDetected(): void
    {
        // Response omits detected_source_language → the provided (normalized)
        // source language wins over the 'en' literal fallback.
        $subject = $this->createSubjectWithResponse(
            $this->createJsonResponseMock(['translations' => [['text' => 'Hallo']]]),
        );

        $result = $subject->translate('Hello', 'de', 'fr');

        self::assertSame('fr', $result->sourceLanguage);
    }

    #[Test]
    public function translatePrefersDetectedSourceOverProvided(): void
    {
        // detected_source_language must win over the provided source language.
        $subject = $this->createSubjectWithResponse(
            $this->createJsonResponseMock([
                'translations' => [['text' => 'Hallo', 'detected_source_language' => 'ES']],
            ]),
        );

        $result = $subject->translate('Hello', 'de', 'fr');

        self::assertSame('es', $result->sourceLanguage);
    }

    #[Test]
    public function translateTracksMultibyteCharacterCount(): void
    {
        // Multibyte text: mb_strlen (character count) differs from strlen (bytes).
        $text = 'Héllo Wörld';

        $httpClientStub = self::createStub(ClientInterface::class);
        $httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock([
                'translations' => [['text' => 'x', 'detected_source_language' => 'EN']],
            ]));

        $usageTrackerMock = $this->createMock(UsageTrackerServiceInterface::class);
        $usageTrackerMock
            ->expects(self::once())
            ->method('trackUsage')
            ->with(
                'translation',
                'deepl',
                self::callback(fn(array $data) => $data['characters'] === mb_strlen($text)),
                null,
                0,
                '',
                0,
                null,
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
    public function translateEmptyResponseExceptionCarriesProviderContext(): void
    {
        $subject = $this->createSubjectWithResponse(
            $this->createJsonResponseMock(['translations' => []]),
        );

        try {
            $subject->translate('Hello', 'de');
            self::fail('Expected ServiceUnavailableException');
        } catch (ServiceUnavailableException $e) {
            self::assertSame(['provider' => 'deepl'], $e->context);
        }
    }

    #[Test]
    public function translateThrowsWhenUnavailableEvenWithSuccessfulResponse(): void
    {
        // A valid response is wired so the availability guard is the ONLY reason
        // to throw: if the guard were skipped the call would succeed instead.
        $httpClientStub = self::createStub(ClientInterface::class);
        $httpClientStub->method('sendRequest')->willReturn($this->createJsonResponseMock([
            'translations' => [['text' => 'x', 'detected_source_language' => 'EN']],
        ]));
        $translator = $this->buildTranslator(
            $httpClientStub,
            ['translators' => ['deepl' => ['apiKeyIdentifier' => '']]],
        );

        $this->expectException(ServiceUnavailableException::class);

        $translator->translate('Hello', 'de');
    }

    #[Test]
    public function translateBatchThrowsWhenUnavailableEvenWithSuccessfulResponse(): void
    {
        $httpClientStub = self::createStub(ClientInterface::class);
        $httpClientStub->method('sendRequest')->willReturn($this->createJsonResponseMock([
            'translations' => [['text' => 'x', 'detected_source_language' => 'EN']],
        ]));
        $translator = $this->buildTranslator(
            $httpClientStub,
            ['translators' => ['deepl' => ['apiKeyIdentifier' => '']]],
        );

        $this->expectException(ServiceUnavailableException::class);

        $translator->translateBatch(['Hello'], 'de');
    }

    #[Test]
    public function translateBatchDoesNotCallHttpForEmptyInput(): void
    {
        $httpClientMock = $this->createMock(ClientInterface::class);
        $httpClientMock->expects(self::never())->method('sendRequest');

        $translator = $this->buildTranslator($httpClientMock, $this->defaultConfig);

        $results = $translator->translateBatch([], 'de');

        self::assertSame([], $results);
    }

    #[Test]
    public function translateBatchBuildsPayloadWithNormalizedCodes(): void
    {
        $captured = [];
        $subject = $this->createCapturingSubject(
            $this->createJsonResponseMock([
                'translations' => [['text' => 'a', 'detected_source_language' => 'EN']],
            ]),
            $captured,
        );

        $subject->translateBatch(['Hello', 'World'], 'zh', 'zh');

        self::assertSame('POST', $captured['method'] ?? null);
        self::assertSame('https://api.deepl.com/v2/translate', $captured['uri'] ?? null);
        self::assertSame(
            ['text' => ['Hello', 'World'], 'target_lang' => 'ZH-HANS', 'source_lang' => 'ZH'],
            $this->decodeCapturedBody($captured),
        );
    }

    #[Test]
    public function translateBatchResultUsesLowercaseTargetAndMetadata(): void
    {
        $subject = $this->createSubjectWithResponse(
            $this->createJsonResponseMock([
                'translations' => [['text' => 'Ni hao', 'detected_source_language' => 'EN']],
            ]),
        );

        $results = $subject->translateBatch(['Hello'], 'zh');

        self::assertSame('zh-hans', $results[0]->targetLanguage);
        self::assertSame('en', $results[0]->sourceLanguage);
        self::assertNotNull($results[0]->metadata);
        self::assertArrayHasKey('detected_source_language', $results[0]->metadata);
    }

    #[Test]
    public function translateBatchFallsBackToProvidedSourceWhenNotDetected(): void
    {
        $subject = $this->createSubjectWithResponse(
            $this->createJsonResponseMock(['translations' => [['text' => 'Hallo']]]),
        );

        $results = $subject->translateBatch(['Hello'], 'de', 'fr');

        self::assertSame('fr', $results[0]->sourceLanguage);
    }

    #[Test]
    public function translateBatchPrefersDetectedSourceOverProvided(): void
    {
        $subject = $this->createSubjectWithResponse(
            $this->createJsonResponseMock([
                'translations' => [['text' => 'Hallo', 'detected_source_language' => 'ES']],
            ]),
        );

        $results = $subject->translateBatch(['Hello'], 'de', 'fr');

        self::assertSame('es', $results[0]->sourceLanguage);
    }

    #[Test]
    public function translateBatchTracksMultibyteTotalCharacters(): void
    {
        $texts = ['Héllo', 'Wörld'];
        $expectedCharacters = array_sum(array_map(mb_strlen(...), $texts));

        $httpClientStub = self::createStub(ClientInterface::class);
        $httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock([
                'translations' => [
                    ['text' => 'a', 'detected_source_language' => 'EN'],
                    ['text' => 'b', 'detected_source_language' => 'EN'],
                ],
            ]));

        $usageTrackerMock = $this->createMock(UsageTrackerServiceInterface::class);
        $usageTrackerMock
            ->expects(self::once())
            ->method('trackUsage')
            ->with(
                'translation',
                'deepl',
                self::callback(
                    fn(array $data) => $data['characters'] === $expectedCharacters && $data['batch_size'] === 2,
                ),
                null,
                0,
                '',
                0,
                null,
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

        $subject->translateBatch($texts, 'de');
    }

    #[Test]
    public function getSupportedLanguagesStripsRegionalTargetVariants(): void
    {
        $languages = $this->subject->getSupportedLanguages();

        // Target codes are base-stripped (en-gb → en), so no regional variant leaks through.
        self::assertNotContains('en-gb', $languages);
        self::assertNotContains('pt-br', $languages);
        self::assertNotContains('zh-hans', $languages);
    }

    #[Test]
    public function detectLanguageSendsTruncatedTextPayload(): void
    {
        // 150 distinct-position chars so every substr offset/length mutant differs.
        $text = str_repeat('0123456789', 15);

        $captured = [];
        $subject = $this->createCapturingSubject(
            $this->createJsonResponseMock([
                'translations' => [['text' => 'x', 'detected_source_language' => 'DE']],
            ]),
            $captured,
        );

        $subject->detectLanguage($text);

        self::assertSame('POST', $captured['method'] ?? null);
        self::assertSame('https://api.deepl.com/v2/translate', $captured['uri'] ?? null);
        self::assertSame(
            ['text' => [substr($text, 0, 100)], 'target_lang' => 'EN'],
            $this->decodeCapturedBody($captured),
        );
    }

    #[Test]
    public function detectLanguageReturnsEnForEmptyTranslations(): void
    {
        $subject = $this->createSubjectWithResponse(
            $this->createJsonResponseMock(['translations' => []]),
        );

        self::assertSame('en', $subject->detectLanguage('anything'));
    }

    #[Test]
    public function detectLanguageThrowsWhenUnavailableEvenWithSuccessfulResponse(): void
    {
        $httpClientStub = self::createStub(ClientInterface::class);
        $httpClientStub->method('sendRequest')->willReturn($this->createJsonResponseMock([
            'translations' => [['text' => 'x', 'detected_source_language' => 'DE']],
        ]));
        $translator = $this->buildTranslator(
            $httpClientStub,
            ['translators' => ['deepl' => ['apiKeyIdentifier' => '']]],
        );

        $this->expectException(ServiceUnavailableException::class);

        $translator->detectLanguage('Hello');
    }

    #[Test]
    public function getUsageThrowsWhenUnavailableEvenWithSuccessfulResponse(): void
    {
        $httpClientStub = self::createStub(ClientInterface::class);
        $httpClientStub->method('sendRequest')->willReturn($this->createJsonResponseMock([
            'character_count' => 1,
            'character_limit' => 2,
        ]));
        $translator = $this->buildTranslator(
            $httpClientStub,
            ['translators' => ['deepl' => ['apiKeyIdentifier' => '']]],
        );

        $this->expectException(ServiceUnavailableException::class);

        $translator->getUsage();
    }

    #[Test]
    public function getUsageReturnsZeroForNonIntegerValues(): void
    {
        $subject = $this->createSubjectWithResponse(
            $this->createJsonResponseMock([
                'character_count' => 'not-an-int',
                'character_limit' => 'also-not',
            ]),
        );

        $usage = $subject->getUsage();

        self::assertSame(0, $usage['character_count']);
        self::assertSame(0, $usage['character_limit']);
    }

    #[Test]
    public function getGlossariesThrowsWhenUnavailableEvenWithSuccessfulResponse(): void
    {
        $httpClientStub = self::createStub(ClientInterface::class);
        $httpClientStub->method('sendRequest')->willReturn($this->createJsonResponseMock([
            'glossaries' => [],
        ]));
        $translator = $this->buildTranslator(
            $httpClientStub,
            ['translators' => ['deepl' => ['apiKeyIdentifier' => '']]],
        );

        $this->expectException(ServiceUnavailableException::class);

        $translator->getGlossaries();
    }

    #[Test]
    public function getGlossariesReturnsAllGlossaries(): void
    {
        $subject = $this->createSubjectWithResponse(
            $this->createJsonResponseMock([
                'glossaries' => [
                    ['glossary_id' => 'gls_1', 'name' => 'A', 'source_lang' => 'en', 'target_lang' => 'de'],
                    ['glossary_id' => 'gls_2', 'name' => 'B', 'source_lang' => 'de', 'target_lang' => 'en'],
                ],
            ]),
        );

        $glossaries = $subject->getGlossaries();

        self::assertCount(2, $glossaries);
        self::assertSame('gls_1', $glossaries[0]['glossary_id']);
        self::assertSame('gls_2', $glossaries[1]['glossary_id']);
    }

    #[Test]
    public function defaultTimeoutIsThirtySecondsWhenConfigOmitsIt(): void
    {
        $translator = $this->buildTranslator(
            $this->createHttpClientMock(),
            ['translators' => ['deepl' => ['apiKeyIdentifier' => $this->apiKeyIdentifier]]],
        );

        $timeout = (new ReflectionClass($translator))->getProperty('timeout')->getValue($translator);

        self::assertSame(30, $timeout);
    }

    #[Test]
    public function configuredTimeoutIsCastToInteger(): void
    {
        $translator = $this->buildTranslator(
            $this->createHttpClientMock(),
            ['translators' => ['deepl' => ['apiKeyIdentifier' => $this->apiKeyIdentifier, 'timeout' => '45']]],
        );

        $timeout = (new ReflectionClass($translator))->getProperty('timeout')->getValue($translator);

        self::assertSame(45, $timeout);
    }

    #[Test]
    public function nonArrayDeeplConfigIsIgnored(): void
    {
        // translators is an array but its deepl entry is not: the service must
        // treat the config as absent (empty api key → unavailable), not read
        // into the scalar.
        $translator = $this->buildTranslator(
            $this->createHttpClientMock(),
            ['translators' => ['deepl' => 123]],
        );

        self::assertFalse($translator->isAvailable());
    }
}
