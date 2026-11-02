<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Translation;

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
use Netresearch\NrLlm\Service\Site\SiteLlmSettings;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Pricing\SpecializedCostCalculatorInterface;
use Netresearch\NrLlm\Specialized\Translation\DeepLGlossarySync;
use Netresearch\NrLlm\Specialized\Translation\DeepLTranslator;
use Netresearch\NrLlm\Specialized\Translation\TranslatorRegistryInterface;
use Netresearch\NrLlm\Tests\Fixture\AllowingBudgetService;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Service\Site\SiteLlmSettingsTest;
use Netresearch\NrVault\Http\VaultHttpClientInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A site that names its own DeepL key translates with it (ADR-244): the
 * request authenticates with the site's vault identifier and goes to the
 * endpoint that key belongs to, and one translator instance serves both keys.
 */
#[CoversClass(DeepLTranslator::class)]
#[AllowMockObjectsWithoutExpectations]
final class DeepLTranslatorSiteKeyTest extends AbstractUnitTestCase
{
    private const GLOBAL_KEY = 'global-deepl';

    private const SHOP_KEY = 'shop-deepl';

    /** @var list<string> identifier the request authenticated with, then METHOD URI */
    private array $requests = [];

    /** @var array<string, int> */
    private array $retrievals = [];

    #[Test]
    public function aSiteTranslatesWithItsOwnKeyAndOthersWithTheGlobalOne(): void
    {
        $translator = $this->translator();

        $translator->translate('Hello', 'de', 'en', ['site' => 'shop']);
        $translator->translate('Hello', 'de', 'en');
        $translator->translate('Hello', 'de', 'en', ['site' => 'blog']);
        $translator->translate('Hello', 'de', 'en', ['site' => 'shop']);

        self::assertSame([
            self::SHOP_KEY . ' POST https://api-free.deepl.com/v2/translate',
            self::GLOBAL_KEY . ' POST https://api.deepl.com/v2/translate',
            self::GLOBAL_KEY . ' POST https://api.deepl.com/v2/translate',
            self::SHOP_KEY . ' POST https://api-free.deepl.com/v2/translate',
        ], $this->requests);
        // Free or Pro is decided once per key, not once per instance.
        self::assertSame([self::SHOP_KEY => 1, self::GLOBAL_KEY => 1], $this->retrievals);
    }

    #[Test]
    public function theTranslationServicePassesTheSiteOfItsOptionsToDeepL(): void
    {
        $registry = self::createStub(TranslatorRegistryInterface::class);
        $registry->method('get')->willReturn($this->translator());
        $service = new TranslationService(
            self::createStub(LlmServiceManagerInterface::class),
            $registry,
            self::createStub(LlmConfigurationServiceInterface::class),
            new TranslationPromptBuilder(),
        );

        $service->translateWithTranslator('Hello', 'de', 'en', (new TranslationOptions())->withTranslator('deepl')->withSite('shop'));
        $service->translateBatchWithTranslator(['Hello'], 'de', 'en', (new TranslationOptions())->withTranslator('deepl')->withSite('shop'));

        self::assertSame([
            self::SHOP_KEY . ' POST https://api-free.deepl.com/v2/translate',
            self::SHOP_KEY . ' POST https://api-free.deepl.com/v2/translate',
        ], $this->requests);
    }

    #[Test]
    public function aBatchOfASiteUsesTheSitesKey(): void
    {
        $this->translator()->translateBatch(['Hello'], 'de', 'en', ['site' => 'shop']);

        self::assertSame([self::SHOP_KEY . ' POST https://api-free.deepl.com/v2/translate'], $this->requests);
    }

    #[Test]
    public function aGlossaryIsCreatedAndDeletedUnderTheSitesKey(): void
    {
        $shop = $this->translator()->forSite('shop');
        $shop->createGlossary('g', 'en', 'de', "a\tb");
        $shop->deleteGlossary('g-1');

        self::assertSame([
            self::SHOP_KEY . ' POST https://api-free.deepl.com/v2/glossaries',
            self::SHOP_KEY . ' DELETE https://api-free.deepl.com/v2/glossaries/g-1',
        ], $this->requests);
    }

