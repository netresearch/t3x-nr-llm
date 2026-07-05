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
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * Ollama provider for local LLM deployments.
 *
 * Ollama provides an OpenAI-compatible API for local model serving.
 *
 * @see https://ollama.com/
 */
#[AsLlmProvider(priority: 40)]
final class OllamaProvider extends AbstractProvider implements StreamingCapableInterface, ToolCapableInterface
{
    /** @var array<string> */
    protected array $supportedFeatures = [
        self::FEATURE_CHAT,
        self::FEATURE_COMPLETION,
        self::FEATURE_EMBEDDINGS,
        self::FEATURE_STREAMING,
    ];

    private const ENDPOINT_CHAT = 'api/chat';

    private const DEFAULT_MODEL = 'llama3.2';

    public function getName(): string
    {
        return 'Ollama';
    }

    public function getIdentifier(): string
    {
        return 'ollama';
    }

    protected function getDefaultBaseUrl(): string
    {
        return 'http://localhost:11434';
    }

    public function getDefaultModel(): string
    {
        return $this->defaultModel !== '' ? $this->defaultModel : self::DEFAULT_MODEL;
    }

    public function isAvailable(): bool
    {
        // Ollama doesn't require an API key
        return $this->baseUrl !== '';
    }

    protected function validateConfiguration(): void
    {
        // Ollama doesn't require API key validation
        if ($this->baseUrl === '') {
            $this->baseUrl = $this->getDefaultBaseUrl();
        }
    }

    /**
     * @return array<string, string>
     */
    public function getAvailableModels(): array
    {
        try {
            $response = $this->sendRequest('api/tags', [], 'GET');
            $models = $this->getList($response, 'models');

            $result = [];
            foreach ($models as $model) {
                $modelArray = $this->asArray($model);
                $name = $this->getString($modelArray, 'name');
                if ($name !== '') {
                    $result[$name] = $name;
                }
            }

            return $result;
        } catch (Throwable $e) {
            // Server unreachable — fall back to a small hardcoded list so the
            // model picker still has something to show. Log so operators know
            // their Ollama endpoint isn't responding (REC #11). Userinfo
            // (`https://user:pass@host`) and query / fragment are stripped
            // before the URL hits the log so credentials accidentally embedded
            // in a misconfigured baseUrl don't leak into sys_log.
            $this->logger->warning('Ollama: getAvailableModels failed, returning hardcoded defaults', [
                'exception' => $e,
                'baseUrl'   => self::sanitizeUrlForLog($this->baseUrl),
            ]);

            return [
                'llama3.2'     => 'Llama 3.2',
                'llama3.2:70b' => 'Llama 3.2 70B',
                'mistral'      => 'Mistral',
                'codellama'    => 'Code Llama',
                'phi3'         => 'Phi-3',
            ];
        }
    }

    /**
     * Strip userinfo + query + fragment from a URL so it is safe to log.
     *
     * Returns scheme://host[:port][/path] for valid URLs, or the empty
     * string if the input is unparseable. Used by the
     * `getAvailableModels()` failure log so a baseUrl misconfigured with
     * embedded credentials (`https://user:pass@host:11434`) does not leak
     * those credentials into sys_log.
     */
    private static function sanitizeUrlForLog(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return '';
        }

        $host = $parts['host'] ?? '';
        if ($host === '') {
            return '';
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $port   = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path   = $parts['path'] ?? '';

        return $scheme . $host . $port . $path;
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

        // The truncation-synthesis path replays prior tool turns through this
        // plain endpoint, so apply the same OpenAI->Ollama shape translation as
        // chatCompletionWithTools(). It is a no-op for plain {role,content}
        // turns, so it is safe for every caller.
        $messages = $this->normaliseToolTurnsForOllama($messages);

        $model = $this->getString($options, 'model', $this->getDefaultModel());

        /** @var array<string, mixed> $payloadOptions */
        $payloadOptions = [];

        // Add optional parameters
        if (isset($options['temperature'])) {
            $payloadOptions['temperature'] = $this->getFloat($options, 'temperature');
        }

        if (isset($options['top_p'])) {
            $payloadOptions['top_p'] = $this->getFloat($options, 'top_p');
        }

        if (isset($options['num_predict']) || isset($options['max_tokens'])) {
            $payloadOptions['num_predict'] = $this->getInt($options, 'num_predict', $this->getInt($options, 'max_tokens', 4096));
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
        ];

        if ($payloadOptions !== []) {
            $payload['options'] = $payloadOptions;
        }

        $response = $this->sendRequest(self::ENDPOINT_CHAT, $payload);

        $message = $this->getArray($response, 'message');

        return $this->createCompletionResponse(
            content: $this->getString($message, 'content'),
            model: $this->getString($response, 'model', $model),
            usage: $this->createUsageStatistics(
                promptTokens: $this->getInt($response, 'prompt_eval_count'),
                completionTokens: $this->getInt($response, 'eval_count'),
            ),
            finishReason: $this->getString($response, 'done_reason', 'stop'),
        );
    }

