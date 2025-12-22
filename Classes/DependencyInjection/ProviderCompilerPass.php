<?php

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
            $priorityA = $a[0]['priority'] ?? 0;
            $priorityB = $b[0]['priority'] ?? 0;
            return $priorityB <=> $priorityA;
        });

        // Add method calls to register each provider
        foreach ($taggedServices as $id => $tags) {
            $managerDefinition->addMethodCall('registerProvider', [new Reference($id)]);
        }
    }
}
