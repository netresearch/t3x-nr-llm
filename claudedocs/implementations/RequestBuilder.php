<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Request;

use Netresearch\NrLlm\Exception\ValidationException;

/**
 * Fluent request builder with validation.
 *
 * Provides a fluent interface for building LLM requests with
 * automatic validation and parameter normalization.
 */
class RequestBuilder
{
    private ?string $prompt = null;
    private ?string $systemPrompt = null;
    private ?string $model = null;
    private float $temperature = 0.7;
    private ?int $maxTokens = null;
    private array $stopSequences = [];
    private string $responseFormat = 'text';
    private array $images = [];
    private array $customParams = [];

    /**
     * Set the user prompt.
     */
    public function prompt(string $prompt): self
    {
        $this->prompt = $prompt;
        return $this;
    }

    /**
     * Set the system prompt (context).
     */
    public function systemPrompt(string $systemPrompt): self
    {
        $this->systemPrompt = $systemPrompt;
        return $this;
    }

    /**
     * Set the model name.
     */
    public function model(string $model): self
    {
        $this->model = $model;
        return $this;
    }

    /**
     * Set temperature (0.0-2.0).
     */
    public function temperature(float $temp): self
    {
        $this->temperature = $temp;
        return $this;
    }

    /**
     * Set maximum tokens to generate.
     */
    public function maxTokens(int $tokens): self
    {
        $this->maxTokens = $tokens;
        return $this;
    }

    /**
     * Set stop sequences.
     */
    public function stopSequences(array $sequences): self
    {
        $this->stopSequences = $sequences;
        return $this;
    }

    /**
     * Set response format ('text', 'json', 'markdown').
     */
    public function responseFormat(string $format): self
    {
        $this->responseFormat = $format;
        return $this;
    }

    /**
     * Set images for vision requests.
     */
    public function images(array $images): self
    {
        $this->images = $images;
        return $this;
    }

    /**
     * Set custom provider-specific parameter.
     */
    public function setCustomParam(string $key, mixed $value): self
    {
        $this->customParams[$key] = $value;
        return $this;
    }

    /**
     * Build from array of options.
     */
    public function fromArray(array $options): self
    {
        foreach ($options as $key => $value) {
            match ($key) {
                'prompt' => $this->prompt($value),
                'system_prompt' => $this->systemPrompt($value),
                'model' => $this->model($value),
                'temperature' => $this->temperature((float)$value),
                'max_tokens' => $this->maxTokens((int)$value),
                'stop_sequences' => $this->stopSequences($value),
                'response_format' => $this->responseFormat($value),
                'images' => $this->images($value),
                default => $this->setCustomParam($key, $value)
            };
        }
        return $this;
    }

    /**
     * Build the request array.
     *
     * @throws ValidationException If validation fails
     *
     * @return array Validated request parameters
     */
    public function build(): array
    {
        $this->validate();

        $request = array_filter([
            'prompt' => $this->prompt,
            'system_prompt' => $this->systemPrompt,
            'model' => $this->model,
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
            'stop_sequences' => !empty($this->stopSequences) ? $this->stopSequences : null,
            'response_format' => $this->responseFormat,
            'images' => !empty($this->images) ? $this->images : null,
        ], fn($value) => $value !== null);

        // Merge custom params
        return array_merge($request, $this->customParams);
    }

    /**
     * Validate request parameters.
     *
     * @throws ValidationException If validation fails
     *
     * @return bool Always true (throws on failure)
     */
    public function validate(): bool
    {
        // Validate prompt
        if ($this->prompt === null || trim($this->prompt) === '') {
            throw new ValidationException('Prompt cannot be empty');
        }

        if (strlen($this->prompt) > 100000) {
            throw new ValidationException('Prompt exceeds maximum length of 100,000 characters');
        }

        // Validate temperature
        if ($this->temperature < 0 || $this->temperature > 2) {
            throw new ValidationException(
                'Temperature must be between 0.0 and 2.0',
                suggestion: 'Use 0.0 for deterministic, 0.7 for balanced, 1.0+ for creative',
            );
        }

        // Validate max tokens
        if ($this->maxTokens !== null && $this->maxTokens < 1) {
            throw new ValidationException('Max tokens must be positive');
        }

        if ($this->maxTokens !== null && $this->maxTokens > 128000) {
            throw new ValidationException(
                'Max tokens exceeds maximum of 128,000',
                suggestion: 'Most models support 4,000-32,000 tokens',
            );
        }

        // Validate response format
        if (!in_array($this->responseFormat, ['text', 'json', 'markdown'])) {
            throw new ValidationException(
                "Invalid response format: {$this->responseFormat}",
                suggestion: "Must be 'text', 'json', or 'markdown'",
            );
        }

        // Validate images
        foreach ($this->images as $image) {
            if (!is_string($image)) {
                throw new ValidationException('Image must be a URL string');
            }

            if (!$this->isValidImageUrl($image)) {
                throw new ValidationException(
                    "Invalid image URL: {$image}",
                    suggestion: 'Must be a valid HTTP(S) URL or data URI',
                );
            }
        }

        return true;
    }

    /**
     * Reset builder to initial state.
     */
    public function reset(): self
    {
        $this->prompt = null;
        $this->systemPrompt = null;
        $this->model = null;
        $this->temperature = 0.7;
        $this->maxTokens = null;
        $this->stopSequences = [];
        $this->responseFormat = 'text';
        $this->images = [];
        $this->customParams = [];

        return $this;
    }

    /**
     * Validate image URL or data URI.
     */
    private function isValidImageUrl(string $url): bool
    {
        // Check for data URI
        if (str_starts_with($url, 'data:image/')) {
            return true;
        }

        // Check for valid URL
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        // Must be HTTP(S)
        $scheme = parse_url($url, PHP_URL_SCHEME);
        return in_array($scheme, ['http', 'https']);
    }
}
