<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Mutation-killing tests for LlmServiceManager.
 */
#[CoversClass(LlmServiceManager::class)]
class LlmServiceManagerMutationTest extends AbstractUnitTestCase
{
    /**
     * @param array<string, mixed> $config
     */
    private function createManager(array $config = []): LlmServiceManager
    {
        $extensionConfigStub = self::createStub(ExtensionConfiguration::class);
        $extensionConfigStub->method('get')
            ->willReturn($config);

        $loggerStub = self::createStub(LoggerInterface::class);
        $adapterRegistryStub = self::createStub(ProviderAdapterRegistry::class);

        return new LlmServiceManager($extensionConfigStub, $loggerStub, $adapterRegistryStub);
    }

    private function createProviderStub(string $identifier, string $name = 'Test'): ProviderInterface
    {
        $provider = self::createStub(ProviderInterface::class);
        $provider->method('getIdentifier')->willReturn($identifier);
        $provider->method('getName')->willReturn($name);
        $provider->method('isAvailable')->willReturn(true);
        return $provider;
    }

    #[Test]
    public function registerProviderStoresProvider(): void
    {
        $manager = $this->createManager();
        $provider = $this->createProviderStub('test');

        $manager->registerProvider($provider);

        self::assertSame($provider, $manager->getProvider('test'));
    }

    #[Test]
    public function getProviderThrowsWhenNoDefaultAndNoIdentifier(): void
    {
        $manager = $this->createManager();

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('No provider specified and no default provider configured');

        $manager->getProvider();
    }

    #[Test]
    public function getProviderThrowsWhenProviderNotFound(): void
    {
        $manager = $this->createManager(['defaultProvider' => 'nonexistent']);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Provider "nonexistent" not found');

        $manager->getProvider();
    }

    #[Test]
    public function getProviderUsesDefaultWhenNullPassed(): void
    {
        $manager = $this->createManager(['defaultProvider' => 'test']);
        $provider = $this->createProviderStub('test');
        $manager->registerProvider($provider);

        $result = $manager->getProvider(null);

        self::assertSame($provider, $result);
    }

    #[Test]
    public function getAvailableProvidersFiltersUnavailable(): void
    {
        $manager = $this->createManager();

        $available = self::createStub(ProviderInterface::class);
        $available->method('getIdentifier')->willReturn('available');
        $available->method('isAvailable')->willReturn(true);

        $unavailable = self::createStub(ProviderInterface::class);
        $unavailable->method('getIdentifier')->willReturn('unavailable');
        $unavailable->method('isAvailable')->willReturn(false);

        $manager->registerProvider($available);
        $manager->registerProvider($unavailable);

        $result = $manager->getAvailableProviders();

        self::assertArrayHasKey('available', $result);
        self::assertArrayNotHasKey('unavailable', $result);
    }

    #[Test]
    public function hasAvailableProviderReturnsFalseWhenEmpty(): void
    {
        $manager = $this->createManager();

        self::assertFalse($manager->hasAvailableProvider());
    }

    #[Test]
    public function hasAvailableProviderReturnsTrueWhenProviderAvailable(): void
    {
        $manager = $this->createManager();
        $provider = $this->createProviderStub('test');
        $manager->registerProvider($provider);

        self::assertTrue($manager->hasAvailableProvider());
    }

    #[Test]
    public function getProviderListReturnsIdentifierNameMap(): void
    {
        $manager = $this->createManager();
        $provider1 = $this->createProviderStub('openai', 'OpenAI');
        $provider2 = $this->createProviderStub('claude', 'Anthropic Claude');

        $manager->registerProvider($provider1);
        $manager->registerProvider($provider2);

        $result = $manager->getProviderList();

        self::assertEquals([
            'openai' => 'OpenAI',
            'claude' => 'Anthropic Claude',
        ], $result);
    }

    #[Test]
    public function setDefaultProviderThrowsWhenProviderNotFound(): void
    {
        $manager = $this->createManager();

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Cannot set default: Provider "nonexistent" not found');

        $manager->setDefaultProvider('nonexistent');
    }

    #[Test]
    public function setDefaultProviderUpdatesDefault(): void
    {
        $manager = $this->createManager();
        $provider = $this->createProviderStub('test');
        $manager->registerProvider($provider);

        $manager->setDefaultProvider('test');

        self::assertEquals('test', $manager->getDefaultProvider());
    }

    #[Test]
    public function getDefaultProviderReturnsNullWhenNotSet(): void
    {
        $manager = $this->createManager();

        self::assertNull($manager->getDefaultProvider());
    }

    #[Test]
    public function getDefaultProviderReturnsConfiguredDefault(): void
    {
        $manager = $this->createManager(['defaultProvider' => 'configured']);

        self::assertEquals('configured', $manager->getDefaultProvider());
    }

    #[Test]
    public function getProviderConfigurationReturnsEmptyArrayForUnknown(): void
    {
        $manager = $this->createManager();

        $result = $manager->getProviderConfiguration('unknown');

        self::assertEquals([], $result);
    }

    #[Test]
    public function getProviderConfigurationReturnsStoredConfig(): void
    {
        $config = [
            'providers' => [
                'openai' => [
                    'apiKeyIdentifier' => 'test-key',
                    'model' => 'gpt-5.2',
                ],
            ],
        ];
        $manager = $this->createManager($config);

        $result = $manager->getProviderConfiguration('openai');

        self::assertEquals(['apiKeyIdentifier' => 'test-key', 'model' => 'gpt-5.2'], $result);
    }

    #[Test]
    public function configureProviderThrowsWhenProviderNotFound(): void
    {
        $manager = $this->createManager();

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Provider "unknown" not found');

        $manager->configureProvider('unknown', ['apiKeyIdentifier' => 'test']);
    }

    #[Test]
    public function configureProviderCallsProviderConfigure(): void
    {
        $manager = $this->createManager();

        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('getIdentifier')->willReturn('test');
        $provider->expects(self::atLeastOnce())
            ->method('configure')
            ->with(['apiKeyIdentifier' => 'new-key']);

        $manager->registerProvider($provider);
        $manager->configureProvider('test', ['apiKeyIdentifier' => 'new-key']);
    }

    #[Test]
    public function registerProviderConfiguresFromExtensionConfig(): void
    {
        $config = [
            'providers' => [
                'test' => ['apiKeyIdentifier' => 'configured-key'],
            ],
        ];
        $manager = $this->createManager($config);

        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('getIdentifier')->willReturn('test');
        $provider->expects(self::once())
            ->method('configure')
            ->with(['apiKeyIdentifier' => 'configured-key']);

        $manager->registerProvider($provider);
    }

    #[Test]
    public function registerProviderSkipsConfigureWhenNoConfig(): void
    {
        $manager = $this->createManager();

        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('getIdentifier')->willReturn('test');
        $provider->expects(self::never())
            ->method('configure');

        $manager->registerProvider($provider);
    }

    #[Test]
    public function supportsFeatureReturnsFalseForUnknownProvider(): void
    {
        $manager = $this->createManager();

        $result = $manager->supportsFeature('chat', 'unknown');

        self::assertFalse($result);
    }

    #[Test]
    public function supportsFeatureReturnsTrueWhenProviderSupports(): void
    {
        $manager = $this->createManager(['defaultProvider' => 'test']);

        $provider = self::createStub(ProviderInterface::class);
        $provider->method('getIdentifier')->willReturn('test');
        $provider->method('supportsFeature')
            ->with('chat')
            ->willReturn(true);

        $manager->registerProvider($provider);

        $result = $manager->supportsFeature('chat');

        self::assertTrue($result);
    }
}
