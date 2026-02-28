<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider;

use Generator;
use JsonException;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Provider\Contract\StreamingCapableInterface;
use Netresearch\NrLlm\Provider\Contract\ToolCapableInterface;

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
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed>             $options
     */
    public function chatCompletion(array $messages, array $options = []): CompletionResponse
    {
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

        // Mistral uses 'random_seed' instead of 'seed'
        if (isset($options['seed'])) {
            $payload['random_seed'] = $this->getInt($options, 'seed');
        }

        // Safe prompt - Mistral specific
        if (isset($options['safe_prompt'])) {
            $payload['safe_prompt'] = $this->getBool($options, 'safe_prompt');
        }

        $response = $this->sendRequest('chat/completions', $payload);

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
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tools
     * @param array<string, mixed>             $options
     */
    public function chatCompletionWithTools(array $messages, array $tools, array $options = []): CompletionResponse
    {
        $model = $this->getString($options, 'model', $this->getDefaultModel());

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'tools' => $tools,
            'temperature' => $this->getFloat($options, 'temperature', 0.7),
            'max_tokens' => $this->getInt($options, 'max_tokens', 4096),
        ];

        if (isset($options['tool_choice'])) {
            $payload['tool_choice'] = $options['tool_choice'];
        }

        $response = $this->sendRequest('chat/completions', $payload);

        $choices = $this->getList($response, 'choices');
        $choice = $this->asArray($choices[0] ?? []);
        $message = $this->getArray($choice, 'message');
        $usage = $this->getArray($response, 'usage');

        $toolCalls = null;
        $rawToolCalls = $this->getArray($message, 'tool_calls');
        if ($rawToolCalls !== []) {
            $toolCalls = [];
            foreach ($rawToolCalls as $tc) {
                $tcArray = $this->asArray($tc);
                $function = $this->getArray($tcArray, 'function');
                $arguments = $this->getString($function, 'arguments');
                $decodedArgs = json_decode($arguments, true);

                $toolCalls[] = [
                    'id' => $this->getString($tcArray, 'id'),
                    'type' => $this->getString($tcArray, 'type'),
                    'function' => [
                        'name' => $this->getString($function, 'name'),
                        'arguments' => is_array($decodedArgs) ? $decodedArgs : [],
                    ],
                ];
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

        $response = $this->sendRequest('embeddings', $payload);

        $data = $this->getList($response, 'data');
        /** @var array<int, array<int, float>> $embeddings */
        $embeddings = [];
        foreach ($data as $item) {
            $itemArray = $this->asArray($item);
            $embedding = $this->getArray($itemArray, 'embedding');
            /** @var array<int, float> $floatEmbedding */
            $floatEmbedding = array_map(fn($v): float => $this->asFloat($v), $embedding);
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
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed>             $options
     *
     * @return Generator<int, string, mixed, void>
     */
    public function streamChatCompletion(array $messages, array $options = []): Generator
    {
        $payload = [
            'model' => $this->getString($options, 'model', $this->getDefaultModel()),
            'messages' => $messages,
            'temperature' => $this->getFloat($options, 'temperature', 0.7),
            'max_tokens' => $this->getInt($options, 'max_tokens', 4096),
            'stream' => true,
        ];

        $url = rtrim($this->baseUrl, '/') . '/chat/completions';

        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'text/event-stream');

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

                if (str_starts_with($line, 'data: ')) {
                    $data = substr($line, 6);

                    if ($data === '[DONE]') {
                        return;
                    }

                    try {
                        $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                        if (is_array($decoded)) {
                            $json = $this->asArray($decoded);
                            $choices = $this->getList($json, 'choices');
                            $firstChoice = $this->asArray($choices[0] ?? []);
                            $delta = $this->getArray($firstChoice, 'delta');
                            $content = $this->getString($delta, 'content');
                            if ($content !== '') {
                                yield $content;
                            }
                        }
                    } catch (JsonException) {
                        // Skip malformed JSON
                    }
                }
            }
        }
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
