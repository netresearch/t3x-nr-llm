<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Option;

/**
 * Options for translation requests.
 *
 * @phpstan-consistent-constructor
 *
 * @api
 */
class TranslationOptions extends AbstractOptions implements BudgetAwareOptionsInterface
{
    use BudgetFieldsTrait;

    private const FORMALITIES = ['default', 'formal', 'informal'];

    private const DOMAINS = ['general', 'technical', 'medical', 'legal', 'marketing'];

    /** DeepL's two markup modes (ADR-209). */
    private const TAG_HANDLINGS = ['html', 'xml'];

    public function __construct(
        private ?string $formality = null,
        private ?string $domain = null,
        /** @var array<string, string>|null Term glossary ['source' => 'translation'] */
        private ?array $glossary = null,
        private ?string $context = null,
        private ?bool $preserveFormatting = true,
        private ?float $temperature = null,
        private ?int $maxTokens = null,
        private ?string $provider = null,
        private ?string $model = null,
        private ?string $configuration = null,
        ?int $beUserUid = null,
        ?float $plannedCost = null,
        // Last, not grouped with the other fluent-setter-only fields above:
        // any positional caller relying on $beUserUid/$plannedCost being the
        // last two constructor params (BC) is unaffected only if new fields
        // are appended after them, not inserted before.
        private ?string $translator = null,
        // Appended for the same reason as $translator (ADR-208).
        private ?string $site = null,
        // Appended for the same reason (ADR-209).
        private ?string $tagHandling = null,
        private ?int $cacheTtl = null,
    ) {
        $this->setBudgetFields($beUserUid, $plannedCost);
        $this->validate();
    }

    // ========================================
    // Factory Presets
    // ========================================

    /**
     * Create options for formal business translation.
     */
    public static function formal(): static
    {
        return new static(
            formality: 'formal',
            domain: 'general',
            temperature: 0.2,
        );
    }

    /**
     * Create options for informal/casual translation.
     */
    public static function informal(): static
    {
        return new static(
            formality: 'informal',
            domain: 'general',
            temperature: 0.5,
        );
    }

    /**
     * Create options for technical documentation.
     */
    public static function technical(): static
    {
        return new static(
            formality: 'formal',
            domain: 'technical',
            preserveFormatting: true,
            temperature: 0.1,
        );
    }

    /**
     * Create options for marketing content.
     */
    public static function marketing(): static
    {
        return new static(
            formality: 'default',
            domain: 'marketing',
            temperature: 0.6,
        );
    }

    /**
     * Create options for medical/scientific translation.
     */
    public static function medical(): static
    {
        return new static(
            formality: 'formal',
            domain: 'medical',
            preserveFormatting: true,
            temperature: 0.1,
        );
    }

    /**
     * Create options for legal translation.
     */
    public static function legal(): static
    {
        return new static(
            formality: 'formal',
            domain: 'legal',
            preserveFormatting: true,
            temperature: 0.1,
        );
    }

    // ========================================
    // Fluent Setters
    // ========================================

    public function withFormality(string $formality): static
    {
        $clone = clone $this;
        $clone->formality = $formality;
        $clone->validate();
        return $clone;
    }

    public function withDomain(string $domain): static
    {
        $clone = clone $this;
        $clone->domain = $domain;
        $clone->validate();
        return $clone;
    }

    /**
     * @param array<string, string> $glossary
     */
    public function withGlossary(array $glossary): static
    {
        $clone = clone $this;
        $clone->glossary = $glossary;
        return $clone;
    }

    public function withContext(string $context): static
    {
        $clone = clone $this;
        $clone->context = $context;
        return $clone;
    }

    public function withPreserveFormatting(bool $preserveFormatting): static
    {
        $clone = clone $this;
        $clone->preserveFormatting = $preserveFormatting;
        return $clone;
    }

    public function withTemperature(float $temperature): static
    {
        $clone = clone $this;
        $clone->temperature = $temperature;
        $clone->validate();
        return $clone;
    }

    public function withMaxTokens(int $maxTokens): static
    {
        $clone = clone $this;
        $clone->maxTokens = $maxTokens;
        $clone->validate();
        return $clone;
    }

    public function withProvider(string $provider): static
    {
        $clone = clone $this;
        $clone->provider = $provider;
        return $clone;
    }

    public function withModel(string $model): static
    {
        $clone = clone $this;
        $clone->model = $model;
        return $clone;
    }