    /**
     * Ollama exposes OpenAI-style function calling on the same `/api/chat`
     * endpoint: tools are declared under a top-level `tools` key and the model
     * answers with `message.tool_calls`. Unlike OpenAI it returns no call id and
     * expects replayed turns in its own native shape, both reconciled here.
     *
     * `tool_choice` / `parallel_tool_calls` options are intentionally ignored —
     * Ollama does not understand them.
     *
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

        $messages = $this->normaliseToolTurnsForOllama($messages);

        $model = $this->getString($options, 'model', $this->getDefaultModel());

        /** @var array<string, mixed> $payloadOptions */
        $payloadOptions = [];

        if (isset($options['temperature'])) {
            $payloadOptions['temperature'] = $this->getFloat($options, 'temperature');
        }

        if (isset($options['top_p'])) {
            $payloadOptions['top_p'] = $this->getFloat($options, 'top_p');
        }

        if (isset($options['num_predict']) || isset($options['max_tokens'])) {
            $payloadOptions['num_predict'] = $this->getInt($options, 'num_predict', $this->getInt($options, 'max_tokens', 4096));
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
            'tools' => array_map(static fn(ToolSpec $spec): array => $spec->toArray(), $tools),
        ];

        if ($payloadOptions !== []) {
            $payload['options'] = $payloadOptions;
        }

        $response = $this->sendRequest(self::ENDPOINT_CHAT, $payload);

        $message = $this->getArray($response, 'message');

        $toolCalls    = null;
        $rawToolCalls = $this->getArray($message, 'tool_calls');
        if ($rawToolCalls !== []) {
            $toolCalls = [];
            $index     = 0;
            foreach ($rawToolCalls as $rawToolCall) {
                $callData = $this->asArray($rawToolCall);
                // Ollama returns no call id; synthesise a stable, non-empty one
                // (ToolCall rejects an empty id). `function.arguments` already
                // arrives as an object, which ToolCall normalises straight
                // through.
                $callData['id'] = 'call_' . $index;
                $toolCalls[]    = ToolCall::fromArray($callData);
                ++$index;
            }
        }

