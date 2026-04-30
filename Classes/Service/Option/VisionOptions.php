<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Option;

use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * Options for vision/image analysis requests.
 *
 * @phpstan-consistent-constructor
 */
class VisionOptions extends AbstractOptions
{
    private const DETAIL_LEVELS = ['auto', 'low', 'high'];

    public function __construct(
        private ?string $detailLevel = null,
        private ?int $maxTokens = null,
        private ?float $temperature = null,
        private ?string $provider = null,
        private ?string $model = null,
        private ?int $beUserUid = null,
        private ?float $plannedCost = null,
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

    /**
     * Set the backend user uid for budget pre-flight (REC #4).
     */
    public function withBeUserUid(int $beUserUid): static
    {
        $clone = clone $this;
        $clone->beUserUid = $beUserUid;
        $clone->validate();
        return $clone;
    }

    /**
     * Set the expected cost of the call for budget pre-flight (REC #4).
     */
    public function withPlannedCost(float $plannedCost): static
    {
        $clone = clone $this;
        $clone->plannedCost = $plannedCost;
        $clone->validate();
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

    public function getBeUserUid(): ?int
    {
        return $this->beUserUid;
    }

    public function getPlannedCost(): ?float
    {
        return $this->plannedCost;
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

        if ($this->beUserUid !== null && $this->beUserUid < 0) {
            throw new InvalidArgumentException(
                sprintf('be_user_uid must be >= 0, got %d', $this->beUserUid),
                7461293503,
            );
        }

        if ($this->plannedCost !== null && $this->plannedCost < 0.0) {
            throw new InvalidArgumentException(
                sprintf('planned_cost must be >= 0.0, got %s', $this->plannedCost),
                4658297016,
            );
        }
    }
}
