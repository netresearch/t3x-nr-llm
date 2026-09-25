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
use Netresearch\NrLlm\Domain\ValueObject\GlossaryTerms;
use Netresearch\NrLlm\Service\Feature\TranslationPromptBuilder;
use Netresearch\NrLlm\Service\Feature\TranslationService;
use Netresearch\NrLlm\Service\Glossary\GlossaryResolverInterface;
use Netresearch\NrLlm\Service\Glossary\ResolvedGlossary;
use Netresearch\NrLlm\Service\LlmConfigurationServiceInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\TranslationOptions;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Translation\DeepLGlossarySyncInterface;
use Netresearch\NrLlm\Specialized\Translation\TranslatorInterface;
use Netresearch\NrLlm\Specialized\Translation\TranslatorRegistryInterface;
use Netresearch\NrLlm\Specialized\Translation\TranslatorResult;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

/**
 * TranslationService applies the caller's site glossary when the caller passed
 * no glossary of its own (ADR-208): as prompt terms on the LLM paths, as a
 * DeepL glossary id on the DeepL path.
 */
#[AllowMockObjectsWithoutExpectations]
#[CoversClass(TranslationService::class)]
final class TranslationServiceSiteGlossaryTest extends AbstractUnitTestCase
{
    /** @var list<array{site: string, source: string, target: string}> */
    private array $lookups = [];

    /** @var list<ResolvedGlossary> */
    private array $synced = [];

    /** @var list<array<int, ChatMessage>> */
    private array $sentMessages = [];

    /** @var list<array<string, mixed>> */
    private array $translatorOptions = [];

    #[Test]
    public function theLlmPathPutsTheSiteTermsIntoThePrompt(): void
    {
        $subject = $this->subject($this->glossary());

        $subject->translate('Der Warenkorb ist leer.', 'en', 'de', (new TranslationOptions())->withSite('main'));

        self::assertSame([['site' => 'main', 'source' => 'de', 'target' => 'en']], $this->lookups);
        self::assertCount(1, $this->sentMessages);
        self::assertStringContainsString('- Warenkorb → shopping cart', $this->sentMessages[0][0]->content);
    }

    #[Test]
    public function theLlmPathLooksTheGlossaryUpForTheDetectedSourceLanguage(): void
    {
        // No source language given: the service detects one first, and only
        // then can it know which glossary applies.
        $subject = $this->subject($this->glossary(), detectedLanguage: 'de');

        $subject->translate('Der Warenkorb ist leer.', 'en', null, (new TranslationOptions())->withSite('main'));

        self::assertSame([['site' => 'main', 'source' => 'de', 'target' => 'en']], $this->lookups);
        self::assertStringContainsString('- Warenkorb → shopping cart', $this->lastPromptText());
    }

    #[Test]
    public function anExplicitGlossaryWinsAndTheSiteIsNotConsulted(): void
    {
        $subject = $this->subject($this->glossary());

        $subject->translate(
            'Der Warenkorb ist leer.',
            'en',
            'de',
            (new TranslationOptions())->withSite('main')->withGlossary(['Warenkorb' => 'basket']),
        );

        self::assertSame([], $this->lookups);
        self::assertStringContainsString('- Warenkorb → basket', $this->lastPromptText());
        self::assertStringNotContainsString('shopping cart', $this->lastPromptText());
    }

    #[Test]
    public function withoutASiteNoGlossaryIsLookedUp(): void
    {
        $subject = $this->subject($this->glossary());

        $subject->translate('Der Warenkorb ist leer.', 'en', 'de');

        self::assertSame([], $this->lookups);
        self::assertStringNotContainsString('exact term translations', $this->lastPromptText());
    }

    #[Test]
    public function theConfigurationPathPutsTheSiteTermsIntoTheUserMessage(): void
    {
        $subject = $this->subject($this->glossary());

        $subject->translateForConfiguration(
            'Der Warenkorb ist leer.',
            'en',
            new LlmConfiguration(),
            'de',
            (new TranslationOptions())->withSite('main'),
        );

        self::assertStringContainsString('- Warenkorb → shopping cart', $this->lastPromptText());
    }

