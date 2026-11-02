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
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Exception\AccessDeniedException;
use Netresearch\NrLlm\Exception\ConfigurationInactiveException;
use Netresearch\NrLlm\Exception\ConfigurationNotFoundException;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Site\SiteLlmSettings;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Service\Site\SiteLlmSettingsTest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * A site that names a configuration replaces the installation default for its
 * calls, and only that (ADR-244).
 */
#[CoversClass(ConfigurationResolver::class)]
final class ConfigurationResolverSiteTest extends AbstractUnitTestCase
{
    #[Test]
    public function aSiteThatNamesNoConfigurationResolvesToNothing(): void
    {
        $repository = self::createMock(LlmConfigurationRepository::class);
        $repository->expects(self::never())->method('findOneByIdentifier');

        $subject = $this->resolver($repository, ['blog' => []]);

        self::assertNull($subject->resolveForSite('blog'));
        self::assertNull($subject->resolveForSite('unknown'));
        self::assertNull($subject->resolveForSite(null));
    }

    #[Test]
    public function aSiteResolvesTheConfigurationItNames(): void
    {
        $shopWriter = $this->configuration();
        $subject    = $this->resolver($this->repositoryWith(['shop-writer' => $shopWriter]), ['shop' => ['nrllm.configuration' => 'shop-writer']]);

        self::assertSame($shopWriter, $subject->resolveForSite('shop'));
    }

    #[Test]
    public function aNamedConfigurationThatDoesNotExistFailsTheCall(): void
    {
        $subject = $this->resolver($this->repositoryWith([]), ['shop' => ['nrllm.configuration' => 'deleted']]);

        $this->expectException(ConfigurationNotFoundException::class);
        $subject->resolveForSite('shop');
    }

    #[Test]
    public function aNamedConfigurationThatIsInactiveFailsTheCall(): void
    {
        $subject = $this->resolver(
            $this->repositoryWith(['off' => $this->configuration(active: false)]),
            ['shop' => ['nrllm.configuration' => 'off']],
        );

        $this->expectException(ConfigurationInactiveException::class);
        $subject->resolveForSite('shop');
    }

    #[Test]
    public function aRestrictedConfigurationFailsWithoutAnActorAndResolvesForAnEntitledOne(): void
    {
        $restricted = $this->configuration(restricted: true);
        $subject    = $this->resolver($this->repositoryWith(['team' => $restricted]), ['shop' => ['nrllm.configuration' => 'team']]);

        self::assertSame($restricted, $subject->resolveForSite('shop', AiActorContext::backendUser(1, true)));

        $this->expectException(AccessDeniedException::class);
        $subject->resolveForSite('shop');
    }

    #[Test]
    public function theSiteConfigurationReplacesTheInstallationDefault(): void
    {
        $shopWriter = $this->configuration();
        $repository = $this->repositoryWith(['shop-writer' => $shopWriter], $this->configuration());

        $subject = $this->resolver($repository, ['shop' => ['nrllm.configuration' => 'shop-writer']]);

        self::assertSame($shopWriter, $subject->resolveDefaultConfiguration(null, 'shop'));
    }

    #[Test]
    public function aSiteThatNamesNoneKeepsTheInstallationDefault(): void
    {
        $default = $this->configuration();
        $subject = $this->resolver($this->repositoryWith([], $default), ['blog' => []]);

        self::assertSame($default, $subject->resolveDefaultConfiguration(null, 'blog'));
        self::assertSame($default, $subject->resolveDefaultConfiguration(null));
    }

    #[Test]
    public function aPinnedProviderStillBypassesBothDefaults(): void
    {
        $subject = $this->resolver(
            $this->repositoryWith(['shop-writer' => $this->configuration()], $this->configuration()),
            ['shop' => ['nrllm.configuration' => 'shop-writer']],
        );

        self::assertNull($subject->resolveDefaultConfiguration('openai', 'shop'));
    }

    /**
     * @param array<string, array<string, mixed>> $sites
     */
    private function resolver(LlmConfigurationRepository $repository, array $sites): ConfigurationResolver
    {
        return new ConfigurationResolver($repository, new SiteLlmSettings(SiteLlmSettingsTest::siteFinder($sites)));
    }

    /**
     * @param array<string, LlmConfiguration> $byIdentifier
     */
    private function repositoryWith(array $byIdentifier, ?LlmConfiguration $default = null): LlmConfigurationRepository
    {
        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturnCallback(
            static fn (string $identifier): ?LlmConfiguration => $byIdentifier[$identifier] ?? null,
        );
        $repository->method('findDefault')->willReturn($default);

        return $repository;
    }

    private function configuration(bool $active = true, bool $restricted = false): LlmConfiguration
    {
        $configuration = self::createStub(LlmConfiguration::class);
        $configuration->method('isActive')->willReturn($active);
        $configuration->method('hasAccessRestrictions')->willReturn($restricted);
        $configuration->method('getLlmModel')->willReturn(self::createStub(Model::class));

        return $configuration;
    }
}
