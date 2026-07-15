<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ConfigurationResolver::class)]
class ConfigurationResolverTest extends AbstractUnitTestCase
{
    #[Test]
    public function resolveDefaultConfigurationReturnsNullWhenProviderPinned(): void
    {
        $repository = self::createMock(LlmConfigurationRepository::class);
        $repository->expects(self::never())->method('findDefault');

        $subject = new ConfigurationResolver($repository);

        self::assertNull($subject->resolveDefaultConfiguration('openai'));
    }

    #[Test]
    public function resolveDefaultConfigurationReturnsNullWhenNoRepositoryWired(): void
    {
        $subject = new ConfigurationResolver();

        self::assertNull($subject->resolveDefaultConfiguration(null));
    }

    #[Test]
    public function resolveDefaultConfigurationReturnsNullWhenNoDefaultExists(): void
    {
        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findDefault')->willReturn(null);

        $subject = new ConfigurationResolver($repository);

        self::assertNull($subject->resolveDefaultConfiguration(null));
    }

    #[Test]
    public function resolveDefaultConfigurationReturnsNullWhenDefaultHasNoModel(): void
    {
        $configuration = self::createStub(LlmConfiguration::class);
        $configuration->method('getLlmModel')->willReturn(null);

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findDefault')->willReturn($configuration);

        $subject = new ConfigurationResolver($repository);

        self::assertNull($subject->resolveDefaultConfiguration(null));
    }

    #[Test]
    public function resolveDefaultConfigurationReturnsNullWhenDefaultIsAccessRestricted(): void
    {
        $configuration = self::createStub(LlmConfiguration::class);
        $configuration->method('getLlmModel')->willReturn(self::createStub(Model::class));
        $configuration->method('hasAccessRestrictions')->willReturn(true);

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findDefault')->willReturn($configuration);

        $subject = new ConfigurationResolver($repository);

        self::assertNull($subject->resolveDefaultConfiguration(null));
    }

    #[Test]
    public function resolveDefaultConfigurationReturnsUnrestrictedDefaultWithModel(): void
    {
        $configuration = self::createStub(LlmConfiguration::class);
        $configuration->method('getLlmModel')->willReturn(self::createStub(Model::class));
        $configuration->method('hasAccessRestrictions')->willReturn(false);

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findDefault')->willReturn($configuration);

        $subject = new ConfigurationResolver($repository);

        self::assertSame($configuration, $subject->resolveDefaultConfiguration(null));
    }

    #[Test]
    public function resolveEffectiveConfigurationReturnsExplicitConfigurationWithoutConsultingRepository(): void
    {
        $explicit = self::createStub(LlmConfiguration::class);

        $repository = self::createMock(LlmConfigurationRepository::class);
        $repository->expects(self::never())->method('findDefault');

        $subject = new ConfigurationResolver($repository);

        self::assertSame($explicit, $subject->resolveEffectiveConfiguration($explicit));
    }

    #[Test]
    public function resolveEffectiveConfigurationFallsBackToDefaultWhenNoneGiven(): void
    {
        $default = self::createStub(LlmConfiguration::class);
        $default->method('getLlmModel')->willReturn(self::createStub(Model::class));
        $default->method('hasAccessRestrictions')->willReturn(false);

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findDefault')->willReturn($default);

        $subject = new ConfigurationResolver($repository);

        self::assertSame($default, $subject->resolveEffectiveConfiguration());
    }
}
