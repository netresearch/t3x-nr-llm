<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Option;

/**
 * Options for embedding requests
 *
 * Embeddings are deterministic, so caching is enabled by default.
 */
class EmbeddingOptions extends AbstractOptions
{
    private const DEFAULT_CACHE_TTL = 86400; // 24 hours

    public function __construct(
        private ?string $model = null,
        private ?int $dimensions = null,
        private ?int $cacheTtl = self::DEFAULT_CACHE_TTL,
        private ?string $provider = null,
    ) {
        $this->validate();
    }

    // ========================================
    // Factory Presets
    // ========================================

    /**
     * Create options for standard embeddings with caching
     */
    public static function standard(): static
    {
        return new static(
            cacheTtl: self::DEFAULT_CACHE_TTL,
        );
    }

    /**
     * Create options with no caching (for ephemeral content)
     */
    public static function noCache(): static
    {
        return new static(
            cacheTtl: 0,
        );
    }

    /**
     * Create options with compact dimensions (for storage efficiency)
     */
    public static function compact(): static
    {
        return new static(
            dimensions: 256,
            cacheTtl: self::DEFAULT_CACHE_TTL,
        );
    }

    /**
     * Create options with high dimensions (for maximum precision)
     */
    public static function highPrecision(): static
    {
        return new static(
            dimensions: 1536,
            cacheTtl: self::DEFAULT_CACHE_TTL,
        );
    }

    // ========================================
    // Fluent Setters
    // ========================================

    public function withModel(string $model): static
    {
        $clone = clone $this;
        $clone->model = $model;
        return $clone;
    }

    public function withDimensions(int $dimensions): static
    {
        $clone = clone $this;
        $clone->dimensions = $dimensions;
        $clone->validate();
        return $clone;
    }

    public function withCacheTtl(int $cacheTtl): static
    {
        $clone = clone $this;
        $clone->cacheTtl = $cacheTtl;
        $clone->validate();
        return $clone;
    }

    public function withProvider(string $provider): static
    {
        $clone = clone $this;
        $clone->provider = $provider;
        return $clone;
    }

    // ========================================
    // Getters
    // ========================================

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function getDimensions(): ?int
    {
        return $this->dimensions;
    }

    public function getCacheTtl(): ?int
    {
        return $this->cacheTtl;
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    // ========================================
    // Array Conversion
    // ========================================

    public function toArray(): array
    {
        return $this->filterNull([
            'model' => $this->model,
            'dimensions' => $this->dimensions,
            'cache_ttl' => $this->cacheTtl,
            'provider' => $this->provider,
        ]);
    }

    public static function fromArray(array $options): static
    {
        return new static(
            model: $options['model'] ?? null,
            dimensions: isset($options['dimensions']) ? (int) $options['dimensions'] : null,
            cacheTtl: isset($options['cache_ttl']) ? (int) $options['cache_ttl'] : self::DEFAULT_CACHE_TTL,
            provider: $options['provider'] ?? null,
        );
    }

    // ========================================
    // Validation
    // ========================================

    private function validate(): void
    {
        if ($this->dimensions !== null) {
            self::validatePositiveInt($this->dimensions, 'dimensions');
        }

        if ($this->cacheTtl !== null && $this->cacheTtl < 0) {
            self::validateRange($this->cacheTtl, 0, PHP_INT_MAX, 'cache_ttl');
        }
    }
}