    /**
     * Pin a stored LlmConfiguration by identifier. On the specialized-translator
     * path (`translateWithTranslator()`), the translator bound to that
     * configuration (`LlmConfiguration::getTranslator()`) is used when set.
     */
    public function withConfiguration(string $configuration): static
    {
        $clone = clone $this;
        $clone->configuration = $configuration;
        return $clone;
    }

    /**
     * Force a specific translator by identifier (e.g. 'deepl', 'llm'),
     * bypassing configuration-based resolution. Highest priority in
     * `TranslationService::resolveTranslator()` — takes precedence over a
     * pinned configuration's own translator, if both are set.
     */
    public function withTranslator(string $translator): static
    {
        $clone = clone $this;
        $clone->translator = $translator;
        return $clone;
    }

    /**
     * Name the site the text belongs to, by its identifier (config/sites/<identifier>).
     * When no explicit glossary is set, `TranslationService` then applies the
     * glossary that site keeps for the language pair (ADR-208): as prompt terms
     * on the LLM path, as a DeepL glossary on the DeepL path.
     */
    public function withSite(string $site): static
    {
        $clone = clone $this;
        $clone->site = $site;
        return $clone;
    }

    /**
     * Declare the text as markup: `html` or `xml` (ADR-209). DeepL receives it
     * as `tag_handling`, so tags and attributes are kept and only the text
     * between them is translated. The LLM translator already keeps tags while
     * `preserveFormatting` is on, which is the default.
     */
    public function withTagHandling(string $tagHandling): static
    {
        $clone = clone $this;
        $clone->tagHandling = $tagHandling;
        $clone->validate();
        return $clone;
    }

    /**
     * Cache the result of `TranslationService::translateWithTranslator()` for
     * this many seconds (ADR-209). Off unless set: a cached answer skips the
     * translator, its budget pre-flight and its usage row, which a caller has
     * to choose knowingly. 0 switches it off again.
     */
    public function withCacheTtl(int $cacheTtl): static
    {
        $clone = clone $this;
        $clone->cacheTtl = $cacheTtl;
        $clone->validate();
        return $clone;
    }

    // Budget pre-flight setters provided by `BudgetFieldsTrait`.

    // ========================================
    // Getters
    // ========================================

    public function getFormality(): ?string
    {
        return $this->formality;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    /**
     * @return array<string, string>|null
     */
    public function getGlossary(): ?array
    {
        return $this->glossary;
    }

    public function getContext(): ?string
    {
        return $this->context;
    }

    public function getPreserveFormatting(): ?bool
    {
        return $this->preserveFormatting;
    }

    public function getTemperature(): ?float
    {
        return $this->temperature;
    }

    public function getMaxTokens(): ?int
    {
        return $this->maxTokens;
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function getConfiguration(): ?string
    {
        return $this->configuration;
    }

    public function getTranslator(): ?string
    {
        return $this->translator;
    }

    public function getSite(): ?string
    {
        return $this->site;
    }

    public function getTagHandling(): ?string
    {
        return $this->tagHandling;
    }

    public function getCacheTtl(): ?int
    {
        return $this->cacheTtl;
    }

    // Budget pre-flight getters provided by `BudgetFieldsTrait`.

    // ========================================
    // Array Conversion
    // ========================================

    public function toArray(): array
    {
        return $this->filterNull([
            'formality' => $this->formality,
            'domain' => $this->domain,
            'glossary' => $this->glossary,
            'context' => $this->context,
            'preserve_formatting' => $this->preserveFormatting,
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
            'provider' => $this->provider,
            'model' => $this->model,
            'configuration' => $this->configuration,
            'translator' => $this->translator,
            // Read by DeepLOptions::fromArray() on the DeepL path.
            'tag_handling' => $this->tagHandling,
        ]);
    }

    // ========================================
    // Validation
    // ========================================

    private function validate(): void
    {
        if ($this->formality !== null) {
            self::validateEnum($this->formality, self::FORMALITIES, 'formality');
        }

        if ($this->domain !== null) {
            self::validateEnum($this->domain, self::DOMAINS, 'domain');
        }

        if ($this->temperature !== null) {
            self::validateRange($this->temperature, 0.0, 2.0, 'temperature');
        }

        if ($this->maxTokens !== null) {
            self::validatePositiveInt($this->maxTokens, 'max_tokens');
        }

        if ($this->tagHandling !== null) {
            self::validateEnum($this->tagHandling, self::TAG_HANDLINGS, 'tag_handling');
        }

        if ($this->cacheTtl !== null) {
            self::validateRange($this->cacheTtl, 0, PHP_INT_MAX, 'cache_ttl');
        }

        $this->validateBudgetFields();
    }
}
