<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Attribute;

use Attribute;

/**
 * Marks a class as a specialized translator for auto-registration with TranslatorRegistry.
 *
 * Translators bearing this attribute are automatically tagged
 * `nr_llm.translator` AND made public by TranslatorCompilerPass at
 * container compile time. You no longer need to add a `tags:` entry or
 * set `public: true` in Services.yaml for the translator when you use
 * this attribute.
 *
 * Higher priority translators are listed first when registry consumers
 * iterate. Priority is an ordering hint only; translators are still
 * resolved by their identifier at runtime
 * (`TranslatorRegistry::get($identifier)`).
 *
 * Example:
 *
 *   #[AsTranslator(identifier: 'deepl', priority: 90)]
 *   final class DeepLTranslator implements TranslatorInterface { ... }
 *
 * Scan scope: the compiler pass only reflects service definitions whose
 * class is in the `Netresearch\NrLlm\Specialized\Translation\` namespace.
 * Third-party translators outside that namespace should keep using the
 * legacy yaml-tag path — `tags: [{ name: nr_llm.translator, priority: N }]`
 * in their own Services.yaml — which remains fully supported and takes
 * precedence when both mechanisms are present on the same service.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsTranslator
{
    public const TAG_NAME = 'nr_llm.translator';

    public function __construct(
        public string $identifier,
        public int $priority = 0,
    ) {}
}
