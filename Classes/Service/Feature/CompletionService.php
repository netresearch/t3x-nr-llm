<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Feature;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * High-level service for text completion
 *
 * Provides simple text generation with configurable creativity,
 * format control, and token management.
 */
class CompletionService
{
    public function __construct(
        private readonly LlmServiceManager $llmManager,
    ) {}

    /**
     * Generate text completion
     *
     * @param string $prompt The user prompt
     * @param array $options Configuration options:
     *   - temperature: float (0.0-2.0) Creativity level, default 0.7
     *   - max_tokens: int Maximum output tokens, default 1000
     *   - top_p: float (0.0-1.0) Nucleus sampling, default 1.0
     *   - frequency_penalty: float (-2.0-2.0) Repetition penalty, default 0.0
     *   - presence_penalty: float (-2.0-2.0) Topic diversity, default 0.0
     *   - response_format: string ('text'|'json'|'markdown') Output format
     *   - system_prompt: string Optional system context
     *   - stop_sequences: array Stop generation on these strings
     * @return CompletionResponse
     * @throws InvalidArgumentException
     */
    public function complete(string $prompt, array $options = []): CompletionResponse
    {
        $this->validateOptions($options);

        $requestOptions = [
            'temperature' => $options['temperature'] ?? 0.7,
            'max_tokens' => $options['max_tokens'] ?? 1000,
            'top_p' => $options['top_p'] ?? 1.0,
            'frequency_penalty' => $options['frequency_penalty'] ?? 0.0,
            'presence_penalty' => $options['presence_penalty'] ?? 0.0,
            'stop' => $options['stop_sequences'] ?? [],
        ];

        // Handle response format
        if (isset($options['response_format'])) {
            $requestOptions['response_format'] = $this->normalizeResponseFormat(
                $options['response_format']
            );
        }

        // Add system prompt if provided
        $messages = [];
        if (!empty($options['system_prompt'])) {
            $messages[] = [
                'role' => 'system',
                'content' => $options['system_prompt'],
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $prompt,
        ];

        $requestOptions['messages'] = $messages;

        // Execute request through LLM manager
        $response = $this->llmManager->complete($requestOptions);

        return new CompletionResponse(
            text: $response->getContent(),
            usage: UsageStatistics::fromTokens(
                promptTokens: $response->getUsage()['prompt_tokens'] ?? 0,
                completionTokens: $response->getUsage()['completion_tokens'] ?? 0,
                estimatedCost: $response->getUsage()['estimated_cost'] ?? null
            ),
            finishReason: $response->getFinishReason(),
            model: $response->getModel(),
            metadata: $response->getMetadata()
        );
    }

    /**
     * Generate JSON-formatted completion
     *
     * @param string $prompt The user prompt
     * @param array $options Configuration options (same as complete())
     * @return array Parsed JSON response
     * @throws InvalidArgumentException
     */
    public function completeJson(string $prompt, array $options = []): array
    {
        $options['response_format'] = 'json';

        $response = $this->complete($prompt, $options);

        $decoded = json_decode($response->text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException(
                'Failed to decode JSON response: ' . json_last_error_msg()
            );
        }

        return $decoded;
    }

    /**
     * Generate markdown-formatted completion
     *
     * @param string $prompt The user prompt
     * @param array $options Configuration options (same as complete())
     * @return string Markdown-formatted text
     */
    public function completeMarkdown(string $prompt, array $options = []): string
    {
        $options['response_format'] = 'markdown';

        $systemPrompt = $options['system_prompt'] ?? '';
        $systemPrompt .= "\n\nFormat your response in clean, well-structured Markdown.";
        $options['system_prompt'] = trim($systemPrompt);

        $response = $this->complete($prompt, $options);

        return $response->text;
    }

    /**
     * Generate completion with low creativity (factual, consistent)
     *
     * @param string $prompt The user prompt
     * @param array $options Additional configuration options
     * @return CompletionResponse
     */
    public function completeFactual(string $prompt, array $options = []): CompletionResponse
    {
        $options['temperature'] = $options['temperature'] ?? 0.2;
        $options['top_p'] = $options['top_p'] ?? 0.9;

        return $this->complete($prompt, $options);
    }

    /**
     * Generate completion with high creativity (diverse, creative)
     *
     * @param string $prompt The user prompt
     * @param array $options Additional configuration options
     * @return CompletionResponse
     */
    public function completeCreative(string $prompt, array $options = []): CompletionResponse
    {
        $options['temperature'] = $options['temperature'] ?? 1.2;
        $options['top_p'] = $options['top_p'] ?? 1.0;
        $options['presence_penalty'] = $options['presence_penalty'] ?? 0.6;

        return $this->complete($prompt, $options);
    }

    /**
     * Validate completion options
     *
     * @param array $options
     * @throws InvalidArgumentException
     */
    private function validateOptions(array $options): void
    {
        if (isset($options['temperature'])) {
            $temp = $options['temperature'];
            if (!is_numeric($temp) || $temp < 0 || $temp > 2) {
                throw new InvalidArgumentException(
                    'Temperature must be between 0.0 and 2.0'
                );
            }
        }

        if (isset($options['max_tokens'])) {
            $maxTokens = $options['max_tokens'];
            if (!is_int($maxTokens) || $maxTokens < 1) {
                throw new InvalidArgumentException(
                    'max_tokens must be a positive integer'
                );
            }
        }

        if (isset($options['top_p'])) {
            $topP = $options['top_p'];
            if (!is_numeric($topP) || $topP < 0 || $topP > 1) {
                throw new InvalidArgumentException(
                    'top_p must be between 0.0 and 1.0'
                );
            }
        }

        if (isset($options['response_format'])) {
            $format = $options['response_format'];
            if (!in_array($format, ['text', 'json', 'markdown'], true)) {
                throw new InvalidArgumentException(
                    'response_format must be "text", "json", or "markdown"'
                );
            }
        }
    }

    /**
     * Normalize response format for provider compatibility
     *
     * @param string $format
     * @return array|string
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
