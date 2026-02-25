<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\DependencyInjection;

use Netresearch\NrLlm\Service\LlmServiceManager;
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

        $managerDefinition = $container->findDefinition(LlmServiceManager::class);

        // Find all services tagged with 'nr_llm.provider'
        $taggedServices = $container->findTaggedServiceIds('nr_llm.provider');

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
}
