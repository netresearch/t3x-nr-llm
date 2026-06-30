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
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Domain\ValueObject\VisionContent;
use Netresearch\NrLlm\Provider\Contract\DocumentCapableInterface;
use Netresearch\NrLlm\Provider\Contract\StreamingCapableInterface;
use Netresearch\NrLlm\Provider\Contract\ToolCapableInterface;
use Netresearch\NrLlm\Provider\Contract\VisionCapableInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrVault\Http\SecretPlacement;

#[AsLlmProvider(priority: 80)]
final class GeminiProvider extends AbstractProvider implements
    VisionCapableInterface,
    DocumentCapableInterface,
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

    private const DEFAULT_MODEL = 'gemini-3-flash-preview';
    private const EMBEDDING_MODEL = 'text-embedding-004';

    public function getName(): string
    {
        return 'Google Gemini';
    }

    public function getIdentifier(): string
    {
        return 'gemini';
    }

    protected function getDefaultBaseUrl(): string
    {
        return 'https://generativelanguage.googleapis.com/v1beta';
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
            // Gemini 3 Series (Latest - December 2025)
            'gemini-3-flash-preview' => 'Gemini 3 Flash (Latest)',
            'gemini-3-pro' => 'Gemini 3 Pro (Most Capable)',
            // Gemini 2.5 Series
            'gemini-2.5-flash' => 'Gemini 2.5 Flash',
            'gemini-2.5-pro' => 'Gemini 2.5 Pro',
            'gemini-2.5-flash-lite' => 'Gemini 2.5 Flash Lite (Fast)',
            // Gemini 2.0 Series (Legacy)
            'gemini-2.0-flash' => 'Gemini 2.0 Flash (Legacy)',
        ];
    }

    /**
     * Test the connection to Google Gemini.
     *
     * getAvailableModels() returns a static list and never touches the
     * network, so the AbstractProvider default would report success even
     * when the endpoint is unreachable or the key is invalid. Make a real
     * lightweight GET to the models endpoint (the key travels in the
     * x-goog-api-key header) and let any failure surface as the typed
     * exception sendRequest() raises.
     *
     * @throws ProviderConnectionException on connection failure
     *
     * @return array{success: bool, message: string, models?: array<string, string>}
     */
    public function testConnection(): array
    {
        // Real HTTP request to the models endpoint — do NOT catch exceptions.
        $response = $this->sendRequest('models', [], 'GET');
        $list = $this->getList($response, 'models');

        $models = [];
        foreach ($list as $model) {
            $modelArray = $this->asArray($model);
            // Gemini reports names as `models/gemini-...`; strip the prefix.
            $name = $this->getString($modelArray, 'name');
            if ($name !== '') {
                $id = str_starts_with($name, 'models/') ? substr($name, 7) : $name;
                $models[$id] = $id;
            }
        }

        return [
            'success' => true,
            'message' => sprintf('Connection successful. Found %d models.', count($models)),
            'models' => $models,
        ];
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
        $this->assertValidModelId($model);
        $geminiContents = $this->convertToGeminiFormat($messages);

        $payload = [
            'contents' => $geminiContents['contents'],
            'generationConfig' => [
                'temperature' => $this->getFloat($options, 'temperature', 0.7),
                'maxOutputTokens' => $this->getInt($options, 'max_tokens', 4096),
            ],
        ];

        if (isset($geminiContents['systemInstruction'])) {
            $payload['systemInstruction'] = $geminiContents['systemInstruction'];
        }

        if (isset($options['top_p'])) {
            $payload['generationConfig']['topP'] = $this->getFloat($options, 'top_p');
        }

        if (isset($options['top_k'])) {
            $payload['generationConfig']['topK'] = $this->getInt($options, 'top_k');
        }

        if (isset($options['stop_sequences'])) {
            $payload['generationConfig']['stopSequences'] = $options['stop_sequences'];
        }

        $response = $this->sendRequest(
            "models/{$model}:generateContent",
            $payload,
        );

        $candidates = $this->getList($response, 'candidates');
        $candidate = $this->asArray($candidates[0] ?? []);
        $contentObj = $this->getArray($candidate, 'content');
        $parts = $this->getList($contentObj, 'parts');
        $firstPart = $this->asArray($parts[0] ?? []);
        $rawContent = $this->getString($firstPart, 'text');
        [$content, $thinking] = $this->extractThinkingBlocks($rawContent);
        $usage = $this->getArray($response, 'usageMetadata');

        return $this->createCompletionResponse(
            content: $content,
            model: $model,
            usage: $this->createUsageStatistics(
                promptTokens: $this->getInt($usage, 'promptTokenCount'),
                completionTokens: $this->getInt($usage, 'candidatesTokenCount'),
            ),
            finishReason: $this->mapFinishReason($this->getString($candidate, 'finishReason', 'STOP')),
            thinking: $thinking,
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
        $this->assertValidModelId($model);
        $geminiContents = $this->convertToGeminiFormat($messages);

        // Convert OpenAI-shaped ToolSpec into Gemini's `functionDeclarations` format.
        $geminiTools = [
            'functionDeclarations' => array_map(static fn(ToolSpec $spec): array => [
                'name'        => $spec->name,
                'description' => $spec->description,
                'parameters'  => $spec->parameters,
            ], $tools),
        ];

        $payload = [
            'contents' => $geminiContents['contents'],
            'tools' => [$geminiTools],
            'generationConfig' => [
                'temperature' => $this->getFloat($options, 'temperature', 0.7),
                'maxOutputTokens' => $this->getInt($options, 'max_tokens', 4096),
            ],
        ];

        if (isset($geminiContents['systemInstruction'])) {
            $payload['systemInstruction'] = $geminiContents['systemInstruction'];
        }

        $response = $this->sendRequest(
            "models/{$model}:generateContent",
            $payload,
        );

        $candidates = $this->getList($response, 'candidates');
        $candidate = $this->asArray($candidates[0] ?? []);
        $contentObj = $this->getArray($candidate, 'content');
        $parts = $this->getList($contentObj, 'parts');

        $rawContent = '';
        $toolCalls = [];

        foreach ($parts as $part) {
            $partArray = $this->asArray($part);
            $text = $this->getNullableString($partArray, 'text');
            if ($text !== null) {
                $rawContent .= $text;
            }

            $functionCall = $this->getArray($partArray, 'functionCall');
            if ($functionCall !== []) {
                // Gemini does not return tool-call IDs; synthesise a
                // conversation-unique one. A per-response part index would
                // reset to 0 each loop turn and collide across turns in
                // buildToolCallIdToName(); uniqid(more_entropy: true) is
                // collision-free even for multiple calls in one response.
                $toolCalls[] = ToolCall::function(
                    id: uniqid('call_', true),
                    name: $this->getString($functionCall, 'name'),
                    arguments: $this->getArray($functionCall, 'args'),
                );
            }
        }

        [$content, $thinking] = $this->extractThinkingBlocks($rawContent);
        $usage = $this->getArray($response, 'usageMetadata');

        return new CompletionResponse(
            content: $content,
            model: $model,
            usage: $this->createUsageStatistics(
                promptTokens: $this->getInt($usage, 'promptTokenCount'),
                completionTokens: $this->getInt($usage, 'candidatesTokenCount'),
            ),
            finishReason: $this->mapFinishReason($this->getString($candidate, 'finishReason', 'STOP')),
            provider: $this->getIdentifier(),
            toolCalls: $toolCalls !== [] ? $toolCalls : null,
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
        $model = $this->getString($options, 'model', self::EMBEDDING_MODEL);
        $this->assertValidModelId($model);

        /** @var array<int, array<int, float>> $embeddings */
        $embeddings = [];
        $totalTokens = 0.0;

        foreach ($inputs as $text) {
            $textString = $this->asString($text);
            $payload = [
                'model' => "models/{$model}",
                'content' => [
                    'parts' => [['text' => $textString]],
                ],
            ];

            $response = $this->sendRequest(
                "models/{$model}:embedContent",
                $payload,
            );

            $embeddingData = $this->getArray($response, 'embedding');
            $values = $this->getArray($embeddingData, 'values');
            /** @var list<float> $floatValues */
            $floatValues = array_values(array_map(fn($v): float => $this->asFloat($v), $values));
            $embeddings[] = $floatValues;
            $totalTokens += strlen($textString) / 4; // Rough token estimate
        }

        return $this->createEmbeddingResponse(
            embeddings: $embeddings,
            model: $model,
            usage: $this->createUsageStatistics(
                promptTokens: (int)$totalTokens,
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
        $model = $this->getString($options, 'model', 'gemini-3-flash-preview');
        $this->assertValidModelId($model);

        $parts = [];
        foreach ($content as $item) {
            if ($item->isText()) {
                // VisionContent::__construct enforces non-empty text for TYPE_TEXT,
                // so $item->text is guaranteed to be a non-empty string here.
                $parts[] = ['text' => $item->text];
                continue;
            }

            if (!$item->isImage()) {
                continue;
            }

            $imageUrl = $item->imageUrl ?? '';
            // analyzeImage() is image-only — skip data URIs whose MIME type
            // isn't `image/*` so non-image documents (e.g. `data:application/pdf`)
            // never reach the vision endpoint.
            if (str_starts_with($imageUrl, 'data:')) {
                if (
                    preg_match('/^data:([^;]+);base64,(.+)$/', $imageUrl, $matches) === 1
                    && str_starts_with($matches[1], 'image/')
                ) {
                    $parts[] = [
                        'inlineData' => [
                            'mimeType' => $matches[1],
                            'data'     => $matches[2],
                        ],
                    ];
                }
            } else {
                $parts[] = [
                    'fileData' => [
                        'mimeType' => 'image/jpeg',
                        'fileUri'  => $imageUrl,
                    ],
                ];
            }
        }

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => $parts,
                ],
            ],
            'generationConfig' => [
                'maxOutputTokens' => $this->getInt($options, 'max_tokens', 4096),
            ],
        ];

        $systemPrompt = $this->getNullableString($options, 'system_prompt');
        if ($systemPrompt !== null) {
            $payload['systemInstruction'] = [
                'parts' => [['text' => $systemPrompt]],
            ];
        }

        $response = $this->sendRequest(
            "models/{$model}:generateContent",
            $payload,
        );

        $candidates = $this->getList($response, 'candidates');
        $candidate = $this->asArray($candidates[0] ?? []);
        $contentObj = $this->getArray($candidate, 'content');
        $responseParts = $this->getList($contentObj, 'parts');
        $firstPart = $this->asArray($responseParts[0] ?? []);
        $description = $this->getString($firstPart, 'text');
        $usage = $this->getArray($response, 'usageMetadata');

        return new VisionResponse(
            description: $description,
            model: $model,
            usage: $this->createUsageStatistics(
                promptTokens: $this->getInt($usage, 'promptTokenCount'),
                completionTokens: $this->getInt($usage, 'candidatesTokenCount'),
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
        return ['png', 'jpeg', 'jpg', 'gif', 'webp', 'heic', 'heif'];
    }

    public function getMaxImageSize(): int
    {
        return 20 * 1024 * 1024; // 20 MB
    }

    public function supportsDocuments(): bool
    {
        return true;
    }

    /**
     * @return array<string>
     */
    public function getSupportedDocumentFormats(): array
    {
        return ['pdf'];
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
        $this->assertValidModelId($model);
        $geminiContents = $this->convertToGeminiFormat($messages);

        $payload = [
            'contents' => $geminiContents['contents'],
            'generationConfig' => [
                'temperature' => $this->getFloat($options, 'temperature', 0.7),
                'maxOutputTokens' => $this->getInt($options, 'max_tokens', 4096),
            ],
        ];

        if (isset($geminiContents['systemInstruction'])) {
            $payload['systemInstruction'] = $geminiContents['systemInstruction'];
        }

        $url = rtrim($this->baseUrl, '/') . "/models/{$model}:streamGenerateContent?alt=sse";

        // The vault HTTP client injects the API key as the x-goog-api-key header
        // (SecretPlacement::Header) — the key is never decrypted into provider
        // memory here.
        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'text/event-stream');

        $body = $this->streamFactory->createStream(json_encode($payload, JSON_THROW_ON_ERROR));
        $request = $request->withBody($body);

        $response = $this->getHttpClient()->sendRequest($request);
        $this->assertStreamingResponseOk($response, sprintf('models/%s:streamGenerateContent', $model));
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

                $text = $this->extractStreamText(substr($line, 6));
                if ($text !== null) {
                    yield $text;
                }
            }
        }
    }

    /**
     * Decode a single SSE `data:` payload and extract the streamed text chunk.
     * Returns null for malformed JSON, non-array payloads, or empty text.
     */
    private function extractStreamText(string $data): ?string
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
        $candidates = $this->getList($json, 'candidates');
        $candidate = $this->asArray($candidates[0] ?? []);
        $contentObj = $this->getArray($candidate, 'content');
        $parts = $this->getList($contentObj, 'parts');
        $firstPart = $this->asArray($parts[0] ?? []);
        $text = $this->getString($firstPart, 'text');

        return $text !== '' ? $text : null;
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    protected function getSecretPlacement(): SecretPlacement
    {
        // Inject the API key as the x-goog-api-key header via the vault HTTP
        // client. The key never enters the URL (no ?key= leak to logs/referrer)
        // and is never decrypted into this provider's memory.
        return SecretPlacement::Header;
    }

    /**
     * @return array{headerName: string, reason: string}
     */
    protected function getSecretPlacementOptions(): array
    {
        return [
            'headerName' => 'x-goog-api-key',
            'reason' => sprintf('LLM API call to %s', $this->getName()),
        ];
    }

    /**
     * @param list<ChatMessage|array<string, mixed>> $messages
     *
     * @return array{contents: array<int, array<string, mixed>>, systemInstruction?: array<string, mixed>}
     */
    private function convertToGeminiFormat(array $messages): array
    {
        $contents = [];
        $systemInstruction = null;

        $toolCallIdToName = $this->buildToolCallIdToName($messages);

        foreach ($messages as $message) {
            $msgArray = $this->asArray($message);
            $role = $this->getString($msgArray, 'role');

            if ($role === 'system') {
                $content = $msgArray['content'] ?? '';
                $systemInstruction = [
                    'parts' => [['text' => is_string($content) ? $content : '']],
                ];

                continue;
            }

            $contents[] = $this->convertMessage($role, $msgArray, $toolCallIdToName);
        }

        $result = ['contents' => $contents];
        if ($systemInstruction !== null) {
            $result['systemInstruction'] = $systemInstruction;
        }

        return $result;
    }

    /**
     * Pre-scan: build a tool_call_id → function_name mapping from assistant tool_calls.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     *
     * @return array<string, string>
     */
    private function buildToolCallIdToName(array $messages): array
    {
        $toolCallIdToName = [];
        foreach ($messages as $msg) {
            $m = $this->asArray($msg);
            if (($m['role'] ?? '') === 'assistant' && isset($m['tool_calls']) && is_array($m['tool_calls'])) {
                foreach ($m['tool_calls'] as $tc) {
                    $tcArray = $this->asArray($tc);
                    $function = $this->getArray($tcArray, 'function');
                    $id = $this->getString($tcArray, 'id');
                    if ($id !== '') {
                        $toolCallIdToName[$id] = $this->getString($function, 'name');
                    }
                }
            }
        }

        return $toolCallIdToName;
    }

    /**
     * Convert a single non-system message to its Gemini `contents` entry.
     *
     * @param array<string, mixed>  $msgArray
     * @param array<string, string> $toolCallIdToName
     *
     * @return array<string, mixed>
     */
    private function convertMessage(string $role, array $msgArray, array $toolCallIdToName): array
    {
        // Tool result messages: convert to Gemini functionResponse format
        if ($role === 'tool') {
            return $this->convertToolMessage($msgArray, $toolCallIdToName);
        }

        // Assistant with tool_calls: convert to Gemini functionCall format
        if ($role === 'assistant' && isset($msgArray['tool_calls']) && is_array($msgArray['tool_calls'])) {
            return $this->convertAssistantToolCalls($msgArray);
        }

        $content = $msgArray['content'] ?? '';
        $geminiRole = $role === 'assistant' ? 'model' : 'user';

        if (is_array($content)) {
            return [
                'role' => $geminiRole,
                'parts' => $this->convertMultimodalToParts($content),
            ];
        }

        return [
            'role' => $geminiRole,
            'parts' => [['text' => is_string($content) ? $content : $this->getString($msgArray, 'content')]],
        ];
    }

    /**
     * @param array<string, mixed>  $msgArray
     * @param array<string, string> $toolCallIdToName
     *
     * @return array<string, mixed>
     */
    private function convertToolMessage(array $msgArray, array $toolCallIdToName): array
    {
        $toolCallId = $this->getString($msgArray, 'tool_call_id');
        // Resolve function name from pre-scanned mapping
        $name = $toolCallIdToName[$toolCallId] ?? $this->getString($msgArray, 'name', 'unknown');
        $contentStr = $this->getString($msgArray, 'content');
        $responseData = json_decode($contentStr, true);
        if (!is_array($responseData)) {
            $responseData = ['result' => $contentStr];
        }

        return [
            'role' => 'user',
            'parts' => [
                [
                    'functionResponse' => [
                        'name' => $name,
                        'response' => $responseData,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $msgArray
     *
     * @return array<string, mixed>
     */
    private function convertAssistantToolCalls(array $msgArray): array
    {
        $content = $msgArray['content'] ?? '';
        $parts = [];
        $textContent = is_string($content) ? $content : '';
        if ($textContent !== '') {
            $parts[] = ['text' => $textContent];
        }

        $toolCalls = $msgArray['tool_calls'] ?? [];
        if (!is_array($toolCalls)) {
            $toolCalls = [];
        }

        foreach ($toolCalls as $call) {
            $callArray = $this->asArray($call);
            $function = $this->getArray($callArray, 'function');
            $arguments = $function['arguments'] ?? [];
            if (is_string($arguments)) {
                $arguments = json_decode($arguments, true) ?? [];
            }
            if (!is_array($arguments)) {
                $arguments = [];
            }

            $parts[] = [
                'functionCall' => [
                    'name' => $this->getString($function, 'name'),
                    'args' => $arguments,
                ],
            ];
        }

        return [
            'role' => 'model',
            'parts' => $parts,
        ];
    }

    /**
     * Convert multimodal content blocks to Gemini parts format.
     *
     * @param array<int, mixed> $content
     *
     * @return array<int, array<string, mixed>>
     */
    private function convertMultimodalToParts(array $content): array
    {
        $parts = [];

        foreach ($content as $block) {
            $blockArray = $this->asArray($block);
            $type = $this->getString($blockArray, 'type');

            if ($type === 'text') {
                $parts[] = ['text' => $this->getString($blockArray, 'text')];
            } elseif ($type === 'image_url') {
                $imageUrl = $this->getArray($blockArray, 'image_url');
                $url = $this->getString($imageUrl, 'url');

                if (preg_match('/^data:([^;]+);base64,(.+)$/', $url, $matches)) {
                    $parts[] = ['inlineData' => ['mimeType' => $matches[1], 'data' => $matches[2]]];
                }
            } elseif ($type === 'document') {
                $source = $this->getArray($blockArray, 'source');
                $parts[] = [
                    'inlineData' => [
                        'mimeType' => $this->getString($source, 'media_type'),
                        'data' => $this->getString($source, 'data'),
                    ],
                ];
            }
        }

        return $parts;
    }

    private function mapFinishReason(string $reason): string
    {
        return match ($reason) {
            'STOP' => 'stop',
            'MAX_TOKENS' => 'length',
            'SAFETY' => 'content_filter',
            'RECITATION' => 'content_filter',
            default => strtolower($reason),
        };
    }

    /**
     * Guard against path-traversal / query injection through the model id.
     *
     * The model id is interpolated raw into the request path
     * (`models/{model}:generateContent`); without this guard a value such as
     * `../../foo` or `gemini?evil=1&` would escape the intended endpoint or
     * smuggle extra query parameters. All official Gemini model names match
     * `[A-Za-z0-9._-]+`, so anything outside that alphabet is rejected.
     *
     * @throws ProviderConfigurationException when the model id is empty or
     *                                        contains illegal characters
     */
    private function assertValidModelId(string $model): void
    {
        if ($model === '' || preg_match('/^[A-Za-z0-9._-]+$/', $model) !== 1) {
            throw new ProviderConfigurationException(
                sprintf('Invalid Gemini model identifier: %s', $model),
                1751280000,
            );
        }
    }
}
