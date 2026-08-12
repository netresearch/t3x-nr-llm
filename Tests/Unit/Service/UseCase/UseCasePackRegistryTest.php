<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\UseCase;

use LogicException;
use Netresearch\NrLlm\Domain\DTO\ModelSelectionCriteria;
use Netresearch\NrLlm\Service\Governance\GovernanceProfile;
use Netresearch\NrLlm\Service\Preset\ConfigurationPreset;
use Netresearch\NrLlm\Service\UseCase\UseCase;
use Netresearch\NrLlm\Service\UseCase\UseCasePack;
use Netresearch\NrLlm\Service\UseCase\UseCasePackPresetProvider;
use Netresearch\NrLlm\Service\UseCase\UseCasePackRegistry;
use Netresearch\NrLlm\Tests\Unit\Service\UseCase\Fixtures\FixturePackProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UseCasePackRegistry::class)]
#[CoversClass(UseCasePackPresetProvider::class)]
final class UseCasePackRegistryTest extends TestCase
{
    private function pack(string $identifier, UseCase $useCase = UseCase::EDITORIAL): UseCasePack
    {
        return new UseCasePack(
            identifier: $identifier,
            useCase: $useCase,
            name: 'Pack ' . $identifier,
            description: '',
            configurationPreset: new ConfigurationPreset(
                identifier: 'ext.' . str_replace('-', '_', $identifier),
                name: 'Preset ' . $identifier,
                description: '',
                criteria: new ModelSelectionCriteria(capabilities: ['chat']),
            ),
            recommendedGovernanceProfile: GovernanceProfile::LOCAL_ONLY,
        );
    }

    #[Test]
    public function collectsPacksAcrossProvidersAndLooksUpByIdentifier(): void
    {
        $alpha = $this->pack('alpha');
        $beta = $this->pack('beta');
        $registry = new UseCasePackRegistry([
            new FixturePackProvider([$alpha]),
            new FixturePackProvider([$beta]),
        ]);

        self::assertSame([$alpha, $beta], $registry->all());
        self::assertSame($alpha, $registry->findByIdentifier('alpha'));
        self::assertNull($registry->findByIdentifier('missing'));
    }

    #[Test]
    public function duplicateIdentifierAcrossProvidersThrows(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1791460031);

        $registry = new UseCasePackRegistry([
            new FixturePackProvider([$this->pack('dup')]),
            new FixturePackProvider([$this->pack('dup')]),
        ]);
        self::fail('Expected the duplicate to be refused, collected ' . count($registry->all()) . ' pack(s).');
    }

    #[Test]
    public function forUseCaseNarrowsToOneUseCaseAndAnswersEmptyForTheRest(): void
    {
        $editorial = $this->pack('editorial-one', UseCase::EDITORIAL);
        $translation = $this->pack('translation-one', UseCase::TRANSLATION);
        $registry = new UseCasePackRegistry([new FixturePackProvider([$editorial, $translation])]);

        self::assertSame([$editorial], $registry->forUseCase(UseCase::EDITORIAL));
        self::assertSame([$translation], $registry->forUseCase(UseCase::TRANSLATION));
        // An unanswered use case is a state the entry step reports, not an error.
        self::assertSame([], $registry->forUseCase(UseCase::METADATA));
    }

    /**
     * The bridge's own output, and nothing beyond it. That its presets actually
     * arrive in ConfigurationPresetRegistry is a DI question a unit test cannot
     * answer — {@see \Netresearch\NrLlm\Tests\Functional\Controller\Backend\UseCasePackRenderTest}
     * asks the container.
     */
    #[Test]
    public function thePresetBridgeExposesEveryPacksConfigurationPreset(): void
    {
        $alpha = $this->pack('alpha');
        $beta = $this->pack('beta');
        $registry = new UseCasePackRegistry([new FixturePackProvider([$alpha, $beta])]);

        self::assertSame(
            [$alpha->configurationPreset, $beta->configurationPreset],
            (new UseCasePackPresetProvider($registry))->getPresets(),
        );
    }
}