    #[Test]
    public function theSiteGlossaryIsSyncedUnderTheKeyOfItsSite(): void
    {
        $resolver = self::createStub(GlossaryResolverInterface::class);
        $sync     = new DeepLGlossarySync($this->translator(), $resolver, $this->createLoggerMock());

        $glossaryId = $sync->glossaryIdFor(new ResolvedGlossary(
            7,
            'en',
            'de',
            GlossaryTerms::fromText('cart = Warenkorb'),
            siteIdentifier: 'shop',
        ));

        self::assertSame('g-1', $glossaryId);
        self::assertSame([self::SHOP_KEY . ' POST https://api-free.deepl.com/v2/glossaries'], $this->requests);
    }

    #[Test]
    public function aSiteKeyThatTheVaultDoesNotHoldFailsInsteadOfUsingTheGlobalKey(): void
    {
        $translator = $this->translator(['shop' => ['nrllm.deepl.apiKeyIdentifier' => 'missing-key']]);

        try {
            $translator->translate('Hello', 'de', 'en', ['site' => 'shop']);
            self::fail('A site key the vault does not hold must fail the call');
        } catch (ServiceUnavailableException) {
        }

        self::assertSame([], $this->requests);
    }

    /**
     * @param array<string, array<string, mixed>>|null $sites
     */
    private function translator(?array $sites = null): DeepLTranslator
    {
        $sites ??= ['shop' => ['nrllm.deepl.apiKeyIdentifier' => self::SHOP_KEY], 'blog' => []];

        return new DeepLTranslator(
            $this->vault([self::GLOBAL_KEY => 'not-a-real-pro-key', self::SHOP_KEY => 'not-a-real-free-key:fx']),
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createExtensionConfigurationMock(['translators' => ['deepl' => ['apiKeyIdentifier' => self::GLOBAL_KEY]]]),
            self::createStub(UsageTrackerServiceInterface::class),
            $this->createLoggerMock(),
            self::createStub(SpecializedCostCalculatorInterface::class),
            new AllowingBudgetService(),
            new MiddlewarePipeline([]),
            new InputGuardrailScreener([]),
            siteSettings: new SiteLlmSettings(SiteLlmSettingsTest::siteFinder($sites)),
        );
    }

    /**
     * A vault whose secure client records which identifier each request
     * authenticated with, and answers like DeepL.
     *
     * @param array<string, string> $secrets
     */
    private function vault(array $secrets): VaultServiceInterface
    {
        $vault = self::createStub(VaultServiceInterface::class);
        $vault->method('exists')->willReturnCallback(static fn (string $id): bool => isset($secrets[$id]));
        $vault->method('retrieve')->willReturnCallback(function (string $id) use ($secrets): ?string {
            $this->retrievals[$id] = ($this->retrievals[$id] ?? 0) + 1;

            return $secrets[$id] ?? null;
        });
        $vault->method('http')->willReturnCallback(fn (): VaultHttpClientInterface => $this->client(''));

        return $vault;
    }

    private function client(string $identifier): VaultHttpClientInterface
    {
        $client = self::createStub(VaultHttpClientInterface::class);
        $client->method('withAuthentication')->willReturnCallback(fn (string $id): VaultHttpClientInterface => $this->client($id));
        $client->method('withReason')->willReturn($client);
        $client->method('withTimeout')->willReturn($client);
        $client->method('sendRequest')->willReturnCallback(function (RequestInterface $request) use ($identifier): ResponseInterface {
            $this->requests[] = $identifier . ' ' . $request->getMethod() . ' ' . $request->getUri();

            return $this->createJsonResponseMock([
                'translations' => [['text' => 'Hallo', 'detected_source_language' => 'EN']],
                'glossary_id'  => 'g-1',
            ]);
        });

        return $client;
    }
}
