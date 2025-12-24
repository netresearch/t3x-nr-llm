<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Response;

use Netresearch\NrLlm\Domain\Model\LlmResponse;
use Netresearch\NrLlm\Domain\Model\TokenUsage;
use Netresearch\NrLlm\Domain\Model\StreamChunk;
use Netresearch\NrLlm\Exception\ProviderResponseException;

/**
 * Response parser that normalizes different provider formats
 *
 * Handles response parsing for:
 * - OpenAI (completions, chat, vision, embeddings)
 * - Anthropic (messages API)
 * - Google Gemini
 * - Other providers with consistent normalization
 */
class ResponseParser
{
    /**
     * Parse provider response to normalized LlmResponse
     *
     * @param array|object $rawResponse Raw provider response
     * @param string $providerName Provider identifier
     * @return LlmResponse Normalized response
     * @throws ProviderResponseException If response cannot be parsed
     */
    public function parse(array|object $rawResponse, string $providerName): LlmResponse
    {
        $response = is_object($rawResponse) ? (array) $rawResponse : $rawResponse;

        return match ($providerName) {
            'openai' => $this->parseOpenAi($response),
            'anthropic' => $this->parseAnthropic($response),
            'gemini' => $this->parseGemini($response),
            'deepl' => $this->parseDeepL($response),
            'ollama' => $this->parseOllama($response),
            default => $this->parseGeneric($response)
        };
    }

    /**
     * Parse streaming chunk
     *
     * @param string $chunk Raw SSE chunk
     * @param string $providerName Provider identifier
     * @return StreamChunk|null Parsed chunk or null if not yet complete
     */
    public function parseStream(string $chunk, string $providerName): ?StreamChunk
    {
        return match ($providerName) {
            'openai' => $this->parseOpenAiStream($chunk),
            'anthropic' => $this->parseAnthropicStream($chunk),
            'gemini' => $this->parseGeminiStream($chunk),
            default => null
        };
    }

    /**
     * Parse OpenAI response format
     */
    private function parseOpenAi(array $response): LlmResponse
    {
        // Handle chat completion format
        if (isset($response['choices'][0]['message']['content'])) {
            $content = $response['choices'][0]['message']['content'];
            $finishReason = $response['choices'][0]['finish_reason'] ?? null;
        }
        // Handle legacy completion format
        elseif (isset($response['choices'][0]['text'])) {
            $content = $response['choices'][0]['text'];
            $finishReason = $response['choices'][0]['finish_reason'] ?? null;
        } else {
            throw new ProviderResponseException(
                'Invalid OpenAI response format',
                context: ['response' => $response]
            );
        }

        // Parse token usage
        $usage = null;
        if (isset($response['usage'])) {
            $usage = new TokenUsage(
                promptTokens: $response['usage']['prompt_tokens'] ?? 0,
                completionTokens: $response['usage']['completion_tokens'] ?? 0,
                totalTokens: $response['usage']['total_tokens'] ?? 0
            );
        }

        // Extract metadata
        $metadata = [
            'model' => $response['model'] ?? null,
            'created' => $response['created'] ?? null,
            'id' => $response['id'] ?? null,
            'provider' => 'openai',
        ];

        return new LlmResponse(
            content: $content,
            usage: $usage,
            metadata: $metadata,
            finishReason: $finishReason
        );
    }

    /**
     * Parse Anthropic response format
     */
    private function parseAnthropic(array $response): LlmResponse
    {
        if (!isset($response['content'][0]['text'])) {
            throw new ProviderResponseException(
                'Invalid Anthropic response format',
                context: ['response' => $response]
            );
        }

        $content = $response['content'][0]['text'];
        $finishReason = $response['stop_reason'] ?? null;

        // Parse token usage
        $usage = null;
        if (isset($response['usage'])) {
            $usage = new TokenUsage(
                promptTokens: $response['usage']['input_tokens'] ?? 0,
                completionTokens: $response['usage']['output_tokens'] ?? 0,
                totalTokens: ($response['usage']['input_tokens'] ?? 0)
                            + ($response['usage']['output_tokens'] ?? 0)
            );
        }

        $metadata = [
            'model' => $response['model'] ?? null,
            'id' => $response['id'] ?? null,
            'role' => $response['role'] ?? null,
            'provider' => 'anthropic',
        ];

        return new LlmResponse(
            content: $content,
            usage: $usage,
            metadata: $metadata,
            finishReason: $finishReason
        );
    }

