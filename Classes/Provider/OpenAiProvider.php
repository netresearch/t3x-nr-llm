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
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Domain\ValueObject\VisionContent;
use Netresearch\NrLlm\Provider\Contract\StreamingCapableInterface;
use Netresearch\NrLlm\Provider\Contract\ToolCapableInterface;
use Netresearch\NrLlm\Provider\Contract\VisionCapableInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;

#[AsLlmProvider(priority: 100)]
final class OpenAiProvider extends AbstractProvider implements
    VisionCapableInterface,
    StreamingCapableInterface,
    ToolCapableInterface
{
    /** @var array<string> */
    protected array $supportedFeatures = [
        self::FEATURE_CHAT,
        self::FEATURE_COMPLETION,
        self::FEATURE_EMBEDDINGS,
        self::FEATURE_VISION,
        self::FEATURE_STREAMING,
        self::FEATURE_TOOLS,
    ];

    private const DEFAULT_CHAT_MODEL = 'gpt-5.2';
    private const DEFAULT_EMBEDDING_MODEL = 'text-embedding-3-small';

    public function getName(): string
    {
        return 'OpenAI';
    }

    public function getIdentifier(): string
    {
        return 'openai';
    }

    protected function getDefaultBaseUrl(): string
    {
        return 'https://api.openai.com/v1';
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
        return [
            // GPT-5 Series (Latest)
            'gpt-5.2' => 'GPT-5.2 (Flagship)',
            'gpt-5.2-pro' => 'GPT-5.2 Pro (Extended)',
            'gpt-5.2-instant' => 'GPT-5.2 Instant (Fast)',
            // GPT-4o Series
            'gpt-4o' => 'GPT-4o',
            'gpt-4o-mini' => 'GPT-4o Mini (Fast & Efficient)',
            'chatgpt-4o-latest' => 'ChatGPT-4o Latest',
            // Reasoning Models (o-series)
            'o1' => 'O1 (Advanced Reasoning)',
            'o1-mini' => 'O1 Mini (Reasoning)',
            'o1-preview' => 'O1 Preview',
            'o3' => 'O3 (Advanced Reasoning)',
            'o3-mini' => 'O3 Mini (Reasoning)',
            // Legacy Models
            'gpt-4-turbo' => 'GPT-4 Turbo (Legacy)',
            'gpt-4' => 'GPT-4 (Legacy)',
            'gpt-3.5-turbo' => 'GPT-3.5 Turbo (Legacy)',
        ];
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
            'max_completion_tokens' => $this->getInt($options, 'max_tokens', 4096),
            ...$this->buildSamplingParams($model, $options),
        ];

        if (isset($options['stop'])) {
            $payload['stop'] = $options['stop'];
        }

        $response = $this->sendRequest('chat/completions', $payload);

        $choices = $this->getList($response, 'choices');
        $choice = $this->asArray($choices[0] ?? []);
        $message = $this->getArray($choice, 'message');
        $usage = $this->getArray($response, 'usage');

        [$content, $thinking] = $this->extractThinkingBlocks($this->getString($message, 'content'));

        return $this->createCompletionResponse(
            content: $content,
            model: $this->getString($response, 'model', $model),
            usage: $this->createUsageStatistics(
                promptTokens: $this->getInt($usage, 'prompt_tokens'),
                completionTokens: $this->getInt($usage, 'completion_tokens'),
            ),
            finishReason: $this->getString($choice, 'finish_reason', 'stop'),
            thinking: $thinking,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param list<ToolSpec>                   $tools
     * @param array<string, mixed>             $options
     */
    public function chatCompletionWithTools(array $messages, array $tools, array $options = []): CompletionResponse
    {
        $model = $this->getString($options, 'model', $this->getDefaultModel());

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'tools' => array_map(static fn(ToolSpec $spec): array => $spec->toArray(), $tools),
            'max_completion_tokens' => $this->getInt($options, 'max_tokens', 4096),
            ...$this->buildSamplingParams($model, $options),
        ];

        if (isset($options['tool_choice'])) {
            $payload['tool_choice'] = $options['tool_choice'];
        }

        $response = $this->sendRequest('chat/completions', $payload);

        $choices = $this->getList($response, 'choices');
        $choice = $this->asArray($choices[0] ?? []);
        $message = $this->getArray($choice, 'message');
        $usage = $this->getArray($response, 'usage');

        $toolCalls    = null;
        $rawToolCalls = $this->getArray($message, 'tool_calls');
        if ($rawToolCalls !== []) {
            $toolCalls = [];
            foreach ($rawToolCalls as $tc) {
                $toolCalls[] = ToolCall::fromArray($this->asArray($tc));
            }
        }

        [$content, $thinking] = $this->extractThinkingBlocks($this->getString($message, 'content'));

        return new CompletionResponse(
            content: $content,
            model: $this->getString($response, 'model', $model),
            usage: $this->createUsageStatistics(
                promptTokens: $this->getInt($usage, 'prompt_tokens'),
                completionTokens: $this->getInt($usage, 'completion_tokens'),
            ),
            finishReason: $this->getString($choice, 'finish_reason', 'stop'),
            provider: $this->getIdentifier(),
            toolCalls: $toolCalls,
            thinking: $thinking,
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

        if (isset($options['dimensions'])) {
            $payload['dimensions'] = $this->getInt($options, 'dimensions');
        }

        $response = $this->sendRequest('embeddings', $payload);

        $data = $this->getList($response, 'data');
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
     * @param list<VisionContent>  $content
     * @param array<string, mixed> $options
     */
    public function analyzeImage(array $content, array $options = []): VisionResponse
    {
        $messages = [
            [
                'role' => 'user',
                'content' => array_map(static fn(VisionContent $vc): array => $vc->toArray(), $content),
            ],
        ];

        $systemPrompt = $this->getNullableString($options, 'system_prompt');
        if ($systemPrompt !== null) {
            array_unshift($messages, [
                'role' => 'system',
                'content' => $systemPrompt,
            ]);
        }

        $model = $this->getString($options, 'model', 'gpt-5.2');

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'max_completion_tokens' => $this->getInt($options, 'max_tokens', 4096),
        ];

        $response = $this->sendRequest('chat/completions', $payload);

        $choices = $this->getList($response, 'choices');
        $choice = $this->asArray($choices[0] ?? []);
        $message = $this->getArray($choice, 'message');
        $usage = $this->getArray($response, 'usage');

        return new VisionResponse(
            description: $this->getString($message, 'content'),
            model: $this->getString($response, 'model', $model),
            usage: $this->createUsageStatistics(
                promptTokens: $this->getInt($usage, 'prompt_tokens'),
                completionTokens: $this->getInt($usage, 'completion_tokens'),
            ),
            provider: $this->getIdentifier(),
        );
    }

    public function supportsVision(): bool
    {
        return true;
    }

    /**
     * @return array<string>
     */
    public function getSupportedImageFormats(): array
    {
        return ['png', 'jpeg', 'jpg', 'gif', 'webp'];
    }

    public function getMaxImageSize(): int
    {
        return 20 * 1024 * 1024; // 20 MB
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed>             $options
     *
     * @return Generator<int, string, mixed, void>
     */
    public function streamChatCompletion(array $messages, array $options = []): Generator
    {
        $model = $this->getString($options, 'model', $this->getDefaultModel());

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'max_completion_tokens' => $this->getInt($options, 'max_tokens', 4096),
            'stream' => true,
            ...$this->buildSamplingParams($model, $options),
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
     * Check if a model doesn't support sampling parameters
     * (temperature, top_p, frequency_penalty, presence_penalty).
     *
     * Covers: o-series reasoning models and GPT-5.x series which
     * only accept the default temperature value of 1.
     */
    private function isReasoningModel(string $model): bool
    {
        return (bool)preg_match('/^(o[1-9]|gpt-5)/', $model);
    }

    /**
     * Build sampling parameters, stripping them for reasoning models.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function buildSamplingParams(string $model, array $options): array
    {
        if ($this->isReasoningModel($model)) {
            return [];
        }

        $params = [
            'temperature' => $this->getFloat($options, 'temperature', 0.7),
        ];

        if (isset($options['top_p'])) {
            $params['top_p'] = $this->getFloat($options, 'top_p');
        }

        if (isset($options['frequency_penalty'])) {
            $params['frequency_penalty'] = $this->getFloat($options, 'frequency_penalty');
        }

        if (isset($options['presence_penalty'])) {
            $params['presence_penalty'] = $this->getFloat($options, 'presence_penalty');
        }

        return $params;
    }

    /**
     * Test the connection to OpenAI.
     *
     * Unlike getAvailableModels() which returns a static list, this method
     * makes an actual HTTP request to verify connectivity.
     *
     *
     * @throws ProviderConnectionException on connection failure
     *
     * @return array{success: bool, message: string, models?: array<string, string>}
     */
    public function testConnection(): array
    {
        // Make actual HTTP request to /models endpoint - do NOT catch exceptions
        $response = $this->sendRequest('models', [], 'GET');
        $data = $this->getList($response, 'data');

        $models = [];
        foreach ($data as $model) {
            $modelArray = $this->asArray($model);
            $id = $this->getString($modelArray, 'id');
            if ($id !== '') {
                $models[$id] = $id;
            }
        }

        return [
            'success' => true,
            'message' => sprintf('Connection successful. Found %d models.', count($models)),
            'models' => $models,
        ];
    }
}
