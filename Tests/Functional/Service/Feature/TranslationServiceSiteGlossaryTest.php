<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Feature;

use Closure;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Service\Feature\TranslationPromptBuilder;
use Netresearch\NrLlm\Service\Feature\TranslationService;
use Netresearch\NrLlm\Service\Glossary\GlossaryResolver;
use Netresearch\NrLlm\Service\Glossary\ResolvedGlossary;
use Netresearch\NrLlm\Service\LlmConfigurationServiceInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\TranslationOptions;
use Netresearch\NrLlm\Specialized\Translation\DeepLGlossarySync;
use Netresearch\NrLlm\Specialized\Translation\DeepLGlossarySyncInterface;
use Netresearch\NrLlm\Specialized\Translation\TranslatorInterface;
use Netresearch\NrLlm\Specialized\Translation\TranslatorRegistryInterface;
use Netresearch\NrLlm\Specialized\Translation\TranslatorResult;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * TranslationService picks the site glossary out of tx_nrllm_glossary on both
 * translation paths (ADR-208). The glossary lookup is the real one against the
 * database; the model call and the translator are doubles that record what
 * they were handed.
 */
#[CoversClass(TranslationService::class)]
#[CoversClass(GlossaryResolver::class)]
final class TranslationServiceSiteGlossaryTest extends AbstractFunctionalTestCase
{
    /** @var list<string> */
    private array $prompts = [];

    /** @var list<array<string, mixed>> */
    private array $translatorOptions = [];

    /** @var list<ResolvedGlossary> */
    private array $synced = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('Glossaries.csv');
    }

    #[Test]
    public function theLlmPathTranslatesWithTheSitesTerms(): void
    {
        $this->subject('llm')->translate('Der Warenkorb ist leer.', 'en', 'de', (new TranslationOptions())->withSite('main'));

        self::assertCount(1, $this->prompts);
        self::assertStringContainsString('- Warenkorb → shopping cart', $this->prompts[0]);
        self::assertStringContainsString('- Kundenkonto → customer account', $this->prompts[0]);
        // Record 2 claims the same pair on the same site but comes later in
        // the manual order; its terms do not leak in.
        self::assertStringNotContainsString('basket', $this->prompts[0]);
    }

    #[Test]
    public function theLlmTranslatorPathReceivesTheSitesTerms(): void
    {
        $this->subject('llm')->translateWithTranslator('Der Warenkorb ist leer.', 'en', 'de', (new TranslationOptions())->withSite('other'));

        self::assertSame(['Warenkorb' => 'cart'], $this->translatorOptions[0]['glossary'] ?? null);
    }

    #[Test]
    public function theDeepLPathReceivesAGlossaryIdForTheSitesRecord(): void
    {
        $this->subject('deepl')->translateWithTranslator('Der Warenkorb ist leer.', 'en-GB', 'de', (new TranslationOptions())->withSite('main'));

        self::assertCount(1, $this->synced);
        self::assertSame(1, $this->synced[0]->uid);
        self::assertSame(
            ['Warenkorb' => 'shopping cart', 'Kundenkonto' => 'customer account'],
            $this->synced[0]->terms->toArray(),
        );
        self::assertSame('gls_test_1', $this->translatorOptions[0]['glossary_id'] ?? null);
    }

    #[Test]
    public function aHiddenGlossaryReachesNeitherPath(): void
    {
        $options = (new TranslationOptions())->withSite('main');

        $this->subject('llm')->translate('Der Warenkorb ist leer.', 'fr', 'de', $options);
        $this->subject('deepl')->translateWithTranslator('Der Warenkorb ist leer.', 'fr', 'de', $options);

        self::assertStringNotContainsString('panier', $this->prompts[0]);
        self::assertSame([], $this->synced);
        self::assertArrayNotHasKey('glossary_id', $this->translatorOptions[0]);
    }

    #[Test]
    public function theContainerHandsTheServiceTheGlossaryResolverAndTheDeepLSync(): void
    {
        // Both collaborators are optional constructor arguments, so a missing
        // alias would not fail the build: the service would silently translate
        // without any site glossary.
        $service = $this->getService(TranslationService::class);
        $reflection = new ReflectionClass($service);

        self::assertInstanceOf(GlossaryResolver::class, $reflection->getProperty('glossaryResolver')->getValue($service));
        self::assertInstanceOf(DeepLGlossarySync::class, $reflection->getProperty('deepLGlossarySync')->getValue($service));
    }

    private function subject(string $translatorIdentifier): TranslationService
    {
        $llmManager = self::createStub(LlmServiceManagerInterface::class);
        $llmManager->method('chat')->willReturnCallback(function (array $messages): CompletionResponse {
            /** @var array<int, ChatMessage> $messages */
            $this->prompts[] = implode("\n", array_map(static fn(ChatMessage $message): string => $message->content, $messages));

            return new CompletionResponse('translated', 'test-model', new UsageStatistics(1, 1, 2), 'stop', 'test');
        });

        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('getIdentifier')->willReturn($translatorIdentifier);
        $translator->method('translate')->willReturnCallback(
            function (string $text, string $target, ?string $source, array $options): TranslatorResult {
                /** @var array<string, mixed> $options */
                $this->translatorOptions[] = $options;

                return new TranslatorResult('translated', $source ?? 'de', $target, 'test');
            },
        );

        $registry = self::createStub(TranslatorRegistryInterface::class);
        $registry->method('get')->willReturn($translator);

        $onSync = function (ResolvedGlossary $glossary): void {
            $this->synced[] = $glossary;
        };
        $sync = new class ($onSync, 'gls_test_1') implements DeepLGlossarySyncInterface {
            /**
             * @param Closure(ResolvedGlossary): void $onSync
             */
            public function __construct(private readonly Closure $onSync, private readonly ?string $id) {}

            public function glossaryIdFor(ResolvedGlossary $glossary): ?string
            {
                ($this->onSync)($glossary);

                return $this->id;
            }
        };

        return new TranslationService(
            $llmManager,
            $registry,
            self::createStub(LlmConfigurationServiceInterface::class),
            new TranslationPromptBuilder(),
            null,
            new GlossaryResolver($this->getConnectionPool()),
            $sync,
        );
    }
}
