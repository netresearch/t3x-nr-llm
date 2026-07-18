<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider;

use Generator;
use JsonException;
use Netresearch\NrLlm\Attribute\AsLlmProvider;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Provider\Contract\StreamingCapableInterface;
use Netresearch\NrLlm\Provider\Contract\ToolCapableInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;

/**
 * Mistral AI Provider.
 *
 * EU-based AI company with GDPR compliance, offering competitive
 * performance/cost ratio via OpenAI-compatible API.
 *
 * Features:
 * - Chat completions with streaming
 * - Function/tool calling
 * - Embeddings
 * - Code-specialized model (Codestral)
 */
#[AsLlmProvider(priority: 60)]
final class MistralProvider extends AbstractProvider implements
    StreamingCapableInterface,
    ToolCapableInterface
{
    /** @var array<string> */
    protected array $supportedFeatures = [
        self::FEATURE_CHAT,
        self::FEATURE_COMPLETION,
        self::FEATURE_EMBEDDINGS,
        self::FEATURE_STREAMING,
        self::FEATURE_TOOLS,
    ];

    private const ENDPOINT_CHAT_COMPLETIONS = 'chat/completions';

    private const DEFAULT_CHAT_MODEL = 'mistral-large-latest';
    private const DEFAULT_EMBEDDING_MODEL = 'mistral-embed';

    /** @var array<string, string> */
    private const MODELS = [
        'mistral-large-latest' => 'Mistral Large (Latest)',
        'mistral-large-2411' => 'Mistral Large 2411',
        'mistral-medium-latest' => 'Mistral Medium',
        'mistral-small-latest' => 'Mistral Small (Latest)',
        'mistral-small-2409' => 'Mistral Small 2409',
        'open-mistral-nemo' => 'Mistral Nemo (Open)',
        'codestral-latest' => 'Codestral (Code)',
        'codestral-2405' => 'Codestral 2405',
        'ministral-8b-latest' => 'Ministral 8B',
        'ministral-3b-latest' => 'Ministral 3B',
    ];

    public function getName(): string
    {
        return 'Mistral AI';
    }

    public function getIdentifier(): string
    {
        return 'mistral';
    }

    protected function getDefaultBaseUrl(): string
    {
        return 'https://api.mistral.ai/v1';
    }

    public function getDefaultModel(): string
    {
        return $this->defaultModel !== '' ? $this->defaultModel : self::DEFAULT_CHAT_MODEL;
    }

    /**
     * @return array<string, string>
     */
    public function getAvailableModels(): array
    {
        return self::MODELS;
    }

    /**
     * Test the connection to Mistral AI.
     *
     * getAvailableModels() returns a static list and never touches the
     * network, so the AbstractProvider default would report success even
     * when the endpoint is unreachable or the key is invalid. Make a real
     * lightweight GET to the OpenAI-compatible models endpoint and let any
     * failure surface as the typed exception sendRequest() raises.
     *
     * @throws ProviderConnectionException on connection failure
     *
     * @return array{success: bool, message: string, models?: array<string, string>}
     */
    public function testConnection(): array
    {
        return $this->testConnectionViaModelsList();
    }

    /**
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param array<string, mixed>                   $options
     */
    public function chatCompletion(array $messages, array $options = []): CompletionResponse
    {
        $messages = array_map(
            static fn(ChatMessage|array $m): array
                => $m instanceof ChatMessage ? $m->toArray() : $m,
            $messages,
        );

        $model = $this->getString($options, 'model', $this->getDefaultModel());

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $this->getFloat($options, 'temperature', 0.7),
            'max_tokens' => $this->getInt($options, 'max_tokens', 4096),
        ];

        if (isset($options['top_p'])) {
            $payload['top_p'] = $this->getFloat($options, 'top_p');
        }

        if (isset($options['stop'])) {
            $payload['stop'] = $options['stop'];
        } else {
            // ChatOptions emits stop sequences as `stop_sequences`; the
            // OpenAI-compatible API expects them under `stop`.
            $stopSequences = $options['stop_sequences'] ?? null;
            if (is_array($stopSequences) && $stopSequences !== []) {
                $payload['stop'] = $stopSequences;
            }
        }

        // Mistral uses 'random_seed' instead of 'seed'
        if (isset($options['seed'])) {
            $payload['random_seed'] = $this->getInt($options, 'seed');
        }

        // Safe prompt - Mistral specific
        if (isset($options['safe_prompt'])) {
            $payload['safe_prompt'] = $this->getBool($options, 'safe_prompt');
        }

        $response = $this->sendRequest(self::ENDPOINT_CHAT_COMPLETIONS, $payload, timeout: $this->resolveRequestTimeout($options));

        $choices = $this->getList($response, 'choices');
        $choice = $this->asArray($choices[0] ?? []);
        $message = $this->getArray($choice, 'message');
        $usage = $this->getArray($response, 'usage');

        return $this->createCompletionResponse(
            content: $this->getString($message, 'content'),
            model: $this->getString($response, 'model', $model),
            usage: $this->createUsageStatistics(
                promptTokens: $this->getInt($usage, 'prompt_tokens'),
                completionTokens: $this->getInt($usage, 'completion_tokens'),
            ),
            finishReason: $this->getString($choice, 'finish_reason', 'stop'),
        );
    }

    /**
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param list<ToolSpec>                         $tools
     * @param array<string, mixed>                   $options
     */
    public function chatCompletionWithTools(array $messages, array $tools, array $options = []): CompletionResponse
    {
        $messages = array_map(
            static fn(ChatMessage|array $m): array
                => $m instanceof ChatMessage ? $m->toArray() : $m,
            $messages,
        );

        $model = $this->getString($options, 'model', $this->getDefaultModel());

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'tools' => array_map(static fn(ToolSpec $spec): array => $spec->toArray(), $tools),
            'temperature' => $this->getFloat($options, 'temperature', 0.7),
            'max_tokens' => $this->getInt($options, 'max_tokens', 4096),
        ];

        if (isset($options['tool_choice'])) {
            $payload['tool_choice'] = $options['tool_choice'];
        }

        $response = $this->sendRequest(self::ENDPOINT_CHAT_COMPLETIONS, $payload, timeout: $this->resolveRequestTimeout($options));

        $choices = $this->getList($response, 'choices');
        $choice = $this->asArray($choices[0] ?? []);
        $message = $this->getArray($choice, 'message');
        $usage = $this->getArray($response, 'usage');

        $toolCalls    = null;
        $rawToolCalls = $this->getArray($message, 'tool_calls');
        if ($rawToolCalls !== []) {
            $toolCalls = [];
            foreach ($rawToolCalls as $tc) {
                // Untrusted provider output: skip a malformed tool call (missing
                // id/name) instead of crashing the whole completion.
                $call = ToolCall::tryFromArray($this->asArray($tc));
                if ($call !== null) {
                    $toolCalls[] = $call;
                }
            }
        }

        return new CompletionResponse(
            content: $this->getString($message, 'content'),
            model: $this->getString($response, 'model', $model),
            usage: $this->createUsageStatistics(
                promptTokens: $this->getInt($usage, 'prompt_tokens'),
                completionTokens: $this->getInt($usage, 'completion_tokens'),
            ),
            finishReason: $this->getString($choice, 'finish_reason', 'stop'),
            provider: $this->getIdentifier(),
            toolCalls: $toolCalls,
            metadata: $this->rawResponseMetadata($options, $response),
        );
    }

    public function supportsTools(): bool
    {
        return true;
    }

    /**
     * @param string|array<int, string> $input
     * @param array<string, mixed>      $options
     */
    public function embeddings(string|array $input, array $options = []): EmbeddingResponse
    {
        $inputs = is_array($input) ? $input : [$input];
        $model = $this->getString($options, 'model', self::DEFAULT_EMBEDDING_MODEL);

        $payload = [
            'model' => $model,
            'input' => $inputs,
        ];

        // Mistral supports encoding_format
        if (isset($options['encoding_format'])) {
            $payload['encoding_format'] = $this->getString($options, 'encoding_format');
        }

        $response = $this->sendRequest('embeddings', $payload, timeout: $this->resolveRequestTimeout($options));

        $data = $this->getList($response, 'data');
        /** @var array<int, array<int, float>> $embeddings */
        $embeddings = [];
        foreach ($data as $item) {
            $itemArray = $this->asArray($item);
            $embedding = $this->getArray($itemArray, 'embedding');
            /** @var list<float> $floatEmbedding */
            $floatEmbedding = array_values(array_map(fn($v): float => $this->asFloat($v), $embedding));
            $embeddings[] = $floatEmbedding;
        }

        $usage = $this->getArray($response, 'usage');

        return $this->createEmbeddingResponse(
            embeddings: $embeddings,
            model: $this->getString($response, 'model', $model),
            usage: $this->createUsageStatistics(
                promptTokens: $this->getInt($usage, 'prompt_tokens'),
                completionTokens: 0,
            ),
        );
    }

    /**
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param array<string, mixed>                   $options
     *
     * @return Generator<int, string, mixed, void>
     */
    public function streamChatCompletion(array $messages, array $options = []): Generator
    {
        // Mirror the non-streaming path (sendRequest validates): fail fast with a
        // typed ProviderConfigurationException instead of a cryptic stream error.
        $this->validateConfiguration();

        $messages = array_map(
            static fn(ChatMessage|array $m): array
                => $m instanceof ChatMessage ? $m->toArray() : $m,
            $messages,
        );

        $payload = [
            'model' => $this->getString($options, 'model', $this->getDefaultModel()),
            'messages' => $messages,
            'temperature' => $this->getFloat($options, 'temperature', 0.7),
            'max_tokens' => $this->getInt($options, 'max_tokens', 4096),
            'stream' => true,
        ];

        $url = rtrim($this->baseUrl, '/') . '/' . self::ENDPOINT_CHAT_COMPLETIONS;

        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'text/event-stream');

        // Streaming bypasses sendRequest(), so operator-configured custom
        // headers must be applied here.
        $request = $this->applyCustomHeaders($request);

        $body = $this->streamFactory->createStream(json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        $request = $request->withBody($body);

        $response = $this->getHttpClient($this->resolveRequestTimeout($options))->sendRequest($request);
        $this->assertStreamingResponseOk($response, self::ENDPOINT_CHAT_COMPLETIONS);
        $stream = $response->getBody();

        $buffer = '';
        while (!$stream->eof()) {
            $chunk = $stream->read(1024);
            $buffer .= $chunk;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                if (!str_starts_with($line, 'data: ')) {
                    continue;
                }

                $data = substr($line, 6);

                if ($data === '[DONE]') {
                    return;
                }

                $content = $this->extractStreamDelta($data);
                if ($content !== null) {
                    yield $content;
                }
            }

            $this->guardStreamLineBuffer($buffer);
        }
    }

    /**
     * Extract the delta content from a single streaming SSE data payload.
     *
     * Returns null for malformed JSON or empty content so the caller can skip it.
     */
    private function extractStreamDelta(string $data): ?string
    {
        try {
            $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Skip malformed JSON
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $json = $this->asArray($decoded);
        $choices = $this->getList($json, 'choices');
        $firstChoice = $this->asArray($choices[0] ?? []);
        $delta = $this->getArray($firstChoice, 'delta');
        $content = $this->getString($delta, 'content');

        return $content !== '' ? $content : null;
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    /**
     * Get code-optimized model identifier.
     */
    public static function getCodeModel(): string
    {
        return 'codestral-latest';
    }

    /**
     * Get cost-efficient small model identifier.
     */
    public static function getSmallModel(): string
    {
        return 'mistral-small-latest';
    }
}
