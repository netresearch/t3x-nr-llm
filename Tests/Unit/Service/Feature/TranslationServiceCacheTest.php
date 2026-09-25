<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Feature;

use Netresearch\NrLlm\Domain\ValueObject\GlossaryTerms;
use Netresearch\NrLlm\Service\CacheManager;
use Netresearch\NrLlm\Service\Feature\TranslationPromptBuilder;
use Netresearch\NrLlm\Service\Feature\TranslationService;
use Netresearch\NrLlm\Service\Glossary\GlossaryResolverInterface;
use Netresearch\NrLlm\Service\Glossary\ResolvedGlossary;
use Netresearch\NrLlm\Service\LlmConfigurationServiceInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\TranslationOptions;
use Netresearch\NrLlm\Specialized\Translation\DeepLGlossarySyncInterface;
use Netresearch\NrLlm\Specialized\Translation\TranslatorInterface;
use Netresearch\NrLlm\Specialized\Translation\TranslatorRegistryInterface;
use Netresearch\NrLlm\Specialized\Translation\TranslatorResult;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager as Typo3CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

/**
 * The opt-in translation cache of `translateWithTranslator()` (ADR-209).
 *
 * The translators are counting doubles, and the cache is the real
 * {@see CacheManager} over an in-memory backend: a cache is invisible in
 * results, so the only thing that proves it is how often the translator ran.
 */
#[CoversClass(TranslationService::class)]
final class TranslationServiceCacheTest extends AbstractUnitTestCase
{
    /** @var array<string, int> translator identifier => calls */
    private array $calls = [];

    /** @var list<array<string, mixed>> */
    private array $translatorOptions = [];

    private ?Throwable $nextFailure = null;

    private ?ResolvedGlossary $glossary = null;

    private string $deepLGlossaryId = 'glossary-one';

    #[Test]
    public function aRepeatedIdenticalCallIsTranslatedOnce(): void
    {
        $subject = $this->subject();

        $first  = $subject->translateWithTranslator('Hello world', 'de', 'en', $this->cached('llm'));
        $second = $subject->translateWithTranslator('Hello world', 'de', 'en', $this->cached('llm'));

        self::assertSame(1, $this->calls['llm'] ?? 0);
        self::assertSame('[llm:de] Hello world', $second->translatedText);
        self::assertSame($first->translatedText, $second->translatedText);
        self::assertTrue($second->metadata['cached'] ?? false, 'a hit is marked as one');
        self::assertArrayNotHasKey('cached', $first->metadata ?? []);
    }

    #[Test]
    public function aRepeatedIdenticalDeepLCallIsTranslatedOnce(): void
    {
        $subject = $this->subject();
        $this->glossary = $this->siteGlossary('Welt = Erde');

        $subject->translateWithTranslator('Hello world', 'de', 'en', $this->cached('deepl')->withSite('main'));
        $subject->translateWithTranslator('Hello world', 'de', 'en', $this->cached('deepl')->withSite('main'));

        self::assertSame(1, $this->calls['deepl'] ?? 0);
        self::assertSame('glossary-one', $this->translatorOptions[0]['glossary_id'] ?? null);
    }

    #[Test]
    public function aChangedTextIsTranslatedAgain(): void
    {
        $subject = $this->subject();

        $subject->translateWithTranslator('Hello world', 'de', 'en', $this->cached('llm'));
        $subject->translateWithTranslator('Hello there', 'de', 'en', $this->cached('llm'));

        self::assertSame(2, $this->calls['llm'] ?? 0);
    }

    /**
     * On the LLM path the site glossary's terms are part of the options, so
     * an edited glossary is a different request.
     */
    #[Test]
    public function aChangedSiteGlossaryIsTranslatedAgainOnTheLlmPath(): void
    {
        $subject = $this->subject();

        $this->glossary = $this->siteGlossary('world = Welt');
        $subject->translateWithTranslator('Hello world', 'de', 'en', $this->cached('llm')->withSite('main'));
        $this->glossary = $this->siteGlossary('world = Erde');
        $subject->translateWithTranslator('Hello world', 'de', 'en', $this->cached('llm')->withSite('main'));
        $subject->translateWithTranslator('Hello world', 'de', 'en', $this->cached('llm')->withSite('main'));

        self::assertSame(2, $this->calls['llm'] ?? 0);
    }

