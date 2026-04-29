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
final readonly class TranslatorCompilerPass implements CompilerPassInterface
{
    /**
     * Default namespace prefix used to limit the attribute-discovery scan.
     * Third-party translators that sit outside this namespace can still
     * opt in via a manual `nr_llm.translator` tag in their own services.yaml.
     */
    public const DEFAULT_SCAN_NAMESPACE_PREFIX = 'Netresearch\\NrLlm\\Specialized\\Translation\\';

    /**
     * @param string $scanNamespacePrefix Override the default for tests
     *                                    that need fixture classes outside
     *                                    the production namespace.
     */
    public function __construct(
        private string $scanNamespacePrefix = self::DEFAULT_SCAN_NAMESPACE_PREFIX,
    ) {}

    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $serviceId => $definition) {
            if ($definition->hasTag(AsTranslator::TAG_NAME)) {
                continue;
            }

            $class = $definition->getClass() ?? $serviceId;
            if (!is_string($class) || $class === '' || !str_starts_with($class, $this->scanNamespacePrefix)) {
                continue;
            }

            $reflection = $container->getReflectionClass($class, false);
            if ($reflection === null) {
                continue;
            }

            if ($reflection->getAttributes(AsTranslator::class) === []) {
                continue;
            }

            // Tag only — no priority on the tag itself, since
            // TranslatorRegistry uses #[TaggedIterator(defaultPriorityMethod:
            // 'getPriority')] to derive ordering from the interface method.
            // Services stay private: the registry consumes them via the
            // tagged iterator, so direct container lookup is never needed.
            $definition->addTag(AsTranslator::TAG_NAME);
        }
    }
}