    #[Test]
    public function theDeepLPathReceivesTheIdOfTheSyncedGlossary(): void
    {
        $subject = $this->subject($this->glossary(), translatorIdentifier: 'deepl', syncedId: 'gls_test_1');

        $subject->translateWithTranslator('Der Warenkorb ist leer.', 'en', 'de', (new TranslationOptions())->withSite('main'));

        self::assertCount(1, $this->synced);
        self::assertSame(7, $this->synced[0]->uid);
        self::assertSame('gls_test_1', $this->translatorOptions[0]['glossary_id'] ?? null);
        self::assertArrayNotHasKey('glossary', $this->translatorOptions[0]);
    }

    #[Test]
    public function theDeepLPathTranslatesWithoutAGlossaryWhenTheSyncYieldsNone(): void
    {
        // An unsupported language pair: the sync returns null, and the
        // translation still runs.
        $subject = $this->subject($this->glossary(), translatorIdentifier: 'deepl');

        $result = $subject->translateWithTranslator('Der Warenkorb ist leer.', 'en', 'de', (new TranslationOptions())->withSite('main'));

        self::assertSame('translated', $result->translatedText);
        self::assertCount(1, $this->synced);
        self::assertArrayNotHasKey('glossary_id', $this->translatorOptions[0]);
    }

    #[Test]
    public function aFailedDeepLGlossaryCreateFailsTheTranslation(): void
    {
        // A glossary DeepL refuses to create must not turn into a translation
        // that silently ignores the site's terms (ADR-208).
        $failure = new ServiceUnavailableException('DeepL API error: Too many glossaries', 'translation', ['statusCode' => 400]);
        $subject = $this->subject($this->glossary(), translatorIdentifier: 'deepl', syncFailure: $failure);

        try {
            $subject->translateWithTranslator('Der Warenkorb ist leer.', 'en', 'de', (new TranslationOptions())->withSite('main'));
            self::fail('The failed glossary create did not reach the caller.');
        } catch (ServiceUnavailableException $e) {
            self::assertSame($failure, $e);
        }

        self::assertSame([], $this->translatorOptions);
    }

    #[Test]
    public function theDeepLBatchPathReceivesTheIdToo(): void
    {
        $subject = $this->subject($this->glossary(), translatorIdentifier: 'deepl', syncedId: 'gls_test_1');

        $subject->translateBatchWithTranslator(['Warenkorb'], 'en', 'de', (new TranslationOptions())->withSite('main'));

        self::assertSame('gls_test_1', $this->translatorOptions[0]['glossary_id'] ?? null);
    }

    #[Test]
    public function anyOtherTranslatorReceivesTheTermsUnderTheGlossaryKey(): void
    {
        $subject = $this->subject($this->glossary(), translatorIdentifier: 'llm');

        $subject->translateWithTranslator('Der Warenkorb ist leer.', 'en', 'de', (new TranslationOptions())->withSite('main'));

        self::assertSame([], $this->synced);
        self::assertSame(['Warenkorb' => 'shopping cart'], $this->translatorOptions[0]['glossary'] ?? null);
        self::assertArrayNotHasKey('glossary_id', $this->translatorOptions[0]);
    }

    #[Test]
    public function theTranslatorPathNeedsAnExplicitSourceLanguage(): void
    {
        // Which glossary applies depends on the source language, and DeepL
        // refuses glossary_id without source_lang.
        $subject = $this->subject($this->glossary(), translatorIdentifier: 'deepl', syncedId: 'gls_test_1');

        $subject->translateWithTranslator('Der Warenkorb ist leer.', 'en', null, (new TranslationOptions())->withSite('main'));

        self::assertSame([], $this->lookups);
        self::assertArrayNotHasKey('glossary_id', $this->translatorOptions[0]);
    }

    #[Test]
    public function aSiteWithoutAGlossaryForThePairChangesNothing(): void
    {
        $subject = $this->subject(null, translatorIdentifier: 'deepl', syncedId: 'gls_test_1');

        $subject->translateWithTranslator('Der Warenkorb ist leer.', 'en', 'de', (new TranslationOptions())->withSite('main'));

        self::assertCount(1, $this->lookups);
        self::assertSame([], $this->synced);
        self::assertArrayNotHasKey('glossary_id', $this->translatorOptions[0]);
    }