    /**
     * On the DeepL path the options carry the id of the DeepL glossary, and a
     * changed term list is synced into a new one (ADR-208).
     */
    #[Test]
    public function aChangedDeepLGlossaryIsTranslatedAgain(): void
    {
        $subject = $this->subject();
        $this->glossary = $this->siteGlossary('world = Welt');

        $subject->translateWithTranslator('Hello world', 'de', 'en', $this->cached('deepl')->withSite('main'));
        $this->deepLGlossaryId = 'glossary-two';
        $subject->translateWithTranslator('Hello world', 'de', 'en', $this->cached('deepl')->withSite('main'));

        self::assertSame(2, $this->calls['deepl'] ?? 0);
    }

    /**
     * @return iterable<string, array{0: TranslationOptions, 1: string, 2: string}>
     */
    public static function otherRequests(): iterable
    {
        yield 'another translator' => [(new TranslationOptions())->withTranslator('deepl')->withCacheTtl(60), 'de', 'en'];
        yield 'another target language' => [(new TranslationOptions())->withTranslator('llm')->withCacheTtl(60), 'fr', 'en'];
        yield 'another source language' => [(new TranslationOptions())->withTranslator('llm')->withCacheTtl(60), 'de', 'nl'];
        yield 'markup instead of text' => [(new TranslationOptions())->withTranslator('llm')->withCacheTtl(60)->withTagHandling('html'), 'de', 'en'];
        yield 'another formality' => [(new TranslationOptions())->withTranslator('llm')->withCacheTtl(60)->withFormality('formal'), 'de', 'en'];
    }

    #[Test]
    #[DataProvider('otherRequests')]
    public function anythingThatChangesTheAnswerIsAnotherEntry(TranslationOptions $other, string $target, string $source): void
    {
        $subject = $this->subject();

        $subject->translateWithTranslator('Hello world', 'de', 'en', $this->cached('llm'));
        $subject->translateWithTranslator('Hello world', $target, $source, $other);

        self::assertSame(2, array_sum($this->calls));
    }

    /**
     * Who asks does not change the answer: the attribution uid stays out of
     * the key, so a second editor gets the first editor's translation.
     */
    #[Test]
    public function theAskingUserIsNotPartOfTheKey(): void
    {
        $subject = $this->subject();

        $subject->translateWithTranslator('Hello world', 'de', 'en', $this->cached('llm')->withBeUserUid(3));
        $subject->translateWithTranslator('Hello world', 'de', 'en', $this->cached('llm')->withBeUserUid(4));

        self::assertSame(1, $this->calls['llm'] ?? 0);
    }

    #[Test]
    public function withoutACacheTtlEveryCallIsTranslated(): void
    {
        $subject = $this->subject();
        $options = (new TranslationOptions())->withTranslator('llm');

        $subject->translateWithTranslator('Hello world', 'de', 'en', $options);
        $subject->translateWithTranslator('Hello world', 'de', 'en', $options);

        self::assertSame(2, $this->calls['llm'] ?? 0);
    }

    #[Test]
    public function aFailedTranslationIsNotStored(): void
    {
        $subject = $this->subject();
        $this->nextFailure = new RuntimeException('translator down');

        try {
            $subject->translateWithTranslator('Hello world', 'de', 'en', $this->cached('llm'));
            self::fail('The failure did not reach the caller.');
        } catch (RuntimeException $e) {
            self::assertSame('translator down', $e->getMessage());
        }

        $result = $subject->translateWithTranslator('Hello world', 'de', 'en', $this->cached('llm'));

        self::assertSame(2, $this->calls['llm'] ?? 0);
        self::assertSame('[llm:de] Hello world', $result->translatedText);
    }

