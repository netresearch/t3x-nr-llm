<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\DependencyInjection;

use Netresearch\NrLlm\DependencyInjection\ProviderCompilerPass;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(ProviderCompilerPass::class)]
class ProviderCompilerPassTest extends AbstractUnitTestCase
{
    #[Test]
    public function processReturnsEarlyWhenManagerNotRegistered(): void
    {
        $container = $this->createMock(ContainerBuilder::class);
        $container->method('has')
            ->with(LlmServiceManager::class)
            ->willReturn(false);

        $container->expects(self::never())
            ->method('findDefinition');

        $compilerPass = new ProviderCompilerPass();
        $compilerPass->process($container);
    }

    #[Test]
    public function processRegistersTaggedProviders(): void
    {
        $container = $this->createMock(ContainerBuilder::class);
        $definition = $this->createMock(Definition::class);

        $container->method('has')
            ->with(LlmServiceManager::class)
            ->willReturn(true);

        $container->method('findDefinition')
            ->with(LlmServiceManager::class)
            ->willReturn($definition);

        $container->method('findTaggedServiceIds')
            ->with('nr_llm.provider')
            ->willReturn([
                'provider.openai' => [['priority' => 10]],
                'provider.claude' => [['priority' => 20]],
            ]);

        // Expect providers registered in priority order (higher first)
        $definition->expects(self::exactly(2))
            ->method('addMethodCall')
            ->with('registerProvider', self::isType('array'));

        $compilerPass = new ProviderCompilerPass();
        $compilerPass->process($container);
    }

    #[Test]
    public function processSortsProvidersByPriority(): void
    {
        $container = $this->createMock(ContainerBuilder::class);
        $definition = $this->createMock(Definition::class);

        $container->method('has')
            ->with(LlmServiceManager::class)
            ->willReturn(true);

        $container->method('findDefinition')
            ->with(LlmServiceManager::class)
            ->willReturn($definition);

        $container->method('findTaggedServiceIds')
            ->with('nr_llm.provider')
            ->willReturn([
                'provider.low' => [['priority' => 5]],
                'provider.high' => [['priority' => 100]],
                'provider.medium' => [['priority' => 50]],
            ]);

        $registrationOrder = [];
        $definition->expects(self::exactly(3))
            ->method('addMethodCall')
            ->willReturnCallback(function (string $method, array $args) use (&$registrationOrder, $definition): Definition {
                $registrationOrder[] = $args[0];
                return $definition;
            });

        $compilerPass = new ProviderCompilerPass();
        $compilerPass->process($container);

        // Verify order: high (100), medium (50), low (5)
        self::assertInstanceOf(Reference::class, $registrationOrder[0]);
        self::assertInstanceOf(Reference::class, $registrationOrder[1]);
        self::assertInstanceOf(Reference::class, $registrationOrder[2]);
    }

    #[Test]
    public function processHandlesEmptyTaggedServices(): void
    {
        $container = $this->createMock(ContainerBuilder::class);
        $definition = $this->createMock(Definition::class);

        $container->method('has')
            ->with(LlmServiceManager::class)
            ->willReturn(true);

        $container->method('findDefinition')
            ->with(LlmServiceManager::class)
            ->willReturn($definition);

        $container->method('findTaggedServiceIds')
            ->with('nr_llm.provider')
            ->willReturn([]);

        $definition->expects(self::never())
            ->method('addMethodCall');

        $compilerPass = new ProviderCompilerPass();
        $compilerPass->process($container);
    }

    #[Test]
    public function processHandlesProvidersWithoutPriority(): void
    {
        $container = $this->createMock(ContainerBuilder::class);
        $definition = $this->createMock(Definition::class);

        $container->method('has')
            ->with(LlmServiceManager::class)
            ->willReturn(true);

        $container->method('findDefinition')
            ->with(LlmServiceManager::class)
            ->willReturn($definition);

        // Empty tag array means no priority specified
        $container->method('findTaggedServiceIds')
            ->with('nr_llm.provider')
            ->willReturn([
                'provider.a' => [],
                'provider.b' => [[]],
            ]);

        $definition->expects(self::exactly(2))
            ->method('addMethodCall')
            ->with('registerProvider', self::isType('array'));

        $compilerPass = new ProviderCompilerPass();
        $compilerPass->process($container);
    }

    #[Test]
    public function processHandlesNonArrayTagData(): void
    {
        $container = $this->createMock(ContainerBuilder::class);
        $definition = $this->createMock(Definition::class);

        $container->method('has')
            ->with(LlmServiceManager::class)
            ->willReturn(true);

        $container->method('findDefinition')
            ->with(LlmServiceManager::class)
            ->willReturn($definition);

        // Tags with non-array first element
        $container->method('findTaggedServiceIds')
            ->with('nr_llm.provider')
            ->willReturn([
                'provider.a' => ['not_an_array'],
            ]);

        $definition->expects(self::once())
            ->method('addMethodCall')
            ->with('registerProvider', self::isType('array'));

        $compilerPass = new ProviderCompilerPass();
        $compilerPass->process($container);
    }

    #[Test]
    public function processHandlesNonIntPriority(): void
    {
        $container = $this->createMock(ContainerBuilder::class);
        $definition = $this->createMock(Definition::class);

        $container->method('has')
            ->with(LlmServiceManager::class)
            ->willReturn(true);

        $container->method('findDefinition')
            ->with(LlmServiceManager::class)
            ->willReturn($definition);

        // Priority as string, should be treated as 0
        $container->method('findTaggedServiceIds')
            ->with('nr_llm.provider')
            ->willReturn([
                'provider.a' => [['priority' => 'high']],
                'provider.b' => [['priority' => 10]],
            ]);

        $definition->expects(self::exactly(2))
            ->method('addMethodCall')
            ->with('registerProvider', self::isType('array'));

        $compilerPass = new ProviderCompilerPass();
        $compilerPass->process($container);
    }
}
