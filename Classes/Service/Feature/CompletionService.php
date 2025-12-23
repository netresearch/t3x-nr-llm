<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Feature;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\Option\OptionsResolverTrait;

/**
 * High-level service for text completion
 *
 * Provides simple text generation with configurable creativity,
 * format control, and token management.
 */
class CompletionService
{
    use OptionsResolverTrait;

    public function __construct(
        private readonly LlmServiceManagerInterface $llmManager,
    ) {}

    /**
     * Generate text completion
     *
     * @param string $prompt The user prompt
     * @param ChatOptions|array<string, mixed> $options Configuration options:
     *   - temperature: float (0.0-2.0) Creativity level, default 0.7
     *   - max_tokens: int Maximum output tokens, default 1000
     *   - top_p: float (0.0-1.0) Nucleus sampling, default 1.0
     *   - frequency_penalty: float (-2.0-2.0) Repetition penalty, default 0.0
     *   - presence_penalty: float (-2.0-2.0) Topic diversity, default 0.0
     *   - response_format: string ('text'|'json'|'markdown') Output format
     *   - system_prompt: string Optional system context
     *   - stop_sequences: array Stop generation on these strings
     *   - provider: string Specific provider to use
     * @throws InvalidArgumentException
     */
    public function complete(string $prompt, ChatOptions|array $options = []): CompletionResponse
    {
        $options = $this->resolveChatOptions($options);
        $this->validateOptions($options);

        $messages = [];

        // Add system prompt if provided
        if (!empty($options['system_prompt'])) {
            $messages[] = [
                'role' => 'system',
                'content' => $options['system_prompt'],
            ];
            unset($options['system_prompt']);
        }

        $messages[] = [
            'role' => 'user',
            'content' => $prompt,
        ];

        // Handle response format
        if (isset($options['response_format'])) {
            $options['response_format'] = $this->normalizeResponseFormat(
                $options['response_format']
            );
        }

        // Map stop_sequences to stop
        if (isset($options['stop_sequences'])) {
            $options['stop'] = $options['stop_sequences'];
            unset($options['stop_sequences']);
        }

        return $this->llmManager->chat($messages, $options);
    }

    /**
     * Generate JSON-formatted completion
     *
     * @param string $prompt The user prompt
     * @param ChatOptions|array<string, mixed> $options Configuration options (same as complete())
     * @return array<string, mixed> Parsed JSON response
     * @throws InvalidArgumentException
     */
    public function completeJson(string $prompt, ChatOptions|array $options = []): array
    {
        $options = $this->resolveChatOptions($options);
        $options['response_format'] = 'json';

        $response = $this->complete($prompt, $options);

        $decoded = json_decode($response->content, true);

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
     * @param ChatOptions|array<string, mixed> $options Configuration options (same as complete())
     * @return string Markdown-formatted text
     */
    public function completeMarkdown(string $prompt, ChatOptions|array $options = []): string
    {
        $options = $this->resolveChatOptions($options);
        $options['response_format'] = 'markdown';

        $systemPrompt = $options['system_prompt'] ?? '';
        $systemPrompt .= "\n\nFormat your response in clean, well-structured Markdown.";
        $options['system_prompt'] = trim($systemPrompt);

        $response = $this->complete($prompt, $options);

        return $response->content;
    }

    /**
     * Generate completion with low creativity (factual, consistent)
     *
     * @param string $prompt The user prompt
     * @param ChatOptions|array<string, mixed> $options Additional configuration options
     */
    public function completeFactual(string $prompt, ChatOptions|array $options = []): CompletionResponse
    {
        $options = $this->resolveChatOptions($options);
        $options['temperature'] = $options['temperature'] ?? 0.2;
        $options['top_p'] = $options['top_p'] ?? 0.9;

        return $this->complete($prompt, $options);
    }

    /**
     * Generate completion with high creativity (diverse, creative)
     *
     * @param string $prompt The user prompt
     * @param ChatOptions|array<string, mixed> $options Additional configuration options
     */
    public function completeCreative(string $prompt, ChatOptions|array $options = []): CompletionResponse
    {
        $options = $this->resolveChatOptions($options);
        $options['temperature'] = $options['temperature'] ?? 1.2;
        $options['top_p'] = $options['top_p'] ?? 1.0;
        $options['presence_penalty'] = $options['presence_penalty'] ?? 0.6;

        return $this->complete($prompt, $options);
    }

    /**
     * Validate completion options
     *
     * @param array<string, mixed> $options
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