    #[Test]
    public function markupReachesTheTranslatorAsTagHandling(): void
    {
        $subject = $this->subject();

        $subject->translateWithTranslator('<p>Hello</p>', 'de', 'en', $this->cached('deepl')->withTagHandling('html'));

        self::assertSame('html', $this->translatorOptions[0]['tag_handling'] ?? null);
    }

    private function cached(string $translator): TranslationOptions
    {
        return (new TranslationOptions())->withTranslator($translator)->withCacheTtl(60);
    }

    private function siteGlossary(string $entries): ResolvedGlossary
    {
        return new ResolvedGlossary(7, 'en', 'de', GlossaryTerms::fromText($entries));
    }

    private function subject(): TranslationService
    {
        $registry = self::createStub(TranslatorRegistryInterface::class);
        $registry->method('get')->willReturnCallback(fn(string $identifier): TranslatorInterface => $this->countingTranslator($identifier));

        $test     = $this;
        $resolver = new class ($test) implements GlossaryResolverInterface {
            public function __construct(private readonly TranslationServiceCacheTest $test) {}

            public function resolve(string $siteIdentifier, string $sourceLanguage, string $targetLanguage): ?ResolvedGlossary
            {
                return $this->test->currentGlossary();
            }

            public function storeDeepLGlossary(int $uid, string $deeplGlossaryId, string $entriesHash): void {}

            public function isDeepLGlossaryReferenced(string $deeplGlossaryId, int $exceptUid): bool
            {
                return false;
            }
        };

        $sync = new class ($test) implements DeepLGlossarySyncInterface {
            public function __construct(private readonly TranslationServiceCacheTest $test) {}

            public function glossaryIdFor(ResolvedGlossary $glossary): string
            {
                return $this->test->currentDeepLGlossaryId();
            }

            public function isStaleGlossaryError(Throwable $e): bool
            {
                return false;
            }

            public function recreate(ResolvedGlossary $glossary): ?string
            {
                return null;
            }
        };

        // Built through the cache manager's own configuration rather than by
        // constructing the backend: its constructor differs between TYPO3 13.4
        // (a context argument) and 14.3 (none).
        $typo3CacheManager = new Typo3CacheManager();
        $typo3CacheManager->setCacheConfigurations([
            'nrllm_responses' => ['frontend' => VariableFrontend::class, 'backend' => TransientMemoryBackend::class, 'options' => [], 'groups' => []],
        ]);

        return new TranslationService(
            self::createStub(LlmServiceManagerInterface::class),
            $registry,
            self::createStub(LlmConfigurationServiceInterface::class),
            new TranslationPromptBuilder(),
            null,
            $resolver,
            $sync,
            new CacheManager($typo3CacheManager),
        );
    }

    /**
     * @internal read by the resolver double
     */
    public function currentGlossary(): ?ResolvedGlossary
    {
        return $this->glossary;
    }

    /**
     * @internal read by the sync double
     */
    public function currentDeepLGlossaryId(): string
    {
        return $this->deepLGlossaryId;
    }

    private function countingTranslator(string $identifier): TranslatorInterface
    {
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('getIdentifier')->willReturn($identifier);
        $translator->method('translate')->willReturnCallback(
            function (string $text, string $target, ?string $source, array $options) use ($identifier): TranslatorResult {
                $this->calls[$identifier] = ($this->calls[$identifier] ?? 0) + 1;
                /** @var array<string, mixed> $options */
                $this->translatorOptions[] = $options;

                if ($this->nextFailure instanceof Throwable) {
                    $failure           = $this->nextFailure;
                    $this->nextFailure = null;

                    throw $failure;
                }

                return new TranslatorResult(
                    sprintf('[%s:%s] %s', $identifier, $target, $text),
                    $source ?? 'en',
                    $target,
                    $identifier,
                    charactersUsed: mb_strlen($text),
                );
            },
        );

        return $translator;
    }
}
