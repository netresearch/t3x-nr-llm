<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Option;

use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * Options for embedding requests.
 *
 * Embeddings are deterministic, so caching is enabled by default.
 *
 * @phpstan-consistent-constructor
 */
class EmbeddingOptions extends AbstractOptions
{
    private const DEFAULT_CACHE_TTL = 86400; // 24 hours

    public function __construct(
        private ?string $model = null,
        private ?int $dimensions = null,
        private ?int $cacheTtl = self::DEFAULT_CACHE_TTL,
        private ?string $provider = null,
        private ?int $beUserUid = null,
        private ?float $plannedCost = null,
    ) {
        $this->validate();
    }

    // ========================================
    // Factory Presets
    // ========================================

    /**
     * Create options for standard embeddings with caching.
     */
    public static function standard(): static
    {
        return new static(
            cacheTtl: self::DEFAULT_CACHE_TTL,
        );
    }

    /**
     * Create options with no caching (for ephemeral content).
     */
    public static function noCache(): static
    {
        return new static(
            cacheTtl: 0,
        );
    }

    /**
     * Create options with compact dimensions (for storage efficiency).
     */
    public static function compact(): static
    {
        return new static(
            dimensions: 256,
            cacheTtl: self::DEFAULT_CACHE_TTL,
        );
    }

    /**
     * Create options with high dimensions (for maximum precision).
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

    /**
     * Set the backend user uid for budget pre-flight (REC #4).
     *
     * See `ChatOptions::withBeUserUid()` for the full contract — the
     * BudgetMiddleware reads the same metadata key regardless of
     * which option type carried it.
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
            'model' => $this->model,
            'dimensions' => $this->dimensions,
            'cache_ttl' => $this->cacheTtl,
            'provider' => $this->provider,
        ]);
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

        if ($this->beUserUid !== null && $this->beUserUid < 0) {
            // Mirror ChatOptions: 0 = anonymous / skip, positive = real
            // BE user, negative = caller bug. See REC #4 / slice 15a.
            throw new InvalidArgumentException(
                sprintf('be_user_uid must be >= 0, got %d', $this->beUserUid),
                7461293502,
            );
        }

        if ($this->plannedCost !== null && $this->plannedCost < 0.0) {
            throw new InvalidArgumentException(
                sprintf('planned_cost must be >= 0.0, got %s', $this->plannedCost),
                4658297015,
            );
        }
    }
}