        return new CompletionResponse(
            content: $this->getString($message, 'content'),
            model: $this->getString($response, 'model', $model),
            usage: $this->createUsageStatistics(
                promptTokens: $this->getInt($response, 'prompt_eval_count'),
                completionTokens: $this->getInt($response, 'eval_count'),
            ),
            finishReason: $this->getString($response, 'done_reason', 'stop'),
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
     * Translate replayed OpenAI-shape conversation turns into Ollama's native
     * chat shape before they go back on the wire.
     *
     * The agent loop replays canonical OpenAI-shaped turns: an assistant turn
     * carries `tool_calls` whose `function.arguments` is a JSON *string*, and a
     * tool result arrives as a `{role:'tool', tool_call_id, content}` turn.
     * Ollama wants `function.arguments` as a decoded object and keys tool
     * results by role/position rather than id, so:
     *
     *  - assistant turns: each `function.arguments` JSON string is decoded to an
     *    object/array;
     *  - `tool` turns: the `tool_call_id` key is dropped.
     *
     * Plain `{role, content}` turns pass through untouched.
     *
     * @param list<array<string, mixed>> $messages
     *
     * @return list<array<string, mixed>>
     */
    private function normaliseToolTurnsForOllama(array $messages): array
    {
        $normalised = [];
        foreach ($messages as $message) {
            if (($message['role'] ?? null) === 'tool') {
                unset($message['tool_call_id']);
                $normalised[] = $message;
                continue;
            }

            if (isset($message['tool_calls']) && is_array($message['tool_calls'])) {
                $calls = [];
                foreach ($message['tool_calls'] as $call) {
                    $calls[] = $this->decodeToolCallArguments($call);
                }
                $message['tool_calls'] = $calls;
            }

            $normalised[] = $message;
        }

        return $normalised;
    }

    /**
     * Decode a single replayed assistant tool-call's `function.arguments` from
     * the OpenAI JSON-string form into Ollama's object form. Anything that is
     * not a tool-call array with a string `arguments` is returned untouched.
     */
    private function decodeToolCallArguments(mixed $call): mixed
    {
        if (!is_array($call)) {
            return $call;
        }

        $function = $call['function'] ?? null;
        if (!is_array($function)) {
            return $call;
        }

        $arguments = $function['arguments'] ?? null;
        if (!is_string($arguments)) {
            return $call;
        }

        // Decode WITHOUT associative arrays. json_decode('{}', true) === [],
        // which would re-encode a parameterless tool call's empty arguments as
        // the array [] and make Ollama reject the replayed turn with
        // "Value looks like object, but can't find closing '}' symbol".
        // Decoding to a stdClass preserves the empty object as {}.
        $decoded = json_decode($arguments);
        if (is_object($decoded) || is_array($decoded)) {
            $function['arguments'] = $decoded;
            $call['function']      = $function;
        }

        return $call;
    }

    /**
     * @param string|array<int, string> $input
     * @param array<string, mixed>      $options
     */
    public function embeddings(string|array $input, array $options = []): EmbeddingResponse
    {
        $model = $this->getString($options, 'model', 'nomic-embed-text');
        $inputs = is_array($input) ? $input : [$input];

        $embeddings = [];
        $totalTokens = 0;

        foreach ($inputs as $text) {
            $response = $this->sendRequest('api/embeddings', [
                'model' => $model,
                'prompt' => $text,
            ]);

            $rawEmbedding = $this->getList($response, 'embedding');
            $embedding = array_values(array_map(fn(mixed $v): float => (float)$v, $rawEmbedding));
            $embeddings[] = $embedding;
            $totalTokens += $this->getInt($response, 'prompt_eval_count', 0);
        }

        return $this->createEmbeddingResponse(
            embeddings: $embeddings,
            model: $model,
            usage: $this->createUsageStatistics(
                promptTokens: $totalTokens,
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

        $model = $this->getString($options, 'model', $this->getDefaultModel());

        /** @var array<string, mixed> $payloadOptions */
        $payloadOptions = [];

        if (isset($options['temperature'])) {
            $payloadOptions['temperature'] = $this->getFloat($options, 'temperature');
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'stream' => true,
        ];

        if ($payloadOptions !== []) {
            $payload['options'] = $payloadOptions;
        }

        $url = rtrim($this->baseUrl, '/') . '/' . self::ENDPOINT_CHAT;

        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json');

        $body = $this->streamFactory->createStream(json_encode($payload, JSON_THROW_ON_ERROR));
        $request = $request->withBody($body);

        $response = $this->getHttpClient()->sendRequest($request);
        $this->assertStreamingResponseOk($response, self::ENDPOINT_CHAT);

        yield from $this->streamChatLines($response->getBody());
    }

    /**
     * Read newline-delimited JSON objects from a streaming response body and
     * yield the assistant content of each chunk until the stream signals
     * `done` or the body is exhausted.
     *
     * @return Generator<int, string, mixed, void>
     */
    private function streamChatLines(StreamInterface $stream): Generator
    {
        $buffer = '';
        while (!$stream->eof()) {
            $buffer .= $stream->read(1024);

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line   = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                $json = $this->decodeStreamLine($line);
                if ($json === null) {
                    continue;
                }

                $content = $this->getString($this->getArray($json, 'message'), 'content');
                if ($content !== '') {
                    yield $content;
                }

                // Check if done
                if ($this->getBool($json, 'done')) {
                    return;
                }
            }
        }
    }

    /**
     * Decode a single streamed line into an associative array, or return null
     * for blank lines and malformed / non-object JSON (which are skipped).
     *
     * @return array<string, mixed>|null
     */
    private function decodeStreamLine(string $line): ?array
    {
        try {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Skip malformed JSON
            return null;
        }

        return is_array($decoded) ? $this->asArray($decoded) : null;
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    /**
     * Test the connection to Ollama.
     *
     * Unlike getAvailableModels(), this method does NOT return fallback models
     * on failure. It makes an actual HTTP request and throws on any error.
     *
     *
     * @throws ProviderConnectionException on connection failure
     *
     * @return array{success: bool, message: string, models?: array<string, string>}
     */
    public function testConnection(): array
    {
        // Make actual HTTP request - do NOT catch exceptions
        $response = $this->sendRequest('api/tags', [], 'GET');
        $models = $this->getList($response, 'models');

        $result = [];
        foreach ($models as $model) {
            $modelArray = $this->asArray($model);
            $name = $this->getString($modelArray, 'name');
            if ($name !== '') {
                $result[$name] = $name;
            }
        }

        return [
            'success' => true,
            'message' => sprintf('Connection successful. Found %d models.', count($result)),
            'models' => $result,
        ];
    }
}
