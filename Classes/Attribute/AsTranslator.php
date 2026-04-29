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
 * `nr_llm.translator` by `TranslatorCompilerPass` at container compile
 * time. You no longer need to add a `tags:` entry in `Services.yaml`
 * for the translator when you use this attribute.
 *
 * This attribute is a pure marker — it carries no fields. The
 * translator's identifier comes from `TranslatorInterface::getIdentifier()`
 * (used by `TranslatorRegistry` for lookup) and its registration order
 * comes from `TranslatorInterface::getPriority()` (used by Symfony's
 * `#[TaggedIterator(defaultPriorityMethod: 'getPriority')]` in
 * `TranslatorRegistry::__construct()`). Keeping those values on the
 * interface methods rather than duplicating them in this attribute
 * eliminates a class of attribute-vs-method drift bugs.
 *
 * Example:
 *
 *   #[AsTranslator]
 *   final class DeepLTranslator implements TranslatorInterface
 *   {
 *       public function getIdentifier(): string { return 'deepl'; }
 *       public function getPriority(): int { return 90; }
 *       // ...
 *   }
 *
 * Scan scope: the compiler pass only reflects service definitions whose
 * class is in the `Netresearch\NrLlm\Specialized\Translation\` namespace.
 * Third-party translators outside that namespace should keep using the
 * legacy yaml-tag path — `tags: [{ name: nr_llm.translator }]` in their
 * own Services.yaml — which remains fully supported.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsTranslator
{
    public const TAG_NAME = 'nr_llm.translator';
}
