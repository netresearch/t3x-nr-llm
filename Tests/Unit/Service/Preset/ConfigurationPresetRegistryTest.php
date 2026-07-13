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
}
