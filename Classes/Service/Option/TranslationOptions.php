<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Option;

/**
 * Options for translation requests
 */
class TranslationOptions extends AbstractOptions
{
    private const FORMALITIES = ['default', 'formal', 'informal'];
    private const DOMAINS = ['general', 'technical', 'medical', 'legal', 'marketing'];

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
    ) {
        $this->validate();
    }

    // ========================================
    // Factory Presets
    // ========================================

    /**
     * Create options for formal business translation
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
     * Create options for informal/casual translation
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
     * Create options for technical documentation
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
     * Create options for marketing content
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
     * Create options for medical/scientific translation
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
     * Create options for legal translation
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
        ]);
    }

    public static function fromArray(array $options): static
    {
        return new static(
            formality: $options['formality'] ?? null,
            domain: $options['domain'] ?? null,
            glossary: $options['glossary'] ?? null,
            context: $options['context'] ?? null,
            preserveFormatting: $options['preserve_formatting'] ?? true,
            temperature: isset($options['temperature']) ? (float) $options['temperature'] : null,
            maxTokens: isset($options['max_tokens']) ? (int) $options['max_tokens'] : null,
            provider: $options['provider'] ?? null,
            model: $options['model'] ?? null,
        );
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
    }
}
