<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Preset;

use LogicException;
use Netresearch\NrLlm\Domain\DTO\ModelSelectionCriteria;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Service\Preset\ConfigurationPreset;
use Netresearch\NrLlm\Service\Preset\ConfigurationPresetRegistry;
use Netresearch\NrLlm\Tests\Unit\Service\Preset\Fixtures\FixturePresetProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(ConfigurationPresetRegistry::class)]
final class ConfigurationPresetRegistryTest extends TestCase
{
    private static function preset(string $identifier): ConfigurationPreset
    {
        return new ConfigurationPreset(
            identifier: $identifier,
            name: 'Preset ' . $identifier,
            description: '',
            criteria: new ModelSelectionCriteria(capabilities: ['chat']),
        );
    }

    #[Test]
    public function collectsPresetsAcrossProvidersAndLooksUpByIdentifier(): void
    {
        $alpha = self::preset('ext_a.alpha');
        $beta = self::preset('ext_b.beta');
        $registry = new ConfigurationPresetRegistry(
            [new FixturePresetProvider([$alpha]), new FixturePresetProvider([$beta])],
            $this->createMock(LlmConfigurationRepository::class),
        );

        self::assertSame([$alpha, $beta], $registry->all());
        self::assertSame($alpha, $registry->findByIdentifier('ext_a.alpha'));
        self::assertNull($registry->findByIdentifier('ext_a.missing'));
    }

    #[Test]
    public function duplicateIdentifierAcrossProvidersThrows(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1789347004);

        new ConfigurationPresetRegistry(
            [
                new FixturePresetProvider([self::preset('ext.dup')]),
                new FixturePresetProvider([self::preset('ext.dup')]),
            ],
            $this->createMock(LlmConfigurationRepository::class),
        );
    }

    #[Test]
    public function pendingReturnsOnlyPresetsWithoutConfigurationRecord(): void
    {
        $imported = self::preset('ext.imported');
        $pending = self::preset('ext.pending');
        $repository = $this->createMock(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturnCallback(
            static fn(string $identifier): ?LlmConfiguration => $identifier === 'ext.imported' ? new LlmConfiguration() : null,
        );

        $registry = new ConfigurationPresetRegistry(
            [new FixturePresetProvider([$imported, $pending])],
            $repository,
        );

        self::assertSame([$pending], $registry->pending());
    }

    #[Test]
    public function driftedReturnsImportedPresetWhoseDeclarationChanged(): void
    {
        $preset = self::preset('ext.imported');
        $configuration = new LlmConfiguration();
        $configuration->setPresetChecksum('0000000000000000000000000000000000000000000000000000000000000000');
        $repository = $this->createMock(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturn($configuration);

        $registry = new ConfigurationPresetRegistry(
            [new FixturePresetProvider([$preset])],
            $repository,
        );

        self::assertSame(
            [['preset' => $preset, 'configuration' => $configuration]],
            $registry->drifted(),
        );
    }

    #[Test]
    public function driftedOmitsImportedPresetWithUnchangedChecksum(): void
    {
        $preset = self::preset('ext.imported');
        $configuration = new LlmConfiguration();
        $configuration->setPresetChecksum($preset->checksum());
        $repository = $this->createMock(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturn($configuration);

        $registry = new ConfigurationPresetRegistry(
            [new FixturePresetProvider([$preset])],
            $repository,
        );

        self::assertSame([], $registry->drifted());
    }

    #[Test]
    public function driftedOmitsPendingPresetsAndHandCreatedRecords(): void
    {
        // A pending preset has no record; a hand-created record sharing a
        // declared identifier carries no stored checksum. Neither is drift.
        $pending = self::preset('ext.pending');
        $handCreated = self::preset('ext.hand_created');
        $record = new LlmConfiguration();
        $repository = $this->createMock(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturnCallback(
            static fn(string $identifier): ?LlmConfiguration => $identifier === 'ext.hand_created' ? $record : null,
        );

        $registry = new ConfigurationPresetRegistry(
            [new FixturePresetProvider([$pending, $handCreated])],
            $repository,
        );

        self::assertSame([], $registry->drifted());
    }
}
