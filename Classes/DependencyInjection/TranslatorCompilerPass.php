<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\DependencyInjection;

use Netresearch\NrLlm\Attribute\AsTranslator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Auto-tag specialized translators carrying the `#[AsTranslator]` attribute.
 *
 * Mirrors the provider-side `ProviderCompilerPass`: scans the
 * `Netresearch\NrLlm\Specialized\Translation\` namespace, tags matching
 * services with `nr_llm.translator`, and makes them public. The actual
 * collection step is owned by `TranslatorRegistry::__construct()` which
 * uses Symfony's `#[TaggedIterator('nr_llm.translator')]`, so this pass
 * is the only piece of glue needed — no manual `addMethodCall` here.
 *
 * Services that already carry the `nr_llm.translator` tag (from yaml)
 * are left untouched to avoid double-registration. Third-party
 * translators outside the scan namespace can opt in via the legacy yaml
 * tag path, which remains the supported escape hatch.
 */
final class TranslatorCompilerPass implements CompilerPassInterface
{
    /**
     * Namespace prefix used to limit the attribute-discovery scan.
     * Third-party translators that sit outside this namespace can still
     * opt in via a manual `nr_llm.translator` tag in their own services.yaml.
     */
    private const SCAN_NAMESPACE_PREFIX = 'Netresearch\\NrLlm\\Specialized\\Translation\\';

    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $serviceId => $definition) {
            if ($definition->hasTag(AsTranslator::TAG_NAME)) {
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

            $attributes = $reflection->getAttributes(AsTranslator::class);
            if ($attributes === []) {
                continue;
            }

            $attribute = $attributes[0]->newInstance();

            $definition->addTag(AsTranslator::TAG_NAME, ['priority' => $attribute->priority]);
            $definition->setPublic(true);
        }
    }
}
