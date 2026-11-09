<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Model;

use DateTimeImmutable;
use GuzzleHttp\Psr7\HttpFactory;
use Netresearch\NrLlm\Domain\Enum\CapabilitySource;
use Netresearch\NrLlm\Domain\Enum\ReasoningEffort;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Service\Model\CapabilityVerifier;
use Netresearch\NrLlm\Service\Model\ThirdPartyReasoningCatalog;
use Netresearch\NrLlm\Service\SetupWizard\Discovery\OpenRouterReasoning;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DiscoveredModel;
use Netresearch\NrLlm\Service\SetupWizard\ModelDiscoveryInterface;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * The capability verification also records the discovered reasoning profile
 * (ADR-274, R3–R5): from the provider's own listing where it states one, from
 * the opt-in third-party catalogue for a direct OpenAI model otherwise, and
 * nothing at all where neither states anything.
 */
#[CoversClass(CapabilityVerifier::class)]
#[UsesClass(ThirdPartyReasoningCatalog::class)]
#[UsesClass(OpenRouterReasoning::class)]
final class CapabilityVerifierReasoningTest extends AbstractUnitTestCase
{
    private const NOW = '2026-11-09 10:00:00';

    private int $catalogueRequests = 0;

    #[Test]
    public function aProviderListingThatStatesReasoningIsRecordedAsDiscovery(): void
    {
        $model = $this->model('openrouter', 'openai/gpt-6-astra');

        $this->verifier([$this->discovered('openai/gpt-6-astra', true, [ReasoningEffort::Low, ReasoningEffort::Max])])
            ->verify($model, new DateTimeImmutable(self::NOW));

        self::assertTrue($model->getReasoningDiscoveredFlag());
        self::assertSame('low,max', $model->getReasoningEffortsDiscovered());
        self::assertSame(CapabilitySource::Discovery, $model->getReasoningSourceEnum());
        self::assertSame((new DateTimeImmutable(self::NOW))->getTimestamp(), $model->getReasoningConfirmedDate()?->getTimestamp());
    }

    /**
     * R5: a run without data for the model writes nothing — an earlier
     * answer survives, and nothing turns into "no".
     */
    #[Test]
    public function aRunThatStatesNothingLeavesTheEarlierAnswerAndTheOverrides(): void
    {
        $model = $this->model('openrouter', 'openrouter/auto');
        $model->setReasoningOverride('no');
        $model->recordReasoningDiscovery(true, [ReasoningEffort::High], CapabilitySource::Discovery, new DateTimeImmutable('2026-10-01'));

        $this->verifier([$this->discovered('openrouter/auto', null)])->verify($model, new DateTimeImmutable(self::NOW));

        self::assertSame('yes', $model->getReasoningDiscovered());
        self::assertSame('high', $model->getReasoningEffortsDiscovered());
        self::assertSame((new DateTimeImmutable('2026-10-01'))->getTimestamp(), $model->getReasoningConfirmedDate()?->getTimestamp());
        self::assertSame('no', $model->getReasoningOverride());
    }

    #[Test]
    public function aCatalogueSubstitutedForTheListingRecordsNoReasoning(): void
    {
        $model = $this->model('openrouter', 'openai/gpt-6-astra');

        $this->verifier([$this->discovered('openai/gpt-6-astra', true)], fromFallback: true)
            ->verify($model, new DateTimeImmutable(self::NOW));

        self::assertSame('', $model->getReasoningDiscovered());
    }

    #[Test]
    public function aDirectOpenAiModelTakesOnlyTheFlagFromTheThirdPartyCatalogue(): void
    {
        $model = $this->model('openai', 'gpt-6-astra');

        $this->verifier([$this->discovered('gpt-6-astra', null)], catalogue: $this->catalogue(enabled: true))
            ->verify($model, new DateTimeImmutable(self::NOW));

        self::assertTrue($model->getReasoningDiscoveredFlag());
        self::assertSame('', $model->getReasoningEffortsDiscovered());
        self::assertSame(CapabilitySource::ThirdPartyCatalog, $model->getReasoningSourceEnum());
        self::assertSame(1, $this->catalogueRequests);
    }

