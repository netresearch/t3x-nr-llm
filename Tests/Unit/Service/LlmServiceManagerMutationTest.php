<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
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
    private function createManager(array $config = []): LlmServiceManager
    {
        $extensionConfigMock = $this->createMock(ExtensionConfiguration::class);
        $extensionConfigMock->method('get')
            ->with('nr_llm')
            ->willReturn($config);

        $loggerMock = $this->createMock(LoggerInterface::class);

        return new LlmServiceManager($extensionConfigMock, $loggerMock);
    }

    private function createProviderMock(string $identifier, string $name = 'Test'): ProviderInterface
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('getIdentifier')->willReturn($identifier);
        $provider->method('getName')->willReturn($name);
        $provider->method('isAvailable')->willReturn(true);
        return $provider;
    }

    #[Test]
    public function registerProviderStoresProvider(): void
    {
        $manager = $this->createManager();
        $provider = $this->createProviderMock('test');

        $manager->registerProvider($provider);

        $this->assertSame($provider, $manager->getProvider('test'));
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
        $provider = $this->createProviderMock('test');
        $manager->registerProvider($provider);

        $result = $manager->getProvider(null);

        $this->assertSame($provider, $result);
    }

    #[Test]
    public function getAvailableProvidersFiltersUnavailable(): void
    {
        $manager = $this->createManager();

        $available = $this->createMock(ProviderInterface::class);
        $available->method('getIdentifier')->willReturn('available');
        $available->method('isAvailable')->willReturn(true);

        $unavailable = $this->createMock(ProviderInterface::class);
        $unavailable->method('getIdentifier')->willReturn('unavailable');
        $unavailable->method('isAvailable')->willReturn(false);

        $manager->registerProvider($available);
        $manager->registerProvider($unavailable);

        $result = $manager->getAvailableProviders();

        $this->assertArrayHasKey('available', $result);
        $this->assertArrayNotHasKey('unavailable', $result);
    }

    #[Test]
    public function hasAvailableProviderReturnsFalseWhenEmpty(): void
    {
        $manager = $this->createManager();

        $this->assertFalse($manager->hasAvailableProvider());
    }

    #[Test]
    public function hasAvailableProviderReturnsTrueWhenProviderAvailable(): void
    {
        $manager = $this->createManager();
        $provider = $this->createProviderMock('test');
        $manager->registerProvider($provider);

        $this->assertTrue($manager->hasAvailableProvider());
    }

    #[Test]
    public function getProviderListReturnsIdentifierNameMap(): void
    {
        $manager = $this->createManager();
        $provider1 = $this->createProviderMock('openai', 'OpenAI');
        $provider2 = $this->createProviderMock('claude', 'Anthropic Claude');

        $manager->registerProvider($provider1);
        $manager->registerProvider($provider2);

        $result = $manager->getProviderList();

        $this->assertEquals([
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
        $provider = $this->createProviderMock('test');
        $manager->registerProvider($provider);

        $manager->setDefaultProvider('test');

        $this->assertEquals('test', $manager->getDefaultProvider());
    }

    #[Test]
    public function getDefaultProviderReturnsNullWhenNotSet(): void
    {
        $manager = $this->createManager();

        $this->assertNull($manager->getDefaultProvider());
    }

    #[Test]
    public function getDefaultProviderReturnsConfiguredDefault(): void
    {
        $manager = $this->createManager(['defaultProvider' => 'configured']);

        $this->assertEquals('configured', $manager->getDefaultProvider());
    }

    #[Test]
    public function getProviderConfigurationReturnsEmptyArrayForUnknown(): void
    {
        $manager = $this->createManager();

        $result = $manager->getProviderConfiguration('unknown');

        $this->assertEquals([], $result);
    }

    #[Test]
    public function getProviderConfigurationReturnsStoredConfig(): void
    {
        $config = [
            'providers' => [
                'openai' => [
                    'apiKey' => 'test-key',
                    'model' => 'gpt-5.2',
                ],
            ],
        ];
        $manager = $this->createManager($config);

        $result = $manager->getProviderConfiguration('openai');

        $this->assertEquals(['apiKey' => 'test-key', 'model' => 'gpt-5.2'], $result);
    }

    #[Test]
    public function configureProviderThrowsWhenProviderNotFound(): void
    {
        $manager = $this->createManager();

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Provider "unknown" not found');

        $manager->configureProvider('unknown', ['apiKey' => 'test']);
    }

    #[Test]
    public function configureProviderCallsProviderConfigure(): void
    {
        $manager = $this->createManager();

        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('getIdentifier')->willReturn('test');
        $provider->expects($this->atLeastOnce())
            ->method('configure')
            ->with(['apiKey' => 'new-key']);

        $manager->registerProvider($provider);
        $manager->configureProvider('test', ['apiKey' => 'new-key']);
    }

    #[Test]
    public function registerProviderConfiguresFromExtensionConfig(): void
    {
        $config = [
            'providers' => [
                'test' => ['apiKey' => 'configured-key'],
            ],
        ];
        $manager = $this->createManager($config);

        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('getIdentifier')->willReturn('test');
        $provider->expects($this->once())
            ->method('configure')
            ->with(['apiKey' => 'configured-key']);

        $manager->registerProvider($provider);
    }

    #[Test]
    public function registerProviderSkipsConfigureWhenNoConfig(): void
    {
        $manager = $this->createManager();

        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('getIdentifier')->willReturn('test');
        $provider->expects($this->never())
            ->method('configure');

        $manager->registerProvider($provider);
    }

    #[Test]
    public function supportsFeatureReturnsFalseForUnknownProvider(): void
    {
        $manager = $this->createManager();

        $result = $manager->supportsFeature('chat', 'unknown');

        $this->assertFalse($result);
    }

    #[Test]
    public function supportsFeatureReturnsTrueWhenProviderSupports(): void
    {
        $manager = $this->createManager(['defaultProvider' => 'test']);

        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('getIdentifier')->willReturn('test');
        $provider->method('supportsFeature')
            ->with('chat')
            ->willReturn(true);

        $manager->registerProvider($provider);

        $result = $manager->supportsFeature('chat');

        $this->assertTrue($result);
    }
}
