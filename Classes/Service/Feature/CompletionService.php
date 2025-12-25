<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Feature;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;

/**
 * High-level service for text completion.
 *
 * Provides simple text generation with configurable creativity,
 * format control, and token management.
 */
class CompletionService
{
    public function __construct(
        private readonly LlmServiceManagerInterface $llmManager,
    ) {}

    /**
     * Generate text completion.
     *
     * @param string $prompt The user prompt
     *
     * @throws InvalidArgumentException
     */
    public function complete(string $prompt, ?ChatOptions $options = null): CompletionResponse
    {
        $options ??= new ChatOptions();
        $optionsArray = $options->toArray();
        $this->validateOptions($optionsArray);

        $messages = [];

        // Add system prompt if provided
        if (!empty($optionsArray['system_prompt'])) {
            $messages[] = [
                'role' => 'system',
                'content' => $optionsArray['system_prompt'],
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $prompt,
        ];

        // Handle response format
        if (isset($optionsArray['response_format'])) {
            $normalizedFormat = $this->normalizeResponseFormat(
                $optionsArray['response_format'],
            );
            $options = $options->withResponseFormat(
                is_string($normalizedFormat) ? $normalizedFormat : 'json',
            );
        }

        // Map stop_sequences to stop
        if ($options->getStopSequences() !== null) {
            $optionsArray = $options->toArray();
            $optionsArray['stop'] = $optionsArray['stop_sequences'];
            unset($optionsArray['stop_sequences']);

            // Create temporary options with modified array
            $tempOptions = new ChatOptions(
                temperature: $options->getTemperature(),
                maxTokens: $options->getMaxTokens(),
                topP: $options->getTopP(),
                frequencyPenalty: $options->getFrequencyPenalty(),
                presencePenalty: $options->getPresencePenalty(),
                responseFormat: $options->getResponseFormat(),
                provider: $options->getProvider(),
                model: $options->getModel(),
            );
            return $this->llmManager->chat($messages, $tempOptions);
        }

        return $this->llmManager->chat($messages, $options);
    }

    /**
     * Generate JSON-formatted completion.
     *
     * @param string $prompt The user prompt
     *
     * @throws InvalidArgumentException
     *
     * @return array<string, mixed> Parsed JSON response
     */
    public function completeJson(string $prompt, ?ChatOptions $options = null): array
    {
        $options ??= new ChatOptions();
        $options = $options->withResponseFormat('json');

        $response = $this->complete($prompt, $options);

        $decoded = json_decode($response->content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException(
                'Failed to decode JSON response: ' . json_last_error_msg(),
            );
        }

        return $decoded;
    }

    /**
     * Generate markdown-formatted completion.
     *
     * @param string $prompt The user prompt
     *
     * @return string Markdown-formatted text
     */
    public function completeMarkdown(string $prompt, ?ChatOptions $options = null): string
    {
        $options ??= new ChatOptions();
        $options = $options->withResponseFormat('markdown');

        $systemPrompt = $options->getSystemPrompt() ?? '';
        $systemPrompt .= "\n\nFormat your response in clean, well-structured Markdown.";
        $options = $options->withSystemPrompt(trim($systemPrompt));

        $response = $this->complete($prompt, $options);

        return $response->content;
    }

    /**
     * Generate completion with low creativity (factual, consistent).
     *
     * @param string $prompt The user prompt
     */
    public function completeFactual(string $prompt, ?ChatOptions $options = null): CompletionResponse
    {
        $options ??= new ChatOptions();

        if ($options->getTemperature() === null) {
            $options = $options->withTemperature(0.2);
        }
        if ($options->getTopP() === null) {
            $options = $options->withTopP(0.9);
        }

        return $this->complete($prompt, $options);
    }

    /**
     * Generate completion with high creativity (diverse, creative).
     *
     * @param string $prompt The user prompt
     */
    public function completeCreative(string $prompt, ?ChatOptions $options = null): CompletionResponse
    {
        $options ??= new ChatOptions();

        if ($options->getTemperature() === null) {
            $options = $options->withTemperature(1.2);
        }
        if ($options->getTopP() === null) {
            $options = $options->withTopP(1.0);
        }
        if ($options->getPresencePenalty() === null) {
            $options = $options->withPresencePenalty(0.6);
        }

        return $this->complete($prompt, $options);
    }

    /**
     * Validate completion options.
     *
     * @param array<string, mixed> $options
     *
     * @throws InvalidArgumentException
     */
    private function validateOptions(array $options): void
    {
        if (isset($options['temperature'])) {
            $temp = $options['temperature'];
            if (!is_numeric($temp) || $temp < 0 || $temp > 2) {
                throw new InvalidArgumentException(
                    'Temperature must be between 0.0 and 2.0',
                );
            }
        }

        if (isset($options['max_tokens'])) {
            $maxTokens = $options['max_tokens'];
            if (!is_int($maxTokens) || $maxTokens < 1) {
                throw new InvalidArgumentException(
                    'max_tokens must be a positive integer',
                );
            }
        }

        if (isset($options['top_p'])) {
            $topP = $options['top_p'];
            if (!is_numeric($topP) || $topP < 0 || $topP > 1) {
                throw new InvalidArgumentException(
                    'top_p must be between 0.0 and 1.0',
                );
            }
        }

        if (isset($options['response_format'])) {
            $format = $options['response_format'];
            if (!in_array($format, ['text', 'json', 'markdown'], true)) {
                throw new InvalidArgumentException(
                    'response_format must be "text", "json", or "markdown"',
                );
            }
        }
    }

    /**
     * Normalize response format for provider compatibility.
     *
     * @return array<string, string>|string
     */
    private function normalizeResponseFormat(string $format): array|string
    {
        return match ($format) {
            'json' => ['type' => 'json_object'],
            'markdown', 'text' => 'text',
            default => 'text',
        };
    }
}