    /**
     * Parse Google Gemini response format
     */
    private function parseGemini(array $response): LlmResponse
    {
        if (!isset($response['candidates'][0]['content']['parts'][0]['text'])) {
            throw new ProviderResponseException(
                'Invalid Gemini response format',
                context: ['response' => $response]
            );
        }

        $content = $response['candidates'][0]['content']['parts'][0]['text'];
        $finishReason = $response['candidates'][0]['finishReason'] ?? null;

        // Parse token usage (if available)
        $usage = null;
        if (isset($response['usageMetadata'])) {
            $usage = new TokenUsage(
                promptTokens: $response['usageMetadata']['promptTokenCount'] ?? 0,
                completionTokens: $response['usageMetadata']['candidatesTokenCount'] ?? 0,
                totalTokens: $response['usageMetadata']['totalTokenCount'] ?? 0
            );
        }

        $metadata = [
            'model' => $response['modelVersion'] ?? null,
            'provider' => 'gemini',
        ];

        return new LlmResponse(
            content: $content,
            usage: $usage,
            metadata: $metadata,
            finishReason: $finishReason
        );
    }

    /**
     * Parse DeepL response format
     */
    private function parseDeepL(array $response): LlmResponse
    {
        if (!isset($response['translations'][0]['text'])) {
            throw new ProviderResponseException(
                'Invalid DeepL response format',
                context: ['response' => $response]
            );
        }

        $content = $response['translations'][0]['text'];

        $metadata = [
            'detected_source_language' => $response['translations'][0]['detected_source_language'] ?? null,
            'provider' => 'deepl',
        ];

        return new LlmResponse(
            content: $content,
            usage: null,
            metadata: $metadata,
            finishReason: 'complete'
        );
    }

    /**
     * Parse Ollama response format
     */
    private function parseOllama(array $response): LlmResponse
    {
        if (!isset($response['response'])) {
            throw new ProviderResponseException(
                'Invalid Ollama response format',
                context: ['response' => $response]
            );
        }

        $content = $response['response'];
        $finishReason = $response['done'] ? 'complete' : null;

        // Parse token usage
        $usage = null;
        if (isset($response['prompt_eval_count'], $response['eval_count'])) {
            $usage = new TokenUsage(
                promptTokens: $response['prompt_eval_count'],
                completionTokens: $response['eval_count'],
                totalTokens: $response['prompt_eval_count'] + $response['eval_count']
            );
        }

        $metadata = [
            'model' => $response['model'] ?? null,
            'created_at' => $response['created_at'] ?? null,
            'provider' => 'ollama',
        ];

        return new LlmResponse(
            content: $content,
            usage: $usage,
            metadata: $metadata,
            finishReason: $finishReason
        );
    }

    /**
     * Parse generic/unknown provider response
     */
    private function parseGeneric(array $response): LlmResponse
    {
        // Try to find content in common locations
        $content = $response['content'] ?? $response['text'] ?? $response['response'] ?? '';

        if (empty($content) && is_string($response)) {
            $content = $response;
        }

        if (empty($content)) {
            throw new ProviderResponseException(
                'Cannot find content in response',
                context: ['response' => $response]
            );
        }

        return new LlmResponse(
            content: $content,
            usage: null,
            metadata: ['provider' => 'generic'],
            finishReason: null
        );
    }

    /**
     * Parse OpenAI streaming chunk
     */
    private function parseOpenAiStream(string $chunk): ?StreamChunk
    {
        // OpenAI uses SSE format: "data: {json}\n\n"
        if (!str_starts_with($chunk, 'data: ')) {
            return null;
        }

        $data = trim(substr($chunk, 6));

        // Check for stream end
        if ($data === '[DONE]') {
            return new StreamChunk('', true, 'complete');
        }

        $json = json_decode($data, true);
        if ($json === null) {
            return null;
        }

        $content = $json['choices'][0]['delta']['content'] ?? '';
        $finishReason = $json['choices'][0]['finish_reason'] ?? null;
        $isComplete = $finishReason !== null;

        return new StreamChunk($content, $isComplete, $finishReason);
    }

    /**
     * Parse Anthropic streaming chunk
     */
    private function parseAnthropicStream(string $chunk): ?StreamChunk
    {
        // Anthropic uses event: + data: format
        $lines = explode("\n", $chunk);
        $eventType = null;
        $data = null;

        foreach ($lines as $line) {
            if (str_starts_with($line, 'event: ')) {
                $eventType = trim(substr($line, 7));
            } elseif (str_starts_with($line, 'data: ')) {
                $data = json_decode(trim(substr($line, 6)), true);
            }
        }

        if ($eventType === 'content_block_delta' && isset($data['delta']['text'])) {
            return new StreamChunk($data['delta']['text'], false, null);
        }

        if ($eventType === 'message_stop') {
            return new StreamChunk('', true, 'complete');
        }

        return null;
    }

    /**
     * Parse Gemini streaming chunk
     */
    private function parseGeminiStream(string $chunk): ?StreamChunk
    {
        $json = json_decode($chunk, true);
        if ($json === null) {
            return null;
        }

        $content = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $finishReason = $json['candidates'][0]['finishReason'] ?? null;
        $isComplete = $finishReason !== null;

        return new StreamChunk($content, $isComplete, $finishReason);
    }
}
