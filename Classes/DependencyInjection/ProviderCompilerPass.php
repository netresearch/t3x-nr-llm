<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\DependencyInjection;

use Netresearch\NrLlm\Attribute\AsLlmProvider;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class ProviderCompilerPass implements CompilerPassInterface
{
    /**
     * Namespace prefix used to limit the attribute-discovery scan.
     * Third-party providers that sit outside this namespace can still opt
     * in via a manual `nr_llm.provider` tag in their own services.yaml.
     */
    private const SCAN_NAMESPACE_PREFIX = 'Netresearch\\NrLlm\\';

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
     * Scan service definitions whose class lives in the Netresearch\NrLlm\
     * namespace for #[AsLlmProvider] and tag them so the priority-based
     * sorting pass picks them up. Narrowing by namespace keeps the
     * reflection cost bounded: TYPO3 installs have hundreds of container
     * definitions and most of them have nothing to do with LLM providers.
     *
     * Uses ContainerBuilder::getReflectionClass($class, false) to reuse
     * Symfony's reflection cache and container-resource tracking instead
     * of calling `new ReflectionClass()` directly.
     *
     * Services that already carry the `nr_llm.provider` tag (from yaml)
     * are left untouched to avoid double-registration. Attribute-discovered
     * providers are set public so TYPO3 backend diagnostics can resolve
     * them by class name.
     */
    private function autoTagAttributeProviders(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $serviceId => $definition) {
            if ($definition->hasTag(AsLlmProvider::TAG_NAME)) {
                continue;
            }

            $class = $definition->getClass() ?? $serviceId;
            if (!is_string($class) || $class === '' || !str_starts_with($class, self::SCAN_NAMESPACE_PREFIX)) {
                continue;
            }

            $reflection = $container->getReflectionClass($class, false);
            if ($reflection === null) {
                continue;
            }

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
