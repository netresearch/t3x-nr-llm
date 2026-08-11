<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Model;

use DateTimeImmutable;
use Netresearch\NrLlm\Domain\Enum\CapabilitySource;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Service\Model\CapabilityVerifier;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DiscoveredModel;
use Netresearch\NrLlm\Service\SetupWizard\ModelDiscoveryInterface;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(CapabilityVerifier::class)]
final class CapabilityVerifierTest extends AbstractUnitTestCase
{
    private const NOW = '2026-08-11 09:30:00';

    /**
     * @param list<DiscoveredModel> $discovered
     */
    private function verifier(array $discovered, bool $fromFallback = false): CapabilityVerifier
    {
        $discovery = self::createStub(ModelDiscoveryInterface::class);
        $discovery->method('discover')->willReturn($discovered);
        $discovery->method('wasLastDiscoveryFromFallback')->willReturn($fromFallback);

        return new CapabilityVerifier($discovery);
    }

    private function modelOnProvider(string $modelId, string $capabilities): Model
    {
        $provider = new Provider();
        $provider->setAdapterType('openai');
        $provider->setName('Contract Provider');

        $model = new Model();
        $model->setModelId($modelId);
        $model->setCapabilities($capabilities);
        $model->setProvider($provider);

        return $model;
    }

    #[Test]
    public function aLiveProviderAnswerIsRecordedAsDiscovery(): void
    {
        $model = $this->modelOnProvider('gpt-4o', 'chat,vision');

        $verified = $this->verifier([
            new DiscoveredModel(modelId: 'gpt-4o', name: 'GPT-4o', capabilities: ['chat', 'vision']),
        ])->verify($model, new DateTimeImmutable(self::NOW));

        self::assertTrue($verified);
        self::assertSame('chat,vision', $model->_getProperty('capabilitiesDiscovered'));
        self::assertSame(CapabilitySource::Discovery->value, $model->_getProperty('capabilitiesSource'));
        self::assertSame(
            (new DateTimeImmutable(self::NOW))->getTimestamp(),
            $model->getCapabilitiesConfirmedDate()?->getTimestamp(),
        );
    }

    /**
     * When the provider does not answer, `ModelDiscovery` substitutes the
     * static catalog. Recording that as a provider confirmation would invent
     * the confidence provenance exists to remove.
     */
    #[Test]
    public function aSubstitutedStaticCatalogIsRecordedAsCatalogNotDiscovery(): void
    {
        $model = $this->modelOnProvider('gpt-4o', 'chat');

        $verified = $this->verifier(
            [new DiscoveredModel(modelId: 'gpt-4o', name: 'GPT-4o', capabilities: ['chat'])],
            fromFallback: true,
        )->verify($model, new DateTimeImmutable(self::NOW));

        self::assertTrue($verified);
        self::assertSame(CapabilitySource::Catalog->value, $model->_getProperty('capabilitiesSource'));
        self::assertFalse($model->getCapabilityProvenance()[0]->isVerified());
    }

    #[Test]
    public function aModelTheProviderDoesNotListIsNotRecordedAtAll(): void
    {
        $model = $this->modelOnProvider('gpt-4o', 'chat');

        $verified = $this->verifier([
            new DiscoveredModel(modelId: 'gpt-4o-mini', name: 'GPT-4o mini', capabilities: ['chat']),
        ])->verify($model, new DateTimeImmutable(self::NOW));

        self::assertFalse($verified);
        self::assertSame('', $model->_getProperty('capabilitiesSource'));
        self::assertSame(0, $model->_getProperty('capabilitiesConfirmedAt'));
    }

    #[Test]
    public function aModelWithoutAProviderHasNobodyToAsk(): void
    {
        $model = new Model();
        $model->setModelId('gpt-4o');
        $model->setCapabilities('chat');

        $verified = $this->verifier([
            new DiscoveredModel(modelId: 'gpt-4o', name: 'GPT-4o', capabilities: ['chat']),
        ])->verify($model, new DateTimeImmutable(self::NOW));

        self::assertFalse($verified);
        self::assertSame(0, $model->_getProperty('capabilitiesConfirmedAt'));
    }

    #[Test]
    public function anEmptyModelIdIsNeverMatchedAgainstTheCatalog(): void
    {
        $model = $this->modelOnProvider('', 'chat');

        $verified = $this->verifier([
            new DiscoveredModel(modelId: '', name: 'Nameless', capabilities: ['chat']),
        ])->verify($model, new DateTimeImmutable(self::NOW));

        self::assertFalse($verified);
    }

    /**
     * Verification records what the provider said; it never edits what the
     * operator declared.
     */
    #[Test]
    public function verificationLeavesTheDeclaredCapabilitySetAlone(): void
    {
        $model = $this->modelOnProvider('gpt-4o', 'chat,tools');

        $this->verifier([
            new DiscoveredModel(modelId: 'gpt-4o', name: 'GPT-4o', capabilities: ['chat', 'vision']),
        ])->verify($model, new DateTimeImmutable(self::NOW));

        self::assertSame('chat,tools', $model->getCapabilities());
        self::assertSame('chat,vision', $model->_getProperty('capabilitiesDiscovered'));
    }
}
