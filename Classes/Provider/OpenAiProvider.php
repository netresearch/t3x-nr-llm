<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Provider\Contract\StreamingCapableInterface;
use Netresearch\NrLlm\Provider\Contract\ToolCapableInterface;
use Netresearch\NrLlm\Provider\Contract\VisionCapableInterface;
use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;

final class OpenAiProvider extends AbstractProvider implements
    VisionCapableInterface,
    StreamingCapableInterface,
    ToolCapableInterface
{
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

    public function getAvailableModels(): array
    {
        return [
            // GPT-5.2 Series (December 2025 - Current)
            'gpt-5.2' => 'GPT-5.2 Thinking (Current)',
            'gpt-5.2-pro' => 'GPT-5.2 Pro (Most Capable)',
            'gpt-5.2-instant' => 'GPT-5.2 Instant (Fast)',
            // Reasoning Models
            'o3' => 'O3 (Advanced Reasoning)',
            'o4-mini' => 'O4 Mini (Reasoning)',
            // Legacy Models
            'gpt-5' => 'GPT-5 (Legacy)',
            'gpt-4.1' => 'GPT-4.1 (Legacy)',
        ];
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

        if (isset($options['frequency_penalty'])) {
            $payload['frequency_penalty'] = $options['frequency_penalty'];
        }

        if (isset($options['presence_penalty'])) {
            $payload['presence_penalty'] = $options['presence_penalty'];
        }

        if (isset($options['stop'])) {
            $payload['stop'] = $options['stop'];
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

        if (isset($options['dimensions'])) {
            $payload['dimensions'] = $options['dimensions'];
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

    public function analyzeImage(array $content, array $options = []): VisionResponse
    {
        $messages = [
            [
                'role' => 'user',
                'content' => $content,
            ],
        ];

        if (isset($options['system_prompt'])) {
            array_unshift($messages, [
                'role' => 'system',
                'content' => $options['system_prompt'],
            ]);
        }

        $payload = [
            'model' => $options['model'] ?? 'gpt-5.2',
            'messages' => $messages,
            'max_tokens' => $options['max_tokens'] ?? 4096,
        ];

        $response = $this->sendRequest('chat/completions', $payload);

        $choice = $response['choices'][0] ?? [];
        $usage = $response['usage'] ?? [];

        return new VisionResponse(
            description: $choice['message']['content'] ?? '',
            model: $response['model'] ?? $payload['model'],
            usage: $this->createUsageStatistics(
                promptTokens: $usage['prompt_tokens'] ?? 0,
                completionTokens: $usage['completion_tokens'] ?? 0
            ),
            provider: $this->getIdentifier()
        );
    }

    public function supportsVision(): bool
    {
        return true;
    }

    public function getSupportedImageFormats(): array
    {
        return ['png', 'jpeg', 'jpg', 'gif', 'webp'];
    }

    public function getMaxImageSize(): int
    {
        return 20 * 1024 * 1024; // 20 MB
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
}