    private function glossary(): ResolvedGlossary
    {
        return new ResolvedGlossary(7, 'de', 'en', GlossaryTerms::fromText('Warenkorb = shopping cart'));
    }

    private function lastPromptText(): string
    {
        $messages = $this->sentMessages[array_key_last($this->sentMessages) ?? 0] ?? [];

        return implode("\n", array_map(static fn(ChatMessage $message): string => $message->content, $messages));
    }

    private function subject(
        ?ResolvedGlossary $glossary,
        string $translatorIdentifier = 'llm',
        ?string $syncedId = null,
        string $detectedLanguage = 'de',
        ?Throwable $syncFailure = null,
    ): TranslationService {
        $response = static fn(string $content): CompletionResponse => new CompletionResponse(
            content: $content,
            model: 'test-model',
            usage: new UsageStatistics(1, 1, 2),
            finishReason: 'stop',
            provider: 'test',
        );

        $llmManager = self::createStub(LlmServiceManagerInterface::class);
        $llmManager->method('chat')->willReturnCallback(
            function (array $messages) use ($response, $detectedLanguage): CompletionResponse {
                /** @var array<int, ChatMessage> $messages */
                if (str_contains($messages[0]->content, 'language detection')) {
                    return $response($detectedLanguage);
                }

                $this->sentMessages[] = $messages;

                return $response('translated');
            },
        );
        $llmManager->method('chatWithConfiguration')->willReturnCallback(
            function (array $messages) use ($response): CompletionResponse {
                /** @var array<int, ChatMessage> $messages */
                $this->sentMessages[] = $messages;

                return $response('translated');
            },
        );

        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('getIdentifier')->willReturn($translatorIdentifier);
        $translator->method('translate')->willReturnCallback(
            function (string $text, string $target, ?string $source, array $options): TranslatorResult {
                /** @var array<string, mixed> $options */
                $this->translatorOptions[] = $options;

                return new TranslatorResult('translated', $source ?? 'de', $target, 'test');
            },
        );
        $translator->method('translateBatch')->willReturnCallback(
            function (array $texts, string $target, ?string $source, array $options): array {
                /** @var array<string, mixed> $options */
                $this->translatorOptions[] = $options;

                return [];
            },
        );

        $registry = self::createStub(TranslatorRegistryInterface::class);
        $registry->method('get')->willReturn($translator);

        $onLookup = function (string $site, string $source, string $target): void {
            $this->lookups[] = ['site' => $site, 'source' => $source, 'target' => $target];
        };
        $resolver = new class ($glossary, $onLookup) implements GlossaryResolverInterface {
            /**
             * @param Closure(string, string, string): void $onLookup
             */
            public function __construct(private readonly ?ResolvedGlossary $glossary, private readonly Closure $onLookup) {}

            public function resolve(string $siteIdentifier, string $sourceLanguage, string $targetLanguage): ?ResolvedGlossary
            {
                ($this->onLookup)($siteIdentifier, $sourceLanguage, $targetLanguage);

                return $this->glossary;
            }

            public function storeDeepLGlossary(int $uid, string $deeplGlossaryId, string $entriesHash): void {}

            public function isDeepLGlossaryReferenced(string $deeplGlossaryId, int $exceptUid): bool
            {
                return false;
            }
        };

        $onSync = function (ResolvedGlossary $glossary): void {
            $this->synced[] = $glossary;
        };
        $sync = new class ($syncedId, $onSync, $syncFailure) implements DeepLGlossarySyncInterface {
            /**
             * @param Closure(ResolvedGlossary): void $onSync
             */
            public function __construct(
                private readonly ?string $id,
                private readonly Closure $onSync,
                private readonly ?Throwable $failure,
            ) {}

            public function glossaryIdFor(ResolvedGlossary $glossary): ?string
            {
                ($this->onSync)($glossary);
                if ($this->failure instanceof Throwable) {
                    throw $this->failure;
                }

                return $this->id;
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

        return new TranslationService(
            $llmManager,
            $registry,
            self::createStub(LlmConfigurationServiceInterface::class),
            new TranslationPromptBuilder(),
            null,
            $resolver,
            $sync,
        );
    }
}
