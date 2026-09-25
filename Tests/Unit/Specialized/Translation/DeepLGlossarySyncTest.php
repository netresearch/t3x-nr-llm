<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Translation;

use Closure;
use Netresearch\NrLlm\Domain\ValueObject\GlossaryTerms;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Service\Feature\TranslationPromptBuilder;
use Netresearch\NrLlm\Service\Feature\TranslationService;
use Netresearch\NrLlm\Service\Glossary\GlossaryResolverInterface;
use Netresearch\NrLlm\Service\Glossary\ResolvedGlossary;
use Netresearch\NrLlm\Service\Guardrail\InputGuardrailScreener;
use Netresearch\NrLlm\Service\LlmConfigurationServiceInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\TranslationOptions;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Pricing\SpecializedCostCalculatorInterface;
use Netresearch\NrLlm\Specialized\Translation\DeepLGlossarySync;
use Netresearch\NrLlm\Specialized\Translation\DeepLTranslator;
use Netresearch\NrLlm\Specialized\Translation\TranslatorRegistryInterface;
use Netresearch\NrLlm\Tests\Fixture\AllowingBudgetService;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Throwable;

/**
 * The DeepL half of the site-glossary handoff (ADR-208): the v2 glossary
 * endpoints on DeepLTranslator, and the sync that keeps one DeepL glossary per
 * glossary record. HTTP is scripted at the PSR-18 client, in the style of
 * DeepLTranslatorTest, so the requests asserted are the ones DeepL would see.
 */
#[AllowMockObjectsWithoutExpectations]
#[CoversClass(DeepLGlossarySync::class)]
#[CoversClass(DeepLTranslator::class)]
#[CoversClass(TranslationService::class)]
final class DeepLGlossarySyncTest extends AbstractUnitTestCase
{
    /** @var list<array{method: string, uri: string, body: ?string}> */
    private array $requests = [];

    /** @var list<ResponseInterface> */
    private array $responses = [];

    /** @var list<array{level: string, message: string}> */
    private array $logged = [];

    /** @var list<array{uid: int, id: string, hash: string}> */
    private array $stored = [];

    /** @var array<string, bool> DeepL ids another record still holds */
    private array $referencedElsewhere = [];

    private ?GlossaryResolverInterface $lastResolver = null;

    // ==================== DeepLTranslator endpoints ====================

    #[Test]
    public function createGlossaryPostsTheTsvEntriesWithBaseLanguageCodes(): void
    {
        $this->responses = [$this->createJsonResponseMock(['glossary_id' => 'gls_test_1', 'ready' => true], 201)];

        $id = $this->translator()->createGlossary('nr_llm glossary 7', 'de-DE', 'EN-GB', "Warenkorb\tshopping cart");

        self::assertSame('gls_test_1', $id);
        self::assertCount(1, $this->requests);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame('https://api.deepl.com/v2/glossaries', $this->requests[0]['uri']);
        self::assertSame(
            [
                'name' => 'nr_llm glossary 7',
                'source_lang' => 'de',
                'target_lang' => 'en',
                'entries' => "Warenkorb\tshopping cart",
                'entries_format' => 'tsv',
            ],
            json_decode((string)$this->requests[0]['body'], true),
        );
    }

    #[Test]
    public function createGlossaryRefusesAnAnswerWithoutAnId(): void
    {
        $this->responses = [$this->createJsonResponseMock(['ready' => true], 201)];

        $this->expectException(ServiceUnavailableException::class);

        $this->translator()->createGlossary('nr_llm glossary 7', 'de', 'en', "a\tb");
    }

