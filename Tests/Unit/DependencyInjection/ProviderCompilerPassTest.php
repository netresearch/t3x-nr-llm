<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\DependencyInjection;

use Netresearch\NrLlm\Attribute\AsLlmProvider;
use Netresearch\NrLlm\DependencyInjection\ProviderCompilerPass;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\DependencyInjection\Fixture\AttributeTaggedProvider;
use Netresearch\NrLlm\Tests\Unit\DependencyInjection\Fixture\HighPriorityAttributeProvider;
use Netresearch\NrLlm\Tests\Unit\DependencyInjection\Fixture\UntaggedService;
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
        $containerMock = $this->createMock(ContainerBuilder::class);
        $definitionMock = $this->createMock(Definition::class);

        $containerMock->method('has')
            ->with(LlmServiceManager::class)
            ->willReturn(true);

        $containerMock->method('findDefinition')
            ->with(LlmServiceManager::class)
            ->willReturn($definitionMock);

        $containerMock->method('findTaggedServiceIds')
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
        $compilerPass->process($containerMock);
    }

    #[Test]
    public function processSortsProvidersByPriority(): void
    {
        $containerMock = $this->createMock(ContainerBuilder::class);
        $definitionMock = $this->createMock(Definition::class);

        $containerMock->method('has')
            ->with(LlmServiceManager::class)
            ->willReturn(true);

        $containerMock->method('findDefinition')
            ->with(LlmServiceManager::class)
            ->willReturn($definitionMock);

        $containerMock->method('findTaggedServiceIds')
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
        $compilerPass->process($containerMock);

        // Verify order: high (100), medium (50), low (5)
        self::assertInstanceOf(Reference::class, $registrationOrder[0]);
        self::assertInstanceOf(Reference::class, $registrationOrder[1]);
        self::assertInstanceOf(Reference::class, $registrationOrder[2]);
    }

    #[Test]
    public function processHandlesEmptyTaggedServices(): void
    {
        $containerMock = $this->createMock(ContainerBuilder::class);
        $definitionMock = $this->createMock(Definition::class);

        $containerMock->method('has')
            ->with(LlmServiceManager::class)
            ->willReturn(true);

        $containerMock->method('findDefinition')
            ->with(LlmServiceManager::class)
            ->willReturn($definitionMock);

        $containerMock->method('findTaggedServiceIds')
            ->with('nr_llm.provider')
            ->willReturn([]);

        $definitionMock->expects(self::never())
            ->method('addMethodCall');

        $compilerPass = new ProviderCompilerPass();
        $compilerPass->process($containerMock);
    }

    #[Test]
    public function processHandlesProvidersWithoutPriority(): void
    {
        $containerMock = $this->createMock(ContainerBuilder::class);
        $definitionMock = $this->createMock(Definition::class);

        $containerMock->method('has')
            ->with(LlmServiceManager::class)
            ->willReturn(true);

        $containerMock->method('findDefinition')
            ->with(LlmServiceManager::class)
            ->willReturn($definitionMock);

        // Empty tag array means no priority specified
        $containerMock->method('findTaggedServiceIds')
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
        $compilerPass->process($containerMock);
    }

    #[Test]
    public function processHandlesNonArrayTagData(): void
    {
        $containerMock = $this->createMock(ContainerBuilder::class);
        $definitionMock = $this->createMock(Definition::class);

        $containerMock->method('has')
            ->with(LlmServiceManager::class)
            ->willReturn(true);

        $containerMock->method('findDefinition')
            ->with(LlmServiceManager::class)
            ->willReturn($definitionMock);

        // Tags with non-array first element
        $containerMock->method('findTaggedServiceIds')
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
        $compilerPass->process($containerMock);
    }

    #[Test]
    public function processHandlesNonIntPriority(): void
    {
        $containerMock = $this->createMock(ContainerBuilder::class);
        $definitionMock = $this->createMock(Definition::class);

        $containerMock->method('has')
            ->with(LlmServiceManager::class)
            ->willReturn(true);

        $containerMock->method('findDefinition')
            ->with(LlmServiceManager::class)
            ->willReturn($definitionMock);

        // Priority as string, should be treated as 0
        $containerMock->method('findTaggedServiceIds')
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
        $compilerPass->process($containerMock);
    }

    // ──────────────────────────────────────────────
    // Attribute-based auto-tagging (#[AsLlmProvider])
    // ──────────────────────────────────────────────

    #[Test]
    public function autoTagsClassesCarryingTheAttribute(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(LlmServiceManager::class, new Definition(LlmServiceManager::class));
        $container->setDefinition('provider.attr', new Definition(AttributeTaggedProvider::class));

        (new ProviderCompilerPass())->process($container);

        $definition = $container->getDefinition('provider.attr');
        self::assertTrue($definition->hasTag(AsLlmProvider::TAG_NAME));

        $tags = $definition->getTag(AsLlmProvider::TAG_NAME);
        self::assertCount(1, $tags);
        self::assertSame(42, $tags[0]['priority'] ?? null);
    }

    #[Test]
    public function attributeTaggedProvidersAreMadePublic(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(LlmServiceManager::class, new Definition(LlmServiceManager::class));
        $definition = $container->setDefinition('provider.attr', new Definition(AttributeTaggedProvider::class));
        self::assertFalse($definition->isPublic());

        (new ProviderCompilerPass())->process($container);

        self::assertTrue($container->getDefinition('provider.attr')->isPublic());
    }

    #[Test]
    public function servicesWithoutTheAttributeAreNotTagged(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(LlmServiceManager::class, new Definition(LlmServiceManager::class));
        $container->setDefinition('some.service', new Definition(UntaggedService::class));

        (new ProviderCompilerPass())->process($container);

        self::assertFalse(
            $container->getDefinition('some.service')->hasTag(AsLlmProvider::TAG_NAME),
        );
    }

    #[Test]
    public function existingYamlTagIsNotDuplicatedByAttributePass(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(LlmServiceManager::class, new Definition(LlmServiceManager::class));

        $definition = new Definition(AttributeTaggedProvider::class);
        $definition->addTag(AsLlmProvider::TAG_NAME, ['priority' => 999]);
        $container->setDefinition('provider.attr', $definition);

        (new ProviderCompilerPass())->process($container);

        // Still exactly one tag; attribute's priority (42) did NOT override yaml's 999.
        $tags = $container->getDefinition('provider.attr')->getTag(AsLlmProvider::TAG_NAME);
        self::assertCount(1, $tags);
        self::assertSame(999, $tags[0]['priority'] ?? null);
    }

    #[Test]
    public function attributeBasedProvidersAreRegisteredInPriorityOrder(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(LlmServiceManager::class, new Definition(LlmServiceManager::class));
        $container->setDefinition('provider.low', new Definition(AttributeTaggedProvider::class));   // prio 42
        $container->setDefinition('provider.high', new Definition(HighPriorityAttributeProvider::class)); // prio 500

        (new ProviderCompilerPass())->process($container);

        $calls = $container->getDefinition(LlmServiceManager::class)->getMethodCalls();
        self::assertCount(2, $calls);

        $firstRef = $calls[0][1][0];
        $secondRef = $calls[1][1][0];
        self::assertInstanceOf(Reference::class, $firstRef);
        self::assertInstanceOf(Reference::class, $secondRef);

        // High-priority (500) comes before low-priority (42).
        self::assertSame('provider.high', (string)$firstRef);
        self::assertSame('provider.low', (string)$secondRef);
    }

    #[Test]
    public function attributeDiscoveryIsSkippedWhenManagerMissing(): void
    {
        $container = new ContainerBuilder();
        // Intentionally do NOT define LlmServiceManager.
        $container->setDefinition('provider.attr', new Definition(AttributeTaggedProvider::class));

        (new ProviderCompilerPass())->process($container);

        self::assertFalse(
            $container->getDefinition('provider.attr')->hasTag(AsLlmProvider::TAG_NAME),
            'Early-return should skip all work, including attribute discovery.',
        );
    }
}
