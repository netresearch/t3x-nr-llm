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
 * by ProviderCompilerPass at container compile time. You no longer need to
 * add a `tags:` entry for the provider in Services.yaml — only `public: true`
 * if the service must be fetched from the container directly.
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
 * Back-compat: existing services with `tags: [{ name: nr_llm.provider }]`
 * in Services.yaml continue to work. The attribute is additive.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsLlmProvider
{
    public const TAG_NAME = 'nr_llm.provider';

    public function __construct(
        public int $priority = 0,
    ) {}
}
