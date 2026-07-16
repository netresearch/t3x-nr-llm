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
use Netresearch\NrLlm\Exception\AccessDeniedException;
use Netresearch\NrLlm\Exception\ConfigurationInactiveException;
use Netresearch\NrLlm\Exception\ConfigurationNotFoundException;
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

    #[Test]
    public function getActiveByIdentifierReturnsActiveUnrestrictedConfiguration(): void
    {
        $configuration = self::createStub(LlmConfiguration::class);
        $configuration->method('isActive')->willReturn(true);
        $configuration->method('hasAccessRestrictions')->willReturn(false);

        $repository = self::createMock(LlmConfigurationRepository::class);
        $repository->expects(self::once())
            ->method('findOneByIdentifier')
            ->with('blog-summarizer')
            ->willReturn($configuration);

        $subject = new ConfigurationResolver($repository);

        self::assertSame($configuration, $subject->getActiveByIdentifier('blog-summarizer'));
    }

    #[Test]
    public function getActiveByIdentifierDoesNotRequireAnAssignedModel(): void
    {
        // Criteria-mode configurations carry no direct model relation; the
        // model is resolved at call time (ADR-066), so the resolver must not
        // refuse them the way the default path refuses a model-less default.
        $configuration = self::createStub(LlmConfiguration::class);
        $configuration->method('getLlmModel')->willReturn(null);
        $configuration->method('isActive')->willReturn(true);
        $configuration->method('hasAccessRestrictions')->willReturn(false);

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturn($configuration);

        $subject = new ConfigurationResolver($repository);

        self::assertSame($configuration, $subject->getActiveByIdentifier('criteria-mode'));
    }

    #[Test]
    public function getActiveByIdentifierThrowsWhenConfigurationIsMissing(): void
    {
        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturn(null);

        $subject = new ConfigurationResolver($repository);

        $this->expectException(ConfigurationNotFoundException::class);
        $this->expectExceptionCode(1784211001);

        $subject->getActiveByIdentifier('missing');
    }

    #[Test]
    public function getActiveByIdentifierThrowsWhenNoRepositoryWired(): void
    {
        $subject = new ConfigurationResolver();

        $this->expectException(ConfigurationNotFoundException::class);
        $this->expectExceptionCode(1784211001);

        $subject->getActiveByIdentifier('anything');
    }

    #[Test]
    public function getActiveByIdentifierThrowsWhenConfigurationIsInactive(): void
    {
        $configuration = self::createStub(LlmConfiguration::class);
        $configuration->method('isActive')->willReturn(false);

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturn($configuration);

        $subject = new ConfigurationResolver($repository);

        $this->expectException(ConfigurationInactiveException::class);
        $this->expectExceptionCode(1784211002);

        $subject->getActiveByIdentifier('deactivated');
    }

    #[Test]
    public function getActiveByIdentifierThrowsWhenConfigurationIsAccessRestricted(): void
    {
        $configuration = self::createStub(LlmConfiguration::class);
        $configuration->method('isActive')->willReturn(true);
        $configuration->method('hasAccessRestrictions')->willReturn(true);

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturn($configuration);

        $subject = new ConfigurationResolver($repository);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionCode(1784211003);

        $subject->getActiveByIdentifier('restricted');
    }
}
