<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Option;

/**
 * Options for tool/function calling requests.
 *
 * Extends ChatOptions with tool-specific configuration.
 *
 * @phpstan-consistent-constructor
 */
class ToolOptions extends ChatOptions
{
    private const TOOL_CHOICES = ['auto', 'none', 'required'];

    /**
     * @param array<int, string>|null $stopSequences
     */
    public function __construct(
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?float $topP = null,
        ?float $frequencyPenalty = null,
        ?float $presencePenalty = null,
        ?string $responseFormat = null,
        ?string $systemPrompt = null,
        ?array $stopSequences = null,
        ?string $provider = null,
        ?string $model = null,
        ?int $beUserUid = null,
        ?float $plannedCost = null,
        private ?string $toolChoice = null,
        private ?bool $parallelToolCalls = null,
        private bool $captureRaw = false,
    ) {
        parent::__construct(
            $temperature,
            $maxTokens,
            $topP,
            $frequencyPenalty,
            $presencePenalty,
            $responseFormat,
            $systemPrompt,
            $stopSequences,
            $provider,
            $model,
            $beUserUid,
            $plannedCost,
        );
        $this->validateToolOptions();
    }

    // ========================================
    // Factory Presets
    // ========================================

    /**
     * Create options that let the model decide when to use tools.
     */
    public static function auto(): static
    {
        return new static(
            toolChoice: 'auto',
            temperature: 0.7,
        );
    }

    /**
     * Create options that require the model to use a tool.
     */
    public static function required(): static
    {
        return new static(
            toolChoice: 'required',
            temperature: 0.3,
        );
    }

    /**
     * Create options that prevent tool usage.
     */
    public static function noTools(): static
    {
        return new static(
            toolChoice: 'none',
            temperature: 0.7,
        );
    }

    /**
     * Create options for parallel tool calls (multiple tools at once).
     */
    public static function parallel(): static
    {
        return new static(
            toolChoice: 'auto',
            parallelToolCalls: true,
            temperature: 0.7,
        );
    }

    // ========================================
    // Fluent Setters
    // ========================================

    public function withToolChoice(string $toolChoice): static
    {
        $clone = clone $this;
        $clone->toolChoice = $toolChoice;
        $clone->validateToolOptions();
        return $clone;
    }

    public function withParallelToolCalls(bool $parallelToolCalls): static
    {
        $clone = clone $this;
        $clone->parallelToolCalls = $parallelToolCalls;
        return $clone;
    }

    // ========================================
    // Getters
    // ========================================

    public function getToolChoice(): ?string
    {
        return $this->toolChoice;
    }

    public function getParallelToolCalls(): ?bool
    {
        return $this->parallelToolCalls;
    }

    /**
     * Whether the adapter should retain the decoded raw provider response in
     * the completion metadata (admin playground inspector only — off in
     * production so raw payloads are never kept).
     */
    public function getCaptureRaw(): bool
    {
        return $this->captureRaw;
    }

    public function withCaptureRaw(bool $captureRaw): static
    {
        $clone = clone $this;
        $clone->captureRaw = $captureRaw;
        return $clone;
    }

    // ========================================
    // Array Conversion
    // ========================================

    public function toArray(): array
    {
        $extra = $this->filterNull([
            'tool_choice' => $this->toolChoice,
            'parallel_tool_calls' => $this->parallelToolCalls,
        ]);

        // Private, out-of-band directive read by the adapters (never a provider
        // API field): retain the decoded raw response. Emitted only when set so
        // production payloads stay unchanged.
        if ($this->captureRaw) {
            $extra['_capture_raw'] = true;
        }

        return array_merge(parent::toArray(), $extra);
    }

    // ========================================
    // Validation
    // ========================================

    private function validateToolOptions(): void
    {
        if ($this->toolChoice !== null) {
            self::validateEnum($this->toolChoice, self::TOOL_CHOICES, 'tool_choice');
        }
    }
}
