<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider;

use Generator;
use JsonException;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Provider\Contract\StreamingCapableInterface;
use Netresearch\NrLlm\Provider\Contract\ToolCapableInterface;
use Netresearch\NrLlm\Provider\Contract\VisionCapableInterface;
use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;

final class ClaudeProvider extends AbstractProvider implements
    VisionCapableInterface,
    StreamingCapableInterface,
    ToolCapableInterface
{
    protected array $supportedFeatures = [
        self::FEATURE_CHAT,
        self::FEATURE_COMPLETION,
        self::FEATURE_VISION,
        self::FEATURE_STREAMING,
        self::FEATURE_TOOLS,
    ];

    private const DEFAULT_MODEL = 'claude-sonnet-4-5-20250929';
    private const API_VERSION = '2023-06-01';

    public function getName(): string
    {
        return 'Anthropic Claude';
    }

    public function getIdentifier(): string
    {
        return 'claude';
    }

    protected function getDefaultBaseUrl(): string
    {
        return 'https://api.anthropic.com/v1';
    }

    public function getDefaultModel(): string
    {
        return $this->defaultModel !== '' ? $this->defaultModel : self::DEFAULT_MODEL;
    }

    public function getAvailableModels(): array
    {
        return [
            // Claude 4.5 Series (Latest)
            'claude-opus-4-5-20251124' => 'Claude Opus 4.5 (Most Capable)',
            'claude-sonnet-4-5-20250929' => 'Claude Sonnet 4.5 (Recommended)',
            // Claude 4.1 Series
            'claude-opus-4-1-20250805' => 'Claude Opus 4.1',
            // Claude 4 Series
            'claude-opus-4-20250514' => 'Claude Opus 4',
            'claude-sonnet-4-20250514' => 'Claude Sonnet 4',
            // Claude 3.5 Series (Legacy)
            'claude-3-5-sonnet-20241022' => 'Claude 3.5 Sonnet (Legacy)',
            'claude-3-5-haiku-20241022' => 'Claude 3.5 Haiku (Legacy)',
        ];
    }

    protected function addProviderSpecificHeaders(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\RequestInterface
    {
        return $request
            ->withHeader('x-api-key', $this->apiKey)
            ->withHeader('anthropic-version', self::API_VERSION)
            ->withoutHeader('Authorization');
    }

    public function chatCompletion(array $messages, array $options = []): CompletionResponse
    {
        $systemMessage = null;
        $filteredMessages = [];

        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                $systemMessage = $message['content'];
            } else {
                $filteredMessages[] = $message;
            }
        }

        $payload = [
            'model' => $options['model'] ?? $this->getDefaultModel(),
            'messages' => $filteredMessages,
            'max_tokens' => $options['max_tokens'] ?? 4096,
        ];

        if ($systemMessage !== null) {
            $payload['system'] = $systemMessage;
        }

        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        }

        if (isset($options['top_p'])) {
            $payload['top_p'] = $options['top_p'];
        }

        if (isset($options['stop_sequences'])) {
            $payload['stop_sequences'] = $options['stop_sequences'];
        }

        $response = $this->sendRequest('messages', $payload);

        $content = '';
        foreach ($response['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $content .= $block['text'];
            }
        }

        $usage = $response['usage'] ?? [];

        return $this->createCompletionResponse(
            content: $content,
            model: $response['model'] ?? $payload['model'],
            usage: $this->createUsageStatistics(
                promptTokens: $usage['input_tokens'] ?? 0,
                completionTokens: $usage['output_tokens'] ?? 0,
            ),
            finishReason: $this->mapStopReason($response['stop_reason'] ?? 'end_turn'),
        );
    }

    public function chatCompletionWithTools(array $messages, array $tools, array $options = []): CompletionResponse
    {
        $systemMessage = null;
        $filteredMessages = [];

        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                $systemMessage = $message['content'];
            } else {
                $filteredMessages[] = $message;
            }
        }

        // Convert OpenAI tool format to Claude format
        $claudeTools = array_map(static fn(array $tool): array => [
            'name' => $tool['function']['name'],
            'description' => $tool['function']['description'],
            'input_schema' => $tool['function']['parameters'],
        ], $tools);

        $payload = [
            'model' => $options['model'] ?? $this->getDefaultModel(),
            'messages' => $filteredMessages,
            'tools' => $claudeTools,
            'max_tokens' => $options['max_tokens'] ?? 4096,
        ];

        if ($systemMessage !== null) {
            $payload['system'] = $systemMessage;
        }

        if (isset($options['tool_choice'])) {
            $payload['tool_choice'] = $this->mapToolChoice($options['tool_choice']);
        }

        $response = $this->sendRequest('messages', $payload);

        $content = '';
        $toolCalls = [];

        foreach ($response['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $content .= $block['text'];
            } elseif ($block['type'] === 'tool_use') {
                $toolCalls[] = [
                    'id' => $block['id'],
                    'type' => 'function',
                    'function' => [
                        'name' => $block['name'],
                        'arguments' => $block['input'],
                    ],
                ];
            }
        }

        $usage = $response['usage'] ?? [];

        return new CompletionResponse(
            content: $content,
            model: $response['model'] ?? $payload['model'],
            usage: $this->createUsageStatistics(
                promptTokens: $usage['input_tokens'] ?? 0,
                completionTokens: $usage['output_tokens'] ?? 0,
            ),
            finishReason: $this->mapStopReason($response['stop_reason'] ?? 'end_turn'),
            provider: $this->getIdentifier(),
            toolCalls: $toolCalls !== [] ? $toolCalls : null,
        );
    }

    public function supportsTools(): bool
    {
        return true;
    }

    public function embeddings(string|array $input, array $options = []): EmbeddingResponse
    {
        throw new UnsupportedFeatureException(
            'Anthropic Claude does not support embeddings. Use OpenAI or a dedicated embedding provider.',
        );
    }

    public function analyzeImage(array $content, array $options = []): VisionResponse
    {
        // Convert content array to Claude's vision format
        $claudeContent = [];

        foreach ($content as $item) {
            if ($item['type'] === 'text') {
                $claudeContent[] = [
                    'type' => 'text',
                    'text' => $item['text'],
                ];
            } elseif ($item['type'] === 'image_url') {
                $imageUrl = $item['image_url']['url'];

                // Handle base64 data URLs
                if (str_starts_with($imageUrl, 'data:')) {
                    preg_match('/^data:(image\/\w+);base64,(.+)$/', $imageUrl, $matches);
                    $claudeContent[] = [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $matches[1] ?? 'image/jpeg',
                            'data' => $matches[2] ?? '',
                        ],
                    ];
                } else {
                    // For URLs, Claude requires base64 encoding
                    $claudeContent[] = [
                        'type' => 'image',
                        'source' => [
                            'type' => 'url',
                            'url' => $imageUrl,
                        ],
                    ];
                }
            }
        }

        $messages = [
            [
                'role' => 'user',
                'content' => $claudeContent,
            ],
        ];

        $payload = [
            'model' => $options['model'] ?? 'claude-sonnet-4-5-20250929',
            'messages' => $messages,
            'max_tokens' => $options['max_tokens'] ?? 4096,
        ];

        if (isset($options['system_prompt'])) {
            $payload['system'] = $options['system_prompt'];
        }

        $response = $this->sendRequest('messages', $payload);

        $description = '';
        foreach ($response['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $description .= $block['text'];
            }
        }

        $usage = $response['usage'] ?? [];

        return new VisionResponse(
            description: $description,
            model: $response['model'] ?? $payload['model'],
            usage: $this->createUsageStatistics(
                promptTokens: $usage['input_tokens'] ?? 0,
                completionTokens: $usage['output_tokens'] ?? 0,
            ),
            provider: $this->getIdentifier(),
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

    public function streamChatCompletion(array $messages, array $options = []): Generator
    {
        $systemMessage = null;
        $filteredMessages = [];

        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                $systemMessage = $message['content'];
            } else {
                $filteredMessages[] = $message;
            }
        }

        $payload = [
            'model' => $options['model'] ?? $this->getDefaultModel(),
            'messages' => $filteredMessages,
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'stream' => true,
        ];

        if ($systemMessage !== null) {
            $payload['system'] = $systemMessage;
        }

        $url = rtrim($this->baseUrl, '/') . '/messages';

        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('x-api-key', $this->apiKey)
            ->withHeader('anthropic-version', self::API_VERSION)
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

                    try {
                        $json = json_decode($data, true, 512, JSON_THROW_ON_ERROR);

                        if ($json['type'] === 'content_block_delta') {
                            $delta = $json['delta'] ?? [];
                            if ($delta['type'] === 'text_delta') {
                                yield $delta['text'] ?? '';
                            }
                        } elseif ($json['type'] === 'message_stop') {
                            return;
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

    private function mapStopReason(string $reason): string
    {
        return match ($reason) {
            'end_turn' => 'stop',
            'max_tokens' => 'length',
            'stop_sequence' => 'stop',
            'tool_use' => 'tool_calls',
            default => $reason,
        };
    }

    /**
     * @return array<string, string>
     */
    private function mapToolChoice(mixed $choice): array
    {
        if (is_string($choice)) {
            return match ($choice) {
                'auto' => ['type' => 'auto'],
                'none' => ['type' => 'none'],
                'required' => ['type' => 'any'],
                default => ['type' => 'tool', 'name' => $choice],
            };
        }

        return is_array($choice) ? $choice : ['type' => 'auto'];
    }
}
