<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Attribute;

use Attribute;

/**
 * Marks a class as an LLM provider for auto-registration with LlmServiceManager.
 *
 * Providers bearing this attribute are automatically tagged `nr_llm.provider`
 * AND made public by ProviderCompilerPass at container compile time. You no
 * longer need to add a `tags:` entry or set `public: true` in Services.yaml
 * for the provider when you use this attribute.
 *
 * Higher priority providers are registered first with the service manager.
 * Priority is an ordering hint only; providers are still resolved by their
 * identifier at runtime.
 *
 * Example:
 *
 *   #[AsLlmProvider(priority: 100)]
 *   final class OpenAiProvider extends AbstractProvider { ... }
 *
 * Scan scope: the compiler pass only reflects service definitions whose
 * class is in the `Netresearch\NrLlm\` namespace. Third-party providers
 * outside that namespace should keep using the legacy yaml-tag path —
 * `tags: [{ name: nr_llm.provider, priority: N }]` in their own
 * Services.yaml — which remains fully supported and takes precedence when
 * both mechanisms are present on the same service.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsLlmProvider
{
    public const TAG_NAME = 'nr_llm.provider';

    public function __construct(
        public int $priority = 0,
    ) {}
}
