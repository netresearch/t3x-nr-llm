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
        ?bool $think = null,
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
            $think,
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

    /**
     * Rebuild options from a {@see self::toArray()} snapshot (ADR-084 resume).
     *
     * The budget fields are not part of toArray(): $beUserUid is supplied by the
     * caller (the acting user, so the resumed continuation is budget-checked for
     * whoever approved it) and plannedCost is not restored.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, ?int $beUserUid = null): static
    {
        $stop          = $data['stop_sequences'] ?? null;
        $stopSequences = is_array($stop) ? array_values(array_filter($stop, is_string(...))) : null;

        return new static(
            beUserUid: $beUserUid,
            temperature: is_numeric($data['temperature'] ?? null) ? (float)$data['temperature'] : null,
            maxTokens: is_numeric($data['max_tokens'] ?? null) ? (int)$data['max_tokens'] : null,
            topP: is_numeric($data['top_p'] ?? null) ? (float)$data['top_p'] : null,
            frequencyPenalty: is_numeric($data['frequency_penalty'] ?? null) ? (float)$data['frequency_penalty'] : null,
            presencePenalty: is_numeric($data['presence_penalty'] ?? null) ? (float)$data['presence_penalty'] : null,
            responseFormat: is_string($data['response_format'] ?? null) ? $data['response_format'] : null,
            systemPrompt: is_string($data['system_prompt'] ?? null) ? $data['system_prompt'] : null,
            stopSequences: $stopSequences,
            provider: is_string($data['provider'] ?? null) ? $data['provider'] : null,
            model: is_string($data['model'] ?? null) ? $data['model'] : null,
            think: is_bool($data['think'] ?? null) ? $data['think'] : null,
            toolChoice: is_string($data['tool_choice'] ?? null) ? $data['tool_choice'] : null,
            parallelToolCalls: is_bool($data['parallel_tool_calls'] ?? null) ? $data['parallel_tool_calls'] : null,
            captureRaw: ($data['_capture_raw'] ?? false) === true,
        );
    }

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
