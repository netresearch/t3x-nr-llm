<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Option;

/**
 * Options for tool/function calling requests
 *
 * Extends ChatOptions with tool-specific configuration.
 */
class ToolOptions extends ChatOptions
{
    private const TOOL_CHOICES = ['auto', 'none', 'required'];

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
        private ?string $toolChoice = null,
        private ?bool $parallelToolCalls = null,
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
        );
        $this->validateToolOptions();
    }

    // ========================================
    // Factory Presets
    // ========================================

    /**
     * Create options that let the model decide when to use tools
     */
    public static function auto(): static
    {
        return new static(
            toolChoice: 'auto',
            temperature: 0.7,
        );
    }

    /**
     * Create options that require the model to use a tool
     */
    public static function required(): static
    {
        return new static(
            toolChoice: 'required',
            temperature: 0.3,
        );
    }

    /**
     * Create options that prevent tool usage
     */
    public static function noTools(): static
    {
        return new static(
            toolChoice: 'none',
            temperature: 0.7,
        );
    }

    /**
     * Create options for parallel tool calls (multiple tools at once)
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

    // ========================================
    // Array Conversion
    // ========================================

    public function toArray(): array
    {
        return array_merge(
            parent::toArray(),
            $this->filterNull([
                'tool_choice' => $this->toolChoice,
                'parallel_tool_calls' => $this->parallelToolCalls,
            ])
        );
    }

    public static function fromArray(array $options): static
    {
        return new static(
            temperature: isset($options['temperature']) ? (float) $options['temperature'] : null,
            maxTokens: isset($options['max_tokens']) ? (int) $options['max_tokens'] : null,
            topP: isset($options['top_p']) ? (float) $options['top_p'] : null,
            frequencyPenalty: isset($options['frequency_penalty']) ? (float) $options['frequency_penalty'] : null,
            presencePenalty: isset($options['presence_penalty']) ? (float) $options['presence_penalty'] : null,
            responseFormat: $options['response_format'] ?? null,
            systemPrompt: $options['system_prompt'] ?? null,
            stopSequences: $options['stop_sequences'] ?? null,
            provider: $options['provider'] ?? null,
            model: $options['model'] ?? null,
            toolChoice: $options['tool_choice'] ?? null,
            parallelToolCalls: isset($options['parallel_tool_calls']) ? (bool) $options['parallel_tool_calls'] : null,
        );
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
