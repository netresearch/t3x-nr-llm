<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Option;

/**
 * Options for vision/image analysis requests.
 *
 * @phpstan-consistent-constructor
 */
class VisionOptions extends AbstractOptions
{
    private const array DETAIL_LEVELS = ['auto', 'low', 'high'];

    public function __construct(
        private ?string $detailLevel = null,
        private ?int $maxTokens = null,
        private ?float $temperature = null,
        private ?string $provider = null,
        private ?string $model = null,
    ) {
        $this->validate();
    }

    // ========================================
    // Factory Presets
    // ========================================

    /**
     * Create options for alt text generation (short, concise).
     */
    public static function altText(): static
    {
        return new static(
            detailLevel: 'low',
            maxTokens: 100,
            temperature: 0.5,
        );
    }

    /**
     * Create options for detailed image description.
     */
    public static function detailed(): static
    {
        return new static(
            detailLevel: 'high',
            maxTokens: 500,
            temperature: 0.7,
        );
    }

    /**
     * Create options for quick analysis (cost-optimized).
     */
    public static function quick(): static
    {
        return new static(
            detailLevel: 'low',
            maxTokens: 200,
            temperature: 0.5,
        );
    }

    /**
     * Create options for comprehensive analysis.
     */
    public static function comprehensive(): static
    {
        return new static(
            detailLevel: 'high',
            maxTokens: 1000,
            temperature: 0.7,
        );
    }

    // ========================================
    // Fluent Setters
    // ========================================

    public function withDetailLevel(string $detailLevel): static
    {
        $clone = clone $this;
        $clone->detailLevel = $detailLevel;
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

    public function withTemperature(float $temperature): static
    {
        $clone = clone $this;
        $clone->temperature = $temperature;
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

    public function getDetailLevel(): ?string
    {
        return $this->detailLevel;
    }

    public function getMaxTokens(): ?int
    {
        return $this->maxTokens;
    }

    public function getTemperature(): ?float
    {
        return $this->temperature;
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
            'detail_level' => $this->detailLevel,
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
            'provider' => $this->provider,
            'model' => $this->model,
        ]);
    }

    // ========================================
    // Validation
    // ========================================

    private function validate(): void
    {
        if ($this->detailLevel !== null) {
            self::validateEnum($this->detailLevel, self::DETAIL_LEVELS, 'detail_level');
        }

        if ($this->maxTokens !== null) {
            self::validatePositiveInt($this->maxTokens, 'max_tokens');
        }

        if ($this->temperature !== null) {
            self::validateRange($this->temperature, 0.0, 2.0, 'temperature');
        }
    }
}
