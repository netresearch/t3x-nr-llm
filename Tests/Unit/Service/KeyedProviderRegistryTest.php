<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Exception;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Service\KeyedProviderRegistry;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(KeyedProviderRegistry::class)]
class KeyedProviderRegistryTest extends AbstractUnitTestCase
{
    /**
     * @param array<string, mixed> $extensionConfig
     */
    private function createRegistry(array $extensionConfig = ['providers' => []], ?LoggerInterface $logger = null): KeyedProviderRegistry
    {
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($extensionConfig);

        return new KeyedProviderRegistry($extensionConfiguration, $logger ?? self::createStub(LoggerInterface::class));
    }

    private function providerStub(string $identifier, string $name = 'Test', bool $available = true, bool $supports = true): ProviderInterface&Stub
    {
        $provider = self::createStub(ProviderInterface::class);
        $provider->method('getIdentifier')->willReturn($identifier);
        $provider->method('getName')->willReturn($name);
        $provider->method('isAvailable')->willReturn($available);
        $provider->method('supportsFeature')->willReturn($supports);

        return $provider;
    }

    #[Test]
    public function registerProviderExposesItInTheProviderList(): void
    {
        $registry = $this->createRegistry();
        $registry->registerProvider($this->providerStub('openai', 'OpenAI'));

        self::assertSame(['openai' => 'OpenAI'], $registry->getProviderList());
    }

    #[Test]
    public function getProviderReturnsTheRegisteredInstance(): void
    {
        $registry = $this->createRegistry();
        $provider = $this->providerStub('openai');
        $registry->registerProvider($provider);

        self::assertSame($provider, $registry->getProvider('openai'));
    }

    #[Test]
    public function getProviderThrowsForUnknownIdentifier(): void
    {
        $registry = $this->createRegistry();

        $this->expectException(ProviderException::class);
        $this->expectExceptionCode(6273324883);
        $this->expectExceptionMessage('Provider "nope" not found');

        $registry->getProvider('nope');
    }

    #[Test]
    public function getProviderThrowsWhenNoIdentifierGiven(): void
    {
        $registry = $this->createRegistry();

        $this->expectException(ProviderException::class);
        $this->expectExceptionCode(4867297358);
        $this->expectExceptionMessage('No provider specified and no default provider configured');

        $registry->getProvider();
    }

    #[Test]
    public function getAvailableProvidersFiltersOutUnavailableOnes(): void
    {
        $registry = $this->createRegistry();
        $registry->registerProvider($this->providerStub('up', 'Up', true));
        $registry->registerProvider($this->providerStub('down', 'Down', false));

        $available = $registry->getAvailableProviders();

        self::assertArrayHasKey('up', $available);
        self::assertArrayNotHasKey('down', $available);
    }

    #[Test]
    public function hasAvailableProviderReflectsAvailability(): void
    {
        $registry = $this->createRegistry();
        self::assertFalse($registry->hasAvailableProvider());

        $registry->registerProvider($this->providerStub('down', 'Down', false));
        self::assertFalse($registry->hasAvailableProvider());

        $registry->registerProvider($this->providerStub('up', 'Up', true));
        self::assertTrue($registry->hasAvailableProvider());
    }

    #[Test]
    public function supportsFeatureReturnsTrueWhenProviderSupportsIt(): void
    {
        $registry = $this->createRegistry();
        $registry->registerProvider($this->providerStub('openai', 'OpenAI', true, true));

        self::assertTrue($registry->supportsFeature('vision', 'openai'));
    }

    #[Test]
    public function supportsFeatureReturnsFalseWhenProviderDoesNotSupportIt(): void
    {
        $registry = $this->createRegistry();
        $registry->registerProvider($this->providerStub('openai', 'OpenAI', true, false));

        self::assertFalse($registry->supportsFeature('vision', 'openai'));
    }

    #[Test]
    public function supportsFeatureReturnsFalseForUnknownProvider(): void
    {
        $registry = $this->createRegistry();

        self::assertFalse($registry->supportsFeature('vision', 'unknown'));
    }

    #[Test]
    public function getProviderConfigurationReturnsTheEntryFromExtensionConfiguration(): void
    {
        $registry = $this->createRegistry([
            'providers' => ['openai' => ['apiKey' => 'k', 'defaultModel' => 'gpt']],
        ]);

        self::assertSame(['apiKey' => 'k', 'defaultModel' => 'gpt'], $registry->getProviderConfiguration('openai'));
    }

    #[Test]
    public function getProviderConfigurationReturnsEmptyArrayForUnknownProvider(): void
    {
        $registry = $this->createRegistry(['providers' => []]);

        self::assertSame([], $registry->getProviderConfiguration('unknown'));
    }

    #[Test]
    public function registerProviderConfiguresProviderFromExtensionConfiguration(): void
    {
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([
            'providers' => ['openai' => ['apiKey' => 'from-config']],
        ]);
        $registry = new KeyedProviderRegistry($extensionConfiguration, self::createStub(LoggerInterface::class));

        $provider = self::createMock(ProviderInterface::class);
        $provider->method('getIdentifier')->willReturn('openai');
        $provider->expects(self::once())->method('configure')->with(['apiKey' => 'from-config']);

        $registry->registerProvider($provider);
    }

    #[Test]
    public function configureProviderDelegatesToTheRegisteredProvider(): void
    {
        $registry = $this->createRegistry();
        $provider = self::createMock(ProviderInterface::class);
        $provider->method('getIdentifier')->willReturn('openai');
        $registry->registerProvider($provider);

        $provider->expects(self::once())->method('configure')->with(['apiKey' => 'runtime']);

        $registry->configureProvider('openai', ['apiKey' => 'runtime']);
    }

    #[Test]
    public function configureProviderThrowsForUnknownProvider(): void
    {
        $registry = $this->createRegistry();

        $this->expectException(ProviderException::class);
        $this->expectExceptionCode(5332497319);
        $this->expectExceptionMessage('Provider "unknown" not found');

        $registry->configureProvider('unknown', []);
    }

    #[Test]
    public function constructorLogsWarningAndKeepsEmptyConfigurationWhenExtensionConfigThrows(): void
    {
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException(new Exception('boom'));

        $logger = self::createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('Failed to load extension configuration', self::anything());

        $registry = new KeyedProviderRegistry($extensionConfiguration, $logger);

        // Configuration failed to load, so no provider configuration is available.
        self::assertSame([], $registry->getProviderConfiguration('openai'));
    }
}
