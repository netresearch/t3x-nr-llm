<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Option;

/**
 * Options for chat completion requests.
 *
 * Provides typed, validated options with fluent setters and factory presets
 * for common use cases like factual, creative, and code generation.
 *
 * @phpstan-consistent-constructor
 */
class ChatOptions extends AbstractOptions
{
    private const array RESPONSE_FORMATS = ['text', 'json', 'markdown'];

    public function __construct(
        private ?float $temperature = null,
        private ?int $maxTokens = null,
        private ?float $topP = null,
        private ?float $frequencyPenalty = null,
        private ?float $presencePenalty = null,
        private ?string $responseFormat = null,
        private ?string $systemPrompt = null,
        /** @var array<int, string>|null */
        private ?array $stopSequences = null,
        private ?string $provider = null,
        private ?string $model = null,
    ) {
        $this->validate();
    }

    // ========================================
    // Factory Presets
    // ========================================

    /**
     * Create options optimized for factual, consistent output.
     *
     * Low temperature (0.2) and top_p (0.9) for deterministic responses.
     */
    public static function factual(): static
    {
        return new static(
            temperature: 0.2,
            topP: 0.9,
        );
    }

    /**
     * Create options optimized for creative, diverse output.
     *
     * High temperature (1.2) and presence penalty (0.6) for originality.
     */
    public static function creative(): static
    {
        return new static(
            temperature: 1.2,
            topP: 1.0,
            presencePenalty: 0.6,
        );
    }

    /**
     * Create balanced options for general use.
     *
     * Default temperature (0.7) for balanced creativity and consistency.
     */
    public static function balanced(): static
    {
        return new static(
            temperature: 0.7,
            maxTokens: 4096,
        );
    }

    /**
     * Create options for JSON output.
     */
    public static function json(): static
    {
        return new static(
            temperature: 0.3,
            responseFormat: 'json',
        );
    }

    /**
     * Create options optimized for code generation.
     *
     * Low temperature (0.2) for precision, no frequency penalty to allow
     * repetitive code patterns.
     */
    public static function code(): static
    {
        return new static(
            temperature: 0.2,
            maxTokens: 8192,
            topP: 0.95,
            frequencyPenalty: 0.0,
        );
    }

    // ========================================
    // Fluent Setters
    // ========================================

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

    public function withTopP(float $topP): static
    {
        $clone = clone $this;
        $clone->topP = $topP;
        $clone->validate();
        return $clone;
    }

    public function withFrequencyPenalty(float $frequencyPenalty): static
    {
        $clone = clone $this;
        $clone->frequencyPenalty = $frequencyPenalty;
        $clone->validate();
        return $clone;
    }

    public function withPresencePenalty(float $presencePenalty): static
    {
        $clone = clone $this;
        $clone->presencePenalty = $presencePenalty;
        $clone->validate();
        return $clone;
    }

    public function withResponseFormat(string $responseFormat): static
    {
        $clone = clone $this;
        $clone->responseFormat = $responseFormat;
        $clone->validate();
        return $clone;
    }

    public function withSystemPrompt(string $systemPrompt): static
    {
        $clone = clone $this;
        $clone->systemPrompt = $systemPrompt;
        return $clone;
    }

    /**
     * @param array<int, string> $stopSequences
     */
    public function withStopSequences(array $stopSequences): static
    {
        $clone = clone $this;
        $clone->stopSequences = $stopSequences;
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

    public function getTemperature(): ?float
    {
        return $this->temperature;
    }

    public function getMaxTokens(): ?int
    {
        return $this->maxTokens;
    }

    public function getTopP(): ?float
    {
        return $this->topP;
    }

    public function getFrequencyPenalty(): ?float
    {
        return $this->frequencyPenalty;
    }

    public function getPresencePenalty(): ?float
    {
        return $this->presencePenalty;
    }

    public function getResponseFormat(): ?string
    {
        return $this->responseFormat;
    }

    public function getSystemPrompt(): ?string
    {
        return $this->systemPrompt;
    }

    /**
     * @return array<int, string>|null
     */
    public function getStopSequences(): ?array
    {
        return $this->stopSequences;
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
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
            'top_p' => $this->topP,
            'frequency_penalty' => $this->frequencyPenalty,
            'presence_penalty' => $this->presencePenalty,
            'response_format' => $this->responseFormat,
            'system_prompt' => $this->systemPrompt,
            'stop_sequences' => $this->stopSequences,
            'provider' => $this->provider,
            'model' => $this->model,
        ]);
    }

    /**
     * Merge current options with overrides.
     *
     * Returns an array with current options merged with the provided overrides.
     * Overrides take precedence over current values.
     *
     * @param array<string, mixed> $overrides Values to override
     *
     * @return array<string, mixed> Merged options array
     */
    public function merge(array $overrides): array
    {
        return array_merge($this->toArray(), $overrides);
    }

    // ========================================
    // Validation
    // ========================================

    private function validate(): void
    {
        if ($this->temperature !== null) {
            self::validateRange($this->temperature, 0.0, 2.0, 'temperature');
        }

        if ($this->maxTokens !== null) {
            self::validatePositiveInt($this->maxTokens, 'max_tokens');
        }

        if ($this->topP !== null) {
            self::validateRange($this->topP, 0.0, 1.0, 'top_p');
        }

        if ($this->frequencyPenalty !== null) {
            self::validateRange($this->frequencyPenalty, -2.0, 2.0, 'frequency_penalty');
        }

        if ($this->presencePenalty !== null) {
            self::validateRange($this->presencePenalty, -2.0, 2.0, 'presence_penalty');
        }

        if ($this->responseFormat !== null) {
            self::validateEnum($this->responseFormat, self::RESPONSE_FORMATS, 'response_format');
        }
    }
}
