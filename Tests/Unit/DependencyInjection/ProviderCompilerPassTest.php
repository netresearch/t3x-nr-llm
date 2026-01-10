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
        $containerMock = $this->createMock(ContainerBuilder::class);
        $containerMock->method('has')
            ->with(LlmServiceManager::class)
            ->willReturn(false);

        $containerMock->expects(self::never())
            ->method('findDefinition');

        $compilerPass = new ProviderCompilerPass();
        $compilerPass->process($containerMock);
    }

    #[Test]
    public function processRegistersTaggedProviders(): void
    {
        $containerStub = self::createStub(ContainerBuilder::class);
        $definitionMock = $this->createMock(Definition::class);

        $containerStub->method('has')
            ->with(LlmServiceManager::class)
            ->willReturn(true);

        $containerStub->method('findDefinition')
            ->with(LlmServiceManager::class)
            ->willReturn($definitionMock);

        $containerStub->method('findTaggedServiceIds')
            ->with('nr_llm.provider')
            ->willReturn([
                'provider.openai' => [['priority' => 10]],
                'provider.claude' => [['priority' => 20]],
            ]);

        // Expect providers registered in priority order (higher first)
        $definitionMock->expects(self::exactly(2))
            ->method('addMethodCall')
            ->with(
                self::identicalTo('registerProvider'),
                self::callback(static fn(mixed $arg): bool => is_array($arg)),
            );

        $compilerPass = new ProviderCompilerPass();
        $compilerPass->process($containerStub);
    }

    #[Test]
    public function processSortsProvidersByPriority(): void
    {
        $containerStub = self::createStub(ContainerBuilder::class);
        $definitionMock = $this->createMock(Definition::class);

        $containerStub->method('has')
            ->with(LlmServiceManager::class)
            ->willReturn(true);

        $containerStub->method('findDefinition')
            ->with(LlmServiceManager::class)
            ->willReturn($definitionMock);

        $containerStub->method('findTaggedServiceIds')
            ->with('nr_llm.provider')
            ->willReturn([
                'provider.low' => [['priority' => 5]],
                'provider.high' => [['priority' => 100]],
                'provider.medium' => [['priority' => 50]],
            ]);

        $registrationOrder = [];
        $definitionMock->expects(self::exactly(3))
            ->method('addMethodCall')
            ->willReturnCallback(function (string $method, array $args) use (&$registrationOrder, $definitionMock): Definition {
                $registrationOrder[] = $args[0];
                return $definitionMock;
            });

        $compilerPass = new ProviderCompilerPass();
        $compilerPass->process($containerStub);

        // Verify order: high (100), medium (50), low (5)
        self::assertInstanceOf(Reference::class, $registrationOrder[0]);
        self::assertInstanceOf(Reference::class, $registrationOrder[1]);
        self::assertInstanceOf(Reference::class, $registrationOrder[2]);
    }

    #[Test]
    public function processHandlesEmptyTaggedServices(): void
    {
        $containerStub = self::createStub(ContainerBuilder::class);
        $definitionMock = $this->createMock(Definition::class);

        $containerStub->method('has')
            ->with(LlmServiceManager::class)
            ->willReturn(true);

        $containerStub->method('findDefinition')
            ->with(LlmServiceManager::class)
            ->willReturn($definitionMock);

        $containerStub->method('findTaggedServiceIds')
            ->with('nr_llm.provider')
            ->willReturn([]);

        $definitionMock->expects(self::never())
            ->method('addMethodCall');

        $compilerPass = new ProviderCompilerPass();
        $compilerPass->process($containerStub);
    }

    #[Test]
    public function processHandlesProvidersWithoutPriority(): void
    {
        $containerStub = self::createStub(ContainerBuilder::class);
        $definitionMock = $this->createMock(Definition::class);

        $containerStub->method('has')
            ->with(LlmServiceManager::class)
            ->willReturn(true);

        $containerStub->method('findDefinition')
            ->with(LlmServiceManager::class)
            ->willReturn($definitionMock);

        // Empty tag array means no priority specified
        $containerStub->method('findTaggedServiceIds')
            ->with('nr_llm.provider')
            ->willReturn([
                'provider.a' => [],
                'provider.b' => [[]],
            ]);

        $definitionMock->expects(self::exactly(2))
            ->method('addMethodCall')
            ->with(
                self::identicalTo('registerProvider'),
                self::callback(static fn(mixed $arg): bool => is_array($arg)),
            );

        $compilerPass = new ProviderCompilerPass();
        $compilerPass->process($containerStub);
    }

    #[Test]
    public function processHandlesNonArrayTagData(): void
    {
        $containerStub = self::createStub(ContainerBuilder::class);
        $definitionMock = $this->createMock(Definition::class);

        $containerStub->method('has')
            ->with(LlmServiceManager::class)
            ->willReturn(true);

        $containerStub->method('findDefinition')
            ->with(LlmServiceManager::class)
            ->willReturn($definitionMock);

        // Tags with non-array first element
        $containerStub->method('findTaggedServiceIds')
            ->with('nr_llm.provider')
            ->willReturn([
                'provider.a' => ['not_an_array'],
            ]);

        $definitionMock->expects(self::once())
            ->method('addMethodCall')
            ->with(
                self::identicalTo('registerProvider'),
                self::callback(static fn(mixed $arg): bool => is_array($arg)),
            );

        $compilerPass = new ProviderCompilerPass();
        $compilerPass->process($containerStub);
    }

    #[Test]
    public function processHandlesNonIntPriority(): void
    {
        $containerStub = self::createStub(ContainerBuilder::class);
        $definitionMock = $this->createMock(Definition::class);

        $containerStub->method('has')
            ->with(LlmServiceManager::class)
            ->willReturn(true);

        $containerStub->method('findDefinition')
            ->with(LlmServiceManager::class)
            ->willReturn($definitionMock);

        // Priority as string, should be treated as 0
        $containerStub->method('findTaggedServiceIds')
            ->with('nr_llm.provider')
            ->willReturn([
                'provider.a' => [['priority' => 'high']],
                'provider.b' => [['priority' => 10]],
            ]);

        $definitionMock->expects(self::exactly(2))
            ->method('addMethodCall')
            ->with(
                self::identicalTo('registerProvider'),
                self::callback(static fn(mixed $arg): bool => is_array($arg)),
            );

        $compilerPass = new ProviderCompilerPass();
        $compilerPass->process($containerStub);
    }
}
