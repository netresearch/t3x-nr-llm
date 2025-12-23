<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Provider\Contract\StreamingCapableInterface;
use Netresearch\NrLlm\Provider\Contract\ToolCapableInterface;

/**
 * Mistral AI Provider
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
    protected array $supportedFeatures = [
        self::FEATURE_CHAT,
        self::FEATURE_COMPLETION,
        self::FEATURE_EMBEDDINGS,
        self::FEATURE_STREAMING,
        self::FEATURE_TOOLS,
    ];

    private const DEFAULT_CHAT_MODEL = 'mistral-large-latest';
    private const DEFAULT_EMBEDDING_MODEL = 'mistral-embed';

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

    public function getAvailableModels(): array
    {
        return self::MODELS;
    }

    public function chatCompletion(array $messages, array $options = []): CompletionResponse
    {
        $payload = [
            'model' => $options['model'] ?? $this->getDefaultModel(),
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 0.7,
            'max_tokens' => $options['max_tokens'] ?? 4096,
        ];

        if (isset($options['top_p'])) {
            $payload['top_p'] = $options['top_p'];
        }

        // Mistral uses 'random_seed' instead of 'seed'
        if (isset($options['seed'])) {
            $payload['random_seed'] = $options['seed'];
        }

        // Safe prompt - Mistral specific
        if (isset($options['safe_prompt'])) {
            $payload['safe_prompt'] = $options['safe_prompt'];
        }

        $response = $this->sendRequest('chat/completions', $payload);

        $choice = $response['choices'][0] ?? [];
        $usage = $response['usage'] ?? [];

        return $this->createCompletionResponse(
            content: $choice['message']['content'] ?? '',
            model: $response['model'] ?? $payload['model'],
            usage: $this->createUsageStatistics(
                promptTokens: $usage['prompt_tokens'] ?? 0,
                completionTokens: $usage['completion_tokens'] ?? 0
            ),
            finishReason: $choice['finish_reason'] ?? 'stop'
        );
    }

    public function chatCompletionWithTools(array $messages, array $tools, array $options = []): CompletionResponse
    {
        $payload = [
            'model' => $options['model'] ?? $this->getDefaultModel(),
            'messages' => $messages,
            'tools' => $tools,
            'temperature' => $options['temperature'] ?? 0.7,
            'max_tokens' => $options['max_tokens'] ?? 4096,
        ];

        if (isset($options['tool_choice'])) {
            $payload['tool_choice'] = $options['tool_choice'];
        }

        $response = $this->sendRequest('chat/completions', $payload);

        $choice = $response['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $usage = $response['usage'] ?? [];

        $toolCalls = null;
        if (isset($message['tool_calls']) && is_array($message['tool_calls'])) {
            $toolCalls = array_map(static fn($tc) => [
                'id' => $tc['id'],
                'type' => $tc['type'],
                'function' => [
                    'name' => $tc['function']['name'],
                    'arguments' => json_decode($tc['function']['arguments'], true),
                ],
            ], $message['tool_calls']);
        }

        return new CompletionResponse(
            content: $message['content'] ?? '',
            model: $response['model'] ?? $payload['model'],
            usage: $this->createUsageStatistics(
                promptTokens: $usage['prompt_tokens'] ?? 0,
                completionTokens: $usage['completion_tokens'] ?? 0
            ),
            finishReason: $choice['finish_reason'] ?? 'stop',
            provider: $this->getIdentifier(),
            toolCalls: $toolCalls
        );
    }

    public function supportsTools(): bool
    {
        return true;
    }

    public function embeddings(string|array $input, array $options = []): EmbeddingResponse
    {
        $inputs = is_array($input) ? $input : [$input];

        $payload = [
            'model' => $options['model'] ?? self::DEFAULT_EMBEDDING_MODEL,
            'input' => $inputs,
        ];

        // Mistral supports encoding_format
        if (isset($options['encoding_format'])) {
            $payload['encoding_format'] = $options['encoding_format'];
        }

        $response = $this->sendRequest('embeddings', $payload);

        $embeddings = array_map(
            static fn($item) => $item['embedding'],
            $response['data'] ?? []
        );

        $usage = $response['usage'] ?? [];

        return $this->createEmbeddingResponse(
            embeddings: $embeddings,
            model: $response['model'] ?? $payload['model'],
            usage: $this->createUsageStatistics(
                promptTokens: $usage['prompt_tokens'] ?? 0,
                completionTokens: 0
            )
        );
    }

    public function streamChatCompletion(array $messages, array $options = []): \Generator
    {
        $payload = [
            'model' => $options['model'] ?? $this->getDefaultModel(),
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 0.7,
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'stream' => true,
        ];

        $url = rtrim($this->baseUrl, '/') . '/chat/completions';

        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $this->apiKey)
            ->withHeader('Accept', 'text/event-stream');

        $body = $this->streamFactory->createStream(json_encode($payload, JSON_THROW_ON_ERROR));
        $request = $request->withBody($body);

        $response = $this->httpClient->sendRequest($request);
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
                        $json = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                        $content = $json['choices'][0]['delta']['content'] ?? '';
                        if ($content !== '') {
                            yield $content;
                        }
                    } catch (\JsonException) {
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
