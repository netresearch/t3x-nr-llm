<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\DependencyInjection;

use Netresearch\NrLlm\Attribute\AsLlmProvider;
use Netresearch\NrLlm\Service\LlmServiceManager;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class ProviderCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(LlmServiceManager::class)) {
            return;
        }

        // Step 1: auto-tag providers that carry #[AsLlmProvider]. Existing
        // services.yaml `tags:` entries keep working and take precedence if
        // both are present (the attribute pass skips already-tagged services).
        $this->autoTagAttributeProviders($container);

        $managerDefinition = $container->findDefinition(LlmServiceManager::class);

        // Step 2: collect every service tagged `nr_llm.provider` (from either
        // the attribute above or a manual yaml tag) and register them.
        $taggedServices = $container->findTaggedServiceIds(AsLlmProvider::TAG_NAME);

        // Sort by priority (higher first)
        uasort($taggedServices, static function (array $a, array $b): int {
            $tagA = is_array($a[0] ?? null) ? $a[0] : [];
            $tagB = is_array($b[0] ?? null) ? $b[0] : [];
            $priorityA = is_int($tagA['priority'] ?? null) ? $tagA['priority'] : 0;
            $priorityB = is_int($tagB['priority'] ?? null) ? $tagB['priority'] : 0;
            return $priorityB <=> $priorityA;
        });

        // Add method calls to register each provider
        foreach ($taggedServices as $id => $tags) {
            $managerDefinition->addMethodCall('registerProvider', [new Reference($id)]);
        }
    }

    /**
     * Scan all service definitions for classes bearing #[AsLlmProvider]
     * and tag them so the priority-based sorting pass picks them up.
     *
     * Services that already carry the `nr_llm.provider` tag (from yaml)
     * are left untouched to avoid double-registration.
     */
    private function autoTagAttributeProviders(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $serviceId => $definition) {
            $class = $definition->getClass() ?? $serviceId;
            if (!is_string($class) || $class === '' || !class_exists($class)) {
                continue;
            }

            if ($definition->hasTag(AsLlmProvider::TAG_NAME)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            $attributes = $reflection->getAttributes(AsLlmProvider::class);
            if ($attributes === []) {
                continue;
            }

            $attribute = $attributes[0]->newInstance();

            $definition->addTag(AsLlmProvider::TAG_NAME, ['priority' => $attribute->priority]);
            $definition->setPublic(true);
        }
    }
}
