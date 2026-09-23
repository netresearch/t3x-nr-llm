<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Option;

use Netresearch\NrLlm\Domain\Enum\ReasoningEffort;

/**
 * Options for chat completion requests.
 *
 * Provides typed, validated options with fluent setters and factory presets
 * for common use cases like factual, creative, and code generation.
 *
 * @phpstan-consistent-constructor
 *
 * @api
 */
class ChatOptions extends AbstractOptions implements BudgetAwareOptionsInterface
{
    use BudgetFieldsTrait;

    private const RESPONSE_FORMATS = ['text', 'json', 'markdown'];

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
        ?int $beUserUid = null,
        ?float $plannedCost = null,
        private ?bool $think = null,
    ) {
        $this->setBudgetFields($beUserUid, $plannedCost);
        $this->validate();
    }

    // Set via withSuppressRequestCount() rather than the constructor: ChatOptions
    // is @phpstan-consistent-constructor and ToolOptions extends it, so a new
    // constructor parameter would collide with the subclass's own parameters.
    private ?bool $suppressRequestCount = null;

    // Same constructor constraint as suppressRequestCount.
    /** @var array<string, mixed>|null */
    private ?array $responseSchema = null;

    // Same constructor constraint again, and one more reason (ADR-204):
    // ToolOptions repeats this class's thirteen constructor parameters
    // positionally before adding its own three, so a fourteenth here would
    // move `toolChoice` along by one for every positional caller.
    private ?ReasoningEffort $reasoningEffort = null;

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

    /**
     * Attach a JSON schema the provider should enforce natively where it can
     * (ADR-128). The schema must lie inside the strict subset (ADR-126) —
     * CompletionService pre-flights that before any provider call; adapters
     * treat the value as opaque and emit their provider's dialect from it.
     * Native enforcement narrows what the model can emit; the local strict
     * validation on the response remains authoritative either way.
     *
     * @param array<string, mixed> $responseSchema
     */
    public function withResponseSchema(array $responseSchema): static
    {
        $clone = clone $this;
        $clone->responseSchema = $responseSchema;
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

    /**
     * Ask a reasoning model for a particular amount of thinking (ADR-204).
     *
     * This is the precise form of {@see self::$think}, and it wins over it.
     * Which values a model accepts is the model's business: an effort it does
     * not allow is moved onto its own scale before the request goes out, and
     * the value that was applied is readable on the response under
     * {@see \Netresearch\NrLlm\Provider\OpenAi\OpenAiCallMetadata::KEY_REASONING_EFFORT}.
     *
     * A provider without an effort scale ignores it and writes no such key.
     */
    public function withReasoningEffort(ReasoningEffort $reasoningEffort): static
    {
        $clone                  = clone $this;
        $clone->reasoningEffort = $reasoningEffort;
        return $clone;
    }

    /**
     * Mark the call as a sub-call of a larger operation: it still records its
     * tokens/cost but is not counted as a separate request.
     * See {@see self::getSuppressRequestCount()}.
     */
    public function withSuppressRequestCount(bool $suppressRequestCount): static
    {
        $clone = clone $this;
        $clone->suppressRequestCount = $suppressRequestCount;
        return $clone;
    }

    // Budget pre-flight setters (`withBeUserUid()`, `withPlannedCost()`)
    // are provided by `BudgetFieldsTrait`. See REC #4 for the full
    // contract — `0` is "anonymous / skip the check"; positive uid =
    // real BE user; negative is rejected at validation time.

    // ========================================
    // Getters
    // ========================================

    public function getTemperature(): ?float
    {
        return $this->temperature;
    }

    /**
     * Reasoning toggle for hybrid-thinking models: true forces thinking
     * on, false off, null leaves the provider/model default untouched.
     *
     * On a provider with a graded effort scale, `false` now selects the lowest
     * effort that model allows rather than being ignored (ADR-204).
     * {@see self::getReasoningEffort()} is the precise form and wins over it.
     */
    public function getThink(): ?bool
    {
        return $this->think;
    }

    /**
     * The reasoning effort asked for, or null to leave the model's own default
     * in place. See {@see self::withReasoningEffort()}.
     */
    public function getReasoningEffort(): ?ReasoningEffort
    {
        return $this->reasoningEffort;
    }

    /**
     * Whether the underlying provider call should be recorded WITHOUT
     * incrementing the request counter. Set on the sub-calls of a larger
     * operation (a translation's language detection, the LLM translator's
     * chat call) so that operation is counted as a single request rather
     * than once per internal provider call.
     *
     * Metadata-only: deliberately excluded from toArray() so it never reaches
     * the provider payload — LlmServiceManager reads it and forwards it as
     * pipeline metadata, exactly like beUserUid / plannedCost.
     */
    public function getSuppressRequestCount(): bool
    {
        return $this->suppressRequestCount === true;
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

    /**
     * @return array<string, mixed>|null
     */
    public function getResponseSchema(): ?array
    {
        return $this->responseSchema;
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

    // Budget pre-flight getters (`getBeUserUid()`, `getPlannedCost()`)
    // are provided by `BudgetFieldsTrait`.

    // ========================================
    // Array Conversion
    // ========================================

    public function toArray(): array
    {
        $options = $this->filterNull([
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
            'top_p' => $this->topP,
            'frequency_penalty' => $this->frequencyPenalty,
            'presence_penalty' => $this->presencePenalty,
            'response_format' => $this->responseFormat,
            'response_schema' => $this->responseSchema,
            'system_prompt' => $this->systemPrompt,
            'stop_sequences' => $this->stopSequences,
            'provider' => $this->provider,
            'model' => $this->model,
        ]);

        // Tri-state: `false` (thinking explicitly OFF) must survive, so this
        // bypasses filterNull; unset = provider/model default.
        if ($this->think !== null) {
            $options['think'] = $this->think;
        }

        // The backed value, not the enum: this array is persisted in a resume
        // snapshot and merged with a configuration's options JSON, and both of
        // those are plain data (ADR-204).
        if ($this->reasoningEffort instanceof ReasoningEffort) {
            $options['reasoning_effort'] = $this->reasoningEffort->value;
        }

        return $options;
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

        $this->validateBudgetFields();
    }
}
