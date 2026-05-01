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
use Netresearch\NrLlm\Provider\Contract\StreamingCapableInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Throwable;

/**
 * Ollama provider for local LLM deployments.
 *
 * Ollama provides an OpenAI-compatible API for local model serving.
 *
 * @see https://ollama.com/
 */
#[AsLlmProvider(priority: 40)]
final class OllamaProvider extends AbstractProvider implements StreamingCapableInterface
{
    /** @var array<string> */
    protected array $supportedFeatures = [
        self::FEATURE_CHAT,
        self::FEATURE_COMPLETION,
        self::FEATURE_EMBEDDINGS,
        self::FEATURE_STREAMING,
    ];

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
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return '';
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host   = $parts['host'] ?? '';
        $port   = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path   = $parts['path'] ?? '';

        if ($host === '') {
            return '';
        }

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

        $response = $this->sendRequest('api/chat', $payload);

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

        $url = rtrim($this->baseUrl, '/') . '/api/chat';

        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json');

        $body = $this->streamFactory->createStream(json_encode($payload, JSON_THROW_ON_ERROR));
        $request = $request->withBody($body);

        $response = $this->getHttpClient()->sendRequest($request);
        $stream = $response->getBody();

        $buffer = '';
        while (!$stream->eof()) {
            $chunk = $stream->read(1024);
            $buffer .= $chunk;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                if (trim($line) === '') {
                    continue;
                }

                try {
                    $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        $json = $this->asArray($decoded);
                        $message = $this->getArray($json, 'message');
                        $content = $this->getString($message, 'content');
                        if ($content !== '') {
                            yield $content;
                        }

                        // Check if done
                        if ($this->getBool($json, 'done')) {
                            return;
                        }
                    }
                } catch (JsonException) {
                    // Skip malformed JSON
                }
            }
        }
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
