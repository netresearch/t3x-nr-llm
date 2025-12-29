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
use Override;
use Psr\Http\Message\RequestInterface;

final class GeminiProvider extends AbstractProvider implements
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

    private const string DEFAULT_MODEL = 'gemini-3-flash-preview';
    private const string EMBEDDING_MODEL = 'text-embedding-004';

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

    #[Override]
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
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed>             $options
     */
    public function chatCompletion(array $messages, array $options = []): CompletionResponse
    {
        $model = $this->getString($options, 'model', $this->getDefaultModel());
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
            "models/{$model}:generateContent?key=" . $this->apiKey,
            $payload,
        );

        $candidates = $this->getList($response, 'candidates');
        $candidate = $this->asArray($candidates[0] ?? []);
        $contentObj = $this->getArray($candidate, 'content');
        $parts = $this->getList($contentObj, 'parts');
        $firstPart = $this->asArray($parts[0] ?? []);
        $content = $this->getString($firstPart, 'text');
        $usage = $this->getArray($response, 'usageMetadata');

        return $this->createCompletionResponse(
            content: $content,
            model: $model,
            usage: $this->createUsageStatistics(
                promptTokens: $this->getInt($usage, 'promptTokenCount'),
                completionTokens: $this->getInt($usage, 'candidatesTokenCount'),
            ),
            finishReason: $this->mapFinishReason($this->getString($candidate, 'finishReason', 'STOP')),
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
        $geminiContents = $this->convertToGeminiFormat($messages);

        // Convert OpenAI tool format to Gemini format
        $geminiTools = [
            'functionDeclarations' => array_map(function (array $tool): array {
                $toolArray = $this->asArray($tool);
                $function = $this->getArray($toolArray, 'function');
                return [
                    'name' => $this->getString($function, 'name'),
                    'description' => $this->getString($function, 'description'),
                    'parameters' => $this->getArray($function, 'parameters'),
                ];
            }, $tools),
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
            "models/{$model}:generateContent?key=" . $this->apiKey,
            $payload,
        );

        $candidates = $this->getList($response, 'candidates');
        $candidate = $this->asArray($candidates[0] ?? []);
        $contentObj = $this->getArray($candidate, 'content');
        $parts = $this->getList($contentObj, 'parts');

        $content = '';
        $toolCalls = [];

        foreach ($parts as $part) {
            $partArray = $this->asArray($part);
            $text = $this->getNullableString($partArray, 'text');
            if ($text !== null) {
                $content .= $text;
            }

            $functionCall = $this->getArray($partArray, 'functionCall');
            if ($functionCall !== []) {
                $toolCalls[] = [
                    'id' => 'call_' . uniqid(),
                    'type' => 'function',
                    'function' => [
                        'name' => $this->getString($functionCall, 'name'),
                        'arguments' => $this->getArray($functionCall, 'args'),
                    ],
                ];
            }
        }

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
                "models/{$model}:embedContent?key=" . $this->apiKey,
                $payload,
            );

            $embeddingData = $this->getArray($response, 'embedding');
            $values = $this->getArray($embeddingData, 'values');
            /** @var array<int, float> $floatValues */
            $floatValues = array_map(fn($v): float => $this->asFloat($v), $values);
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
     * @param array<int, array<string, mixed>> $content
     * @param array<string, mixed>             $options
     */
    public function analyzeImage(array $content, array $options = []): VisionResponse
    {
        $model = $this->getString($options, 'model', 'gemini-3-flash-preview');

        $parts = [];
        foreach ($content as $item) {
            $itemArray = $this->asArray($item);
            $itemType = $this->getString($itemArray, 'type');

            if ($itemType === 'text') {
                $text = $this->getString($itemArray, 'text');
                if ($text !== '') {
                    $parts[] = ['text' => $text];
                }
            } elseif ($itemType === 'image_url') {
                $imageUrl = $this->getNestedString($itemArray, 'image_url.url');

                if (str_starts_with($imageUrl, 'data:')) {
                    preg_match('/^data:(image\/\w+);base64,(.+)$/', $imageUrl, $matches);
                    $parts[] = [
                        'inlineData' => [
                            'mimeType' => $matches[1] ?? 'image/jpeg',
                            'data' => $matches[2] ?? '',
                        ],
                    ];
                } else {
                    $parts[] = [
                        'fileData' => [
                            'mimeType' => 'image/jpeg',
                            'fileUri' => $imageUrl,
                        ],
                    ];
                }
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
            "models/{$model}:generateContent?key=" . $this->apiKey,
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

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed>             $options
     *
     * @return Generator<int, string, mixed, void>
     */
    public function streamChatCompletion(array $messages, array $options = []): Generator
    {
        $model = $this->getString($options, 'model', $this->getDefaultModel());
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

        $url = rtrim($this->baseUrl, '/') . "/models/{$model}:streamGenerateContent?key=" . $this->apiKey . '&alt=sse';

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

                    try {
                        $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                        if (is_array($decoded)) {
                            $json = $this->asArray($decoded);
                            $candidates = $this->getList($json, 'candidates');
                            $candidate = $this->asArray($candidates[0] ?? []);
                            $contentObj = $this->getArray($candidate, 'content');
                            $parts = $this->getList($contentObj, 'parts');
                            $firstPart = $this->asArray($parts[0] ?? []);
                            $text = $this->getString($firstPart, 'text');
                            if ($text !== '') {
                                yield $text;
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

    #[Override]
    protected function addProviderSpecificHeaders(RequestInterface $request): RequestInterface
    {
        // Gemini uses API key in URL, not headers
        return $request->withoutHeader('Authorization');
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     *
     * @return array{contents: array<int, array<string, mixed>>, systemInstruction?: array<string, mixed>}
     */
    private function convertToGeminiFormat(array $messages): array
    {
        $contents = [];
        $systemInstruction = null;

        foreach ($messages as $message) {
            $msgArray = $this->asArray($message);
            $role = $this->getString($msgArray, 'role');
            $content = $this->getString($msgArray, 'content');

            if ($role === 'system') {
                $systemInstruction = [
                    'parts' => [['text' => $content]],
                ];
            } else {
                $geminiRole = $role === 'assistant' ? 'model' : 'user';
                $contents[] = [
                    'role' => $geminiRole,
                    'parts' => [['text' => $content]],
                ];
            }
        }

        $result = ['contents' => $contents];
        if ($systemInstruction !== null) {
            $result['systemInstruction'] = $systemInstruction;
        }

        return $result;
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
}