    #[Test]
    public function aSwitchedOffCatalogueIsNeverAskedAndRecordsNothing(): void
    {
        $model = $this->model('openai', 'gpt-6-astra');

        $this->verifier([$this->discovered('gpt-6-astra', null)], catalogue: $this->catalogue(enabled: false))
            ->verify($model, new DateTimeImmutable(self::NOW));

        self::assertSame('', $model->getReasoningDiscovered());
        self::assertSame(0, $this->catalogueRequests);
    }

    #[Test]
    public function aFailedCatalogueLookupStillConfirmsTheCapabilitiesAndRecordsNoReasoning(): void
    {
        $model = $this->model('openai', 'gpt-6-astra');

        $verified = $this->verifier([$this->discovered('gpt-6-astra', null)], catalogue: $this->catalogue(enabled: true, fails: true))
            ->verify($model, new DateTimeImmutable(self::NOW));

        self::assertTrue($verified);
        self::assertNotNull($model->getCapabilitiesConfirmedDate());
        self::assertSame('', $model->getReasoningDiscovered());
        self::assertNull($model->getReasoningConfirmedDate());
    }

    /**
     * Azure, custom gateways and the other OpenAI-dialect types name
     * deployments; `openai/<deployment>` would match the wrong model.
     */
    #[Test]
    public function onlyADirectOpenAiProviderAsksTheCatalogue(): void
    {
        $model = $this->model('azure_openai', 'gpt-6-astra');

        $this->verifier([$this->discovered('gpt-6-astra', null)], catalogue: $this->catalogue(enabled: true))
            ->verify($model, new DateTimeImmutable(self::NOW));

        self::assertSame(0, $this->catalogueRequests);
        self::assertSame('', $model->getReasoningDiscovered());
    }

    #[Test]
    public function theProvidersOwnAnswerIsPreferredOverTheCatalogue(): void
    {
        $model = $this->model('openai', 'gpt-6-astra');

        $this->verifier([$this->discovered('gpt-6-astra', false)], catalogue: $this->catalogue(enabled: true))
            ->verify($model, new DateTimeImmutable(self::NOW));

        self::assertFalse($model->getReasoningDiscoveredFlag());
        self::assertSame(CapabilitySource::Discovery, $model->getReasoningSourceEnum());
        self::assertSame(0, $this->catalogueRequests);
    }

    /**
     * @param list<DiscoveredModel> $discovered
     */
    private function verifier(array $discovered, bool $fromFallback = false, ?ThirdPartyReasoningCatalog $catalogue = null): CapabilityVerifier
    {
        $discovery = self::createStub(ModelDiscoveryInterface::class);
        $discovery->method('discover')->willReturn($discovered);
        $discovery->method('wasLastDiscoveryFromFallback')->willReturn($fromFallback);

        return new CapabilityVerifier($discovery, $catalogue);
    }

    private function catalogue(bool $enabled, bool $fails = false): ThirdPartyReasoningCatalog
    {
        $settings = self::createStub(ExtensionConfiguration::class);
        $settings->method('get')->willReturn(['thirdPartyReasoningCatalog' => $enabled ? '1' : '0']);

        $body = file_get_contents(__DIR__ . '/../SetupWizard/Fixtures/openrouter-models-2026-11-09.json');
        self::assertIsString($body);

        $client = self::createStub(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(function () use ($fails, $body): ResponseInterface {
            ++$this->catalogueRequests;
            if ($fails) {
                throw new RuntimeException('connection refused', 3434310604);
            }

            return $this->createHttpResponseMock(200, $body);
        });

        $catalogue = new ThirdPartyReasoningCatalog(
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
            new HttpFactory(),
            $settings,
            self::createStub(LoggerInterface::class),
        );
        $catalogue->setHttpClient($client);

        return $catalogue;
    }

    private function model(string $adapterType, string $modelId): Model
    {
        $provider = new Provider();
        $provider->setAdapterType($adapterType);
        $provider->setName('Provider');

        $model = new Model();
        $model->setModelId($modelId);
        $model->setCapabilities('chat');
        $model->setProvider($provider);

        return $model;
    }

    /**
     * @param list<ReasoningEffort> $efforts
     */
    private function discovered(string $modelId, ?bool $reasoning, array $efforts = []): DiscoveredModel
    {
        return new DiscoveredModel(
            modelId: $modelId,
            name: $modelId,
            capabilities: ['chat'],
            capabilitiesFromApi: true,
            reasoning: $reasoning,
            reasoningEfforts: $efforts,
        );
    }
}