    #[Test]
    public function deleteGlossarySendsADeleteForThatId(): void
    {
        $this->responses = [$this->createHttpResponseMock(204, '')];

        $this->translator()->deleteGlossary('gls_test_1');

        self::assertSame('DELETE', $this->requests[0]['method']);
        self::assertSame('https://api.deepl.com/v2/glossaries/gls_test_1', $this->requests[0]['uri']);
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function languagePairs(): iterable
    {
        yield 'German to English' => ['de', 'en', true];
        yield 'regional variants count as their base' => ['de-DE', 'en-GB', true];
        yield 'Hebrew and Vietnamese are glossary languages' => ['he', 'vi', true];
        yield 'legacy code for Norwegian' => ['no', 'de', true];
        yield 'Thai is not' => ['th', 'en', false];
        yield 'unknown target' => ['de', 'xx', false];
        yield 'same language twice' => ['de', 'de-AT', false];
    }

    #[Test]
    #[DataProvider('languagePairs')]
    public function knowsWhichPairsDeepLHoldsGlossariesFor(string $source, string $target, bool $expected): void
    {
        self::assertSame($expected, $this->translator()->supportsGlossaryLanguagePair($source, $target));
    }

    // ==================== DeepLGlossarySync ====================

    #[Test]
    public function aFirstTranslationCreatesTheGlossaryAndStoresItsIdWithTheHash(): void
    {
        $this->responses = [$this->createJsonResponseMock(['glossary_id' => 'gls_test_1'], 201)];
        $glossary = $this->glossary();

        $id = $this->sync()->glossaryIdFor($glossary);

        self::assertSame('gls_test_1', $id);
        self::assertCount(1, $this->requests);
        self::assertSame([['uid' => 7, 'id' => 'gls_test_1', 'hash' => $glossary->entriesHash()]], $this->stored);
    }

    #[Test]
    public function anUnchangedTermListReusesTheStoredGlossaryWithoutARequest(): void
    {
        $current = $this->glossary();
        $glossary = $this->glossary('gls_test_1', $current->entriesHash());

        self::assertSame('gls_test_1', $this->sync()->glossaryIdFor($glossary));
        self::assertSame([], $this->requests);
        self::assertSame([], $this->stored);
    }

    #[Test]
    public function aChangedTermListCreatesANewGlossaryAndDeletesTheSupersededOne(): void
    {
        $this->responses = [
            $this->createJsonResponseMock(['glossary_id' => 'gls_test_2'], 201),
            $this->createHttpResponseMock(204, ''),
        ];
        $glossary = $this->glossary('gls_test_1', 'hash-of-the-old-terms');

        $id = $this->sync()->glossaryIdFor($glossary);

        self::assertSame('gls_test_2', $id);
        self::assertSame([['uid' => 7, 'id' => 'gls_test_2', 'hash' => $glossary->entriesHash()]], $this->stored);
        self::assertCount(2, $this->requests);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame('DELETE', $this->requests[1]['method']);
        self::assertSame('https://api.deepl.com/v2/glossaries/gls_test_1', $this->requests[1]['uri']);
    }

    #[Test]
    public function aSupersededGlossaryAnotherRecordStillUsesIsKept(): void
    {
        $this->responses = [$this->createJsonResponseMock(['glossary_id' => 'gls_test_2'], 201)];
        $this->referencedElsewhere = ['gls_test_1' => true];

        $this->sync()->glossaryIdFor($this->glossary('gls_test_1', 'hash-of-the-old-terms'));

        self::assertCount(1, $this->requests);
        self::assertSame('POST', $this->requests[0]['method']);
    }

    #[Test]
    public function aFailedCleanupIsLoggedAndDoesNotFailTheTranslation(): void
    {
        $this->responses = [
            $this->createJsonResponseMock(['glossary_id' => 'gls_test_2'], 201),
            $this->createJsonResponseMock(['message' => 'Glossary not found'], 404),
        ];

        $id = $this->sync()->glossaryIdFor($this->glossary('gls_test_1', 'hash-of-the-old-terms'));

        self::assertSame('gls_test_2', $id);
        self::assertContains(
            ['level' => 'warning', 'message' => 'Could not delete the superseded DeepL glossary'],
            $this->logged,
        );
    }

    #[Test]
    public function anUnsupportedLanguagePairFallsBackToNoGlossaryWithALogLine(): void
    {
        $glossary = new ResolvedGlossary(7, 'th', 'en', GlossaryTerms::fromText('a = b'));

        self::assertNull($this->sync()->glossaryIdFor($glossary));
        self::assertSame([], $this->requests);
        self::assertSame([], $this->stored);
        self::assertContains(
            ['level' => 'info', 'message' => 'DeepL holds no glossary for this language pair; translating without the site glossary'],
            $this->logged,
        );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function refusedCreates(): iterable
    {
        yield 'bad request' => [400];
        yield 'quota exceeded' => [456];
    }

    #[Test]
    #[DataProvider('refusedCreates')]
    public function aRefusedCreatePropagatesAndLeavesTheRecordAlone(int $status): void
    {
        $this->responses = [$this->createJsonResponseMock(['message' => 'Glossary could not be created'], $status)];

        try {
            $this->sync()->glossaryIdFor($this->glossary('gls_test_1', 'hash-of-the-old-terms'));
            self::fail('A refused create did not reach the caller.');
        } catch (ServiceUnavailableException) {
            // expected
        }

        self::assertSame([], $this->stored);
        self::assertCount(1, $this->requests);
        self::assertSame('POST', $this->requests[0]['method']);
    }

    /**
     * @return iterable<string, array{Throwable, bool}>
     */
    public static function translateFailures(): iterable
    {
        $unavailable = static fn(string $message, int $status): ServiceUnavailableException
            => new ServiceUnavailableException($message, 'translation', ['provider' => 'deepl', 'statusCode' => $status]);

        yield '404 naming the glossary' => [$unavailable('DeepL API error: Glossary not found', 404), true];
        yield '400 naming the glossary' => [$unavailable('DeepL API error: Invalid glossary_id', 400), true];
        yield '400 about something else' => [$unavailable('DeepL API error: Value for target_lang not supported', 400), false];
        yield '456 quota' => [new ServiceUnavailableException('DeepL API quota exceeded (glossary)', 'translation', ['provider' => 'deepl']), false];
        yield '500 naming the glossary' => [$unavailable('DeepL API error: glossary backend down', 500), false];
        yield 'not a service error' => [new RuntimeException('glossary'), false];
    }

    #[Test]
    #[DataProvider('translateFailures')]
    public function tellsARejectedGlossaryIdFromOtherFailures(Throwable $failure, bool $stale): void
    {
        self::assertSame($stale, $this->sync()->isStaleGlossaryError($failure));
    }

    #[Test]
    public function aRejectedGlossaryIdIsCreatedAgainAndTheTranslationRetriedOnce(): void
    {
        $current = $this->glossary();
        $glossary = $this->glossary('gls_test_gone', $current->entriesHash());
        $this->responses = [
            $this->createJsonResponseMock(['message' => 'Glossary not found'], 404),
            $this->createJsonResponseMock(['glossary_id' => 'gls_test_2'], 201),
            $this->createJsonResponseMock(['translations' => [['text' => 'The shopping cart is empty.', 'detected_source_language' => 'DE']]]),
        ];

        $result = $this->translationService($glossary)
            ->translateWithTranslator('Der Warenkorb ist leer.', 'en-GB', 'de', (new TranslationOptions())->withSite('main'));

        self::assertSame('The shopping cart is empty.', $result->translatedText);
        self::assertCount(3, $this->requests);
        self::assertSame('gls_test_gone', $this->jsonBody(0)['glossary_id'] ?? null);
        self::assertSame('https://api.deepl.com/v2/glossaries', $this->requests[1]['uri']);
        self::assertSame('gls_test_2', $this->jsonBody(2)['glossary_id'] ?? null);
        // Cleared first, then repointed at the new glossary.
        self::assertSame(
            [['uid' => 7, 'id' => '', 'hash' => ''], ['uid' => 7, 'id' => 'gls_test_2', 'hash' => $current->entriesHash()]],
            $this->stored,
        );
    }

    #[Test]
    public function aSecondRejectionReachesTheCaller(): void
    {
        $current = $this->glossary();
        $this->responses = [
            $this->createJsonResponseMock(['message' => 'Glossary not found'], 404),
            $this->createJsonResponseMock(['glossary_id' => 'gls_test_2'], 201),
            $this->createJsonResponseMock(['message' => 'Glossary not found'], 404),
        ];

        try {
            $this->translationService($this->glossary('gls_test_gone', $current->entriesHash()))
                ->translateWithTranslator('Der Warenkorb ist leer.', 'en', 'de', (new TranslationOptions())->withSite('main'));
            self::fail('The second rejection did not reach the caller.');
        } catch (ServiceUnavailableException $e) {
            self::assertStringContainsString('Glossary not found', $e->getMessage());
        }

        self::assertCount(3, $this->requests);
    }

    #[Test]
    public function aFailureThatIsNotAboutTheGlossaryIsNotRetried(): void
    {
        $current = $this->glossary();
        $this->responses = [
            $this->createJsonResponseMock(['message' => 'Value for target_lang not supported'], 400),
        ];

        try {
            $this->translationService($this->glossary('gls_test_1', $current->entriesHash()))
                ->translateWithTranslator('Der Warenkorb ist leer.', 'en', 'de', (new TranslationOptions())->withSite('main'));
            self::fail('The failure did not reach the caller.');
        } catch (ServiceUnavailableException) {
            // expected
        }

        self::assertCount(1, $this->requests);
        self::assertSame([], $this->stored);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(int $request): array
    {
        $body = json_decode((string)$this->requests[$request]['body'], true);
        self::assertIsArray($body);

        /** @var array<string, mixed> $body */
        return $body;
    }

    private function glossary(string $deeplId = '', string $deeplHash = ''): ResolvedGlossary
    {
        return new ResolvedGlossary(
            7,
            'de',
            'en',
            GlossaryTerms::fromText("Warenkorb = shopping cart\nKasse = checkout"),
            $deeplId,
            $deeplHash,
        );
    }

    private function sync(?ResolvedGlossary $resolves = null, ?DeepLTranslator $translator = null): DeepLGlossarySync
    {
        $onStore = function (int $uid, string $id, string $hash): void {
            $this->stored[] = ['uid' => $uid, 'id' => $id, 'hash' => $hash];
        };
        $resolver = new class ($onStore, $this->referencedElsewhere, $resolves) implements GlossaryResolverInterface {
            /**
             * @param Closure(int, string, string): void $onStore
             * @param array<string, bool>                $referenced
             */
            public function __construct(
                private readonly Closure $onStore,
                private readonly array $referenced,
                private readonly ?ResolvedGlossary $resolves,
            ) {}

            public function resolve(string $siteIdentifier, string $sourceLanguage, string $targetLanguage): ?ResolvedGlossary
            {
                return $this->resolves;
            }

            public function storeDeepLGlossary(int $uid, string $deeplGlossaryId, string $entriesHash): void
            {
                ($this->onStore)($uid, $deeplGlossaryId, $entriesHash);
            }

            public function isDeepLGlossaryReferenced(string $deeplGlossaryId, int $exceptUid): bool
            {
                return $this->referenced[$deeplGlossaryId] ?? false;
            }
        };

        $this->lastResolver = $resolver;

        return new DeepLGlossarySync($translator ?? $this->translator(), $resolver, $this->logger());
    }

    /**
     * TranslationService on the real DeepLTranslator and the real sync, so the
     * retry after a rejected glossary id runs against scripted HTTP answers.
     */
    private function translationService(ResolvedGlossary $glossary): TranslationService
    {
        $translator = $this->translator();
        $sync = $this->sync($glossary, $translator);

        $registry = self::createStub(TranslatorRegistryInterface::class);
        $registry->method('get')->willReturn($translator);

        return new TranslationService(
            self::createStub(LlmServiceManagerInterface::class),
            $registry,
            self::createStub(LlmConfigurationServiceInterface::class),
            new TranslationPromptBuilder(),
            null,
            $this->lastResolver,
            $sync,
        );
    }

    private function translator(): DeepLTranslator
    {
        $client = self::createStub(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(function (): ResponseInterface {
            $response = array_shift($this->responses);
            self::assertInstanceOf(ResponseInterface::class, $response, 'More requests than scripted responses.');

            return $response;
        });

        $translator = new DeepLTranslator(
            $this->createVaultServiceMock(),
            $this->recordingRequestFactory(),
            $this->createStreamFactoryMock(),
            $this->createExtensionConfigurationMock([
                'translators' => ['deepl' => ['apiKeyIdentifier' => 'deepl-key', 'timeout' => 30]],
            ]),
            self::createStub(UsageTrackerServiceInterface::class),
            $this->createLoggerMock(),
            self::createStub(SpecializedCostCalculatorInterface::class),
            new AllowingBudgetService(),
            new MiddlewarePipeline([]),
            new InputGuardrailScreener([]),
        );
        $translator->setHttpClient($client);

        return $translator;
    }

    private function recordingRequestFactory(): RequestFactoryInterface
    {
        $factory = self::createStub(RequestFactoryInterface::class);
        $factory->method('createRequest')->willReturnCallback(function (string $method, string $uri): RequestInterface {
            $index = count($this->requests);
            $this->requests[] = ['method' => $method, 'uri' => $uri, 'body' => null];

            $uriStub = self::createStub(UriInterface::class);
            $uriStub->method('__toString')->willReturn($uri);
            $uriStub->method('getHost')->willReturn((string)parse_url($uri, PHP_URL_HOST));
            $uriStub->method('getPath')->willReturn((string)parse_url($uri, PHP_URL_PATH));

            $request = self::createStub(RequestInterface::class);
            $request->method('withHeader')->willReturnCallback(fn(): Stub => $request);
            $request->method('withoutHeader')->willReturnCallback(fn(): Stub => $request);
            $request->method('withBody')->willReturnCallback(function (StreamInterface $body) use ($request, $index): RequestInterface {
                $this->requests[$index]['body'] = (string)$body;

                return $request;
            });
            $request->method('getMethod')->willReturn($method);
            $request->method('getUri')->willReturn($uriStub);

            return $request;
        });

        return $factory;
    }

    private function logger(): AbstractLogger
    {
        $onLog = function (string $level, string $message): void {
            $this->logged[] = ['level' => $level, 'message' => $message];
        };

        return new class ($onLog) extends AbstractLogger {
            /**
             * @param Closure(string, string): void $onLog
             */
            public function __construct(private readonly Closure $onLog) {}

            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                ($this->onLog)(is_string($level) ? $level : '', (string)$message);
            }
        };
    }
}
