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
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Provider\Contract\StreamingCapableInterface;
use Netresearch\NrLlm\Provider\Contract\ToolCapableInterface;
use Netresearch\NrLlm\Provider\Contract\VisionCapableInterface;
use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;
use Netresearch\NrVault\Http\SecretPlacement;
use Psr\Http\Message\RequestInterface;

final class ClaudeProvider extends AbstractProvider implements
    VisionCapableInterface,
    StreamingCapableInterface,
    ToolCapableInterface
{
    /** @var array<string> */
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

    protected function getSecretPlacement(): SecretPlacement
    {
        return SecretPlacement::Header;
    }

    /**
     * @return array{headerName: string, reason: string}
     */
    protected function getSecretPlacementOptions(): array
    {
        return [
            'headerName' => 'x-api-key',
            'reason' => sprintf('LLM API call to %s', $this->getName()),
        ];
    }

    public function getDefaultModel(): string
    {
        return $this->defaultModel !== '' ? $this->defaultModel : self::DEFAULT_MODEL;
    }

    /**
     * @return array<string, string>
     */
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

    protected function addProviderSpecificHeaders(RequestInterface $request): RequestInterface
    {
        return $request
            ->withHeader('anthropic-version', self::API_VERSION);
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed>             $options
     */
    public function chatCompletion(array $messages, array $options = []): CompletionResponse
    {
        $systemMessage = null;
        $filteredMessages = [];

        foreach ($messages as $message) {
            $msgArray = $this->asArray($message);
            $role = $this->getString($msgArray, 'role');
            if ($role === 'system') {
                $systemMessage = $this->getString($msgArray, 'content');
            } else {
                $filteredMessages[] = $message;
            }
        }

        $model = $this->getString($options, 'model', $this->getDefaultModel());

        $payload = [
            'model' => $model,
            'messages' => $filteredMessages,
            'max_tokens' => $this->getInt($options, 'max_tokens', 4096),
        ];

        if ($systemMessage !== null) {
            $payload['system'] = $systemMessage;
        }

        if (isset($options['temperature'])) {
            $payload['temperature'] = $this->getFloat($options, 'temperature');
        }

        if (isset($options['top_p'])) {
            $payload['top_p'] = $this->getFloat($options, 'top_p');
        }

        if (isset($options['stop_sequences'])) {
            $payload['stop_sequences'] = $options['stop_sequences'];
        }

        $response = $this->sendRequest('messages', $payload);

        $content = '';
        $contentBlocks = $this->getList($response, 'content');
        foreach ($contentBlocks as $block) {
            $blockArray = $this->asArray($block);
            if ($this->getString($blockArray, 'type') === 'text') {
                $content .= $this->getString($blockArray, 'text');
            }
        }

        $usage = $this->getArray($response, 'usage');

        return $this->createCompletionResponse(
            content: $content,
            model: $this->getString($response, 'model', $model),
            usage: $this->createUsageStatistics(
                promptTokens: $this->getInt($usage, 'input_tokens'),
                completionTokens: $this->getInt($usage, 'output_tokens'),
            ),
            finishReason: $this->mapStopReason($this->getString($response, 'stop_reason', 'end_turn')),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tools
     * @param array<string, mixed>             $options
     */
    public function chatCompletionWithTools(array $messages, array $tools, array $options = []): CompletionResponse
    {
        $systemMessage = null;
        $filteredMessages = [];

        foreach ($messages as $message) {
            $msgArray = $this->asArray($message);
            $role = $this->getString($msgArray, 'role');
            if ($role === 'system') {
                $systemMessage = $this->getString($msgArray, 'content');
            } else {
                $filteredMessages[] = $message;
            }
        }

        // Convert OpenAI tool format to Claude format
        $claudeTools = [];
        foreach ($tools as $tool) {
            $toolArray = $this->asArray($tool);
            $function = $this->getArray($toolArray, 'function');
            $claudeTools[] = [
                'name' => $this->getString($function, 'name'),
                'description' => $this->getString($function, 'description'),
                'input_schema' => $this->getArray($function, 'parameters'),
            ];
        }

        $model = $this->getString($options, 'model', $this->getDefaultModel());

        $payload = [
            'model' => $model,
            'messages' => $filteredMessages,
            'tools' => $claudeTools,
            'max_tokens' => $this->getInt($options, 'max_tokens', 4096),
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

        $contentBlocks = $this->getList($response, 'content');
        foreach ($contentBlocks as $block) {
            $blockArray = $this->asArray($block);
            $blockType = $this->getString($blockArray, 'type');
            if ($blockType === 'text') {
                $content .= $this->getString($blockArray, 'text');
            } elseif ($blockType === 'tool_use') {
                $toolCalls[] = [
                    'id' => $this->getString($blockArray, 'id'),
                    'type' => 'function',
                    'function' => [
                        'name' => $this->getString($blockArray, 'name'),
                        'arguments' => $this->getArray($blockArray, 'input'),
                    ],
                ];
            }
        }

        $usage = $this->getArray($response, 'usage');

        return new CompletionResponse(
            content: $content,
            model: $this->getString($response, 'model', $model),
            usage: $this->createUsageStatistics(
                promptTokens: $this->getInt($usage, 'input_tokens'),
                completionTokens: $this->getInt($usage, 'output_tokens'),
            ),
            finishReason: $this->mapStopReason($this->getString($response, 'stop_reason', 'end_turn')),
            provider: $this->getIdentifier(),
            toolCalls: $toolCalls !== [] ? $toolCalls : null,
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
        throw new UnsupportedFeatureException(
            'Anthropic Claude does not support embeddings. Use OpenAI or a dedicated embedding provider.',
            8109610521,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $content
     * @param array<string, mixed>             $options
     */
    public function analyzeImage(array $content, array $options = []): VisionResponse
    {
        // Convert content array to Claude's vision format
        $claudeContent = [];

        foreach ($content as $item) {
            $itemArray = $this->asArray($item);
            $itemType = $this->getString($itemArray, 'type');

            if ($itemType === 'text') {
                $claudeContent[] = [
                    'type' => 'text',
                    'text' => $this->getString($itemArray, 'text'),
                ];
            } elseif ($itemType === 'image_url') {
                $imageUrl = $this->getNestedString($itemArray, 'image_url.url');

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

        $model = $this->getString($options, 'model', 'claude-sonnet-4-5-20250929');

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $this->getInt($options, 'max_tokens', 4096),
        ];

        $systemPrompt = $this->getNullableString($options, 'system_prompt');
        if ($systemPrompt !== null) {
            $payload['system'] = $systemPrompt;
        }

        $response = $this->sendRequest('messages', $payload);

        $description = '';
        $contentBlocks = $this->getList($response, 'content');
        foreach ($contentBlocks as $block) {
            $blockArray = $this->asArray($block);
            if ($this->getString($blockArray, 'type') === 'text') {
                $description .= $this->getString($blockArray, 'text');
            }
        }

        $usage = $this->getArray($response, 'usage');

        return new VisionResponse(
            description: $description,
            model: $this->getString($response, 'model', $model),
            usage: $this->createUsageStatistics(
                promptTokens: $this->getInt($usage, 'input_tokens'),
                completionTokens: $this->getInt($usage, 'output_tokens'),
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
        $systemMessage = null;
        $filteredMessages = [];

        foreach ($messages as $message) {
            $msgArray = $this->asArray($message);
            $role = $this->getString($msgArray, 'role');
            if ($role === 'system') {
                $systemMessage = $this->getString($msgArray, 'content');
            } else {
                $filteredMessages[] = $message;
            }
        }

        $payload = [
            'model' => $this->getString($options, 'model', $this->getDefaultModel()),
            'messages' => $filteredMessages,
            'max_tokens' => $this->getInt($options, 'max_tokens', 4096),
            'stream' => true,
        ];

        if ($systemMessage !== null) {
            $payload['system'] = $systemMessage;
        }

        $url = rtrim($this->baseUrl, '/') . '/messages';

        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('anthropic-version', self::API_VERSION)
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

                    try {
                        $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                        if (is_array($decoded)) {
                            $json = $this->asArray($decoded);
                            $type = $this->getString($json, 'type');
                            if ($type === 'content_block_delta') {
                                $delta = $this->getArray($json, 'delta');
                                if ($this->getString($delta, 'type') === 'text_delta') {
                                    yield $this->getString($delta, 'text');
                                }
                            } elseif ($type === 'message_stop') {
                                return;
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

        if (is_array($choice)) {
            /** @var array<string, string> $choice */
            return $choice;
        }

        return ['type' => 'auto'];
    }
}
