<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider;

use BadMethodCallException;
use Generator;
use JsonException;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Provider\Contract\StreamingCapableInterface;
use Netresearch\NrLlm\Provider\Contract\ToolCapableInterface;
use Override;

/**
 * Groq Provider.
 *
 * Ultra-fast inference using custom LPU (Language Processing Unit) hardware.
 * Offers OpenAI-compatible API with significantly lower latency.
 *
 * Features:
 * - Extremely fast inference (often <100ms for short responses)
 * - Chat completions with streaming
 * - Function/tool calling
 * - Cost-effective for high-volume use cases
 *
 * Note: Groq does not provide embedding models.
 */
final class GroqProvider extends AbstractProvider implements
    StreamingCapableInterface,
    ToolCapableInterface
{
    /** @var array<string> */
    protected array $supportedFeatures = [
        self::FEATURE_CHAT,
        self::FEATURE_COMPLETION,
        self::FEATURE_STREAMING,
        self::FEATURE_TOOLS,
    ];

    private const string DEFAULT_CHAT_MODEL = 'llama-3.3-70b-versatile';

    /** @var array<string, string> */
    private const array MODELS = [
        // Llama 3.3
        'llama-3.3-70b-versatile' => 'Llama 3.3 70B Versatile',
        'llama-3.3-70b-specdec' => 'Llama 3.3 70B SpecDec (Fast)',
        // Llama 3.1
        'llama-3.1-70b-versatile' => 'Llama 3.1 70B Versatile',
        'llama-3.1-8b-instant' => 'Llama 3.1 8B Instant (Ultra-Fast)',
        // Llama 3.2
        'llama-3.2-90b-vision-preview' => 'Llama 3.2 90B Vision (Preview)',
        'llama-3.2-11b-vision-preview' => 'Llama 3.2 11B Vision (Preview)',
        'llama-3.2-3b-preview' => 'Llama 3.2 3B (Preview)',
        'llama-3.2-1b-preview' => 'Llama 3.2 1B (Preview)',
        // Mixtral
        'mixtral-8x7b-32768' => 'Mixtral 8x7B (32K context)',
        // Gemma
        'gemma2-9b-it' => 'Gemma 2 9B Instruct',
        // Whisper (for reference, handled separately)
        'whisper-large-v3' => 'Whisper Large V3 (Audio)',
        'whisper-large-v3-turbo' => 'Whisper Large V3 Turbo (Audio)',
    ];

    public function getName(): string
    {
        return 'Groq';
    }

    public function getIdentifier(): string
    {
        return 'groq';
    }

    protected function getDefaultBaseUrl(): string
    {
        return 'https://api.groq.com/openai/v1';
    }

    #[Override]
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

        if (isset($options['frequency_penalty'])) {
            $payload['frequency_penalty'] = $this->getFloat($options, 'frequency_penalty');
        }

        if (isset($options['presence_penalty'])) {
            $payload['presence_penalty'] = $this->getFloat($options, 'presence_penalty');
        }

        if (isset($options['stop'])) {
            $payload['stop'] = $options['stop'];
        }

        if (isset($options['seed'])) {
            $payload['seed'] = $this->getInt($options, 'seed');
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

        // Groq supports parallel_tool_calls
        if (isset($options['parallel_tool_calls'])) {
            $payload['parallel_tool_calls'] = $this->getBool($options, 'parallel_tool_calls');
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
     * Get ultra-fast model identifier (lowest latency).
     */
    public static function getFastModel(): string
    {
        return 'llama-3.1-8b-instant';
    }

    /**
     * Get best quality model identifier.
     */
    public static function getQualityModel(): string
    {
        return 'llama-3.3-70b-versatile';
    }

    /**
     * Get vision-capable model identifier.
     */
    public static function getVisionModel(): string
    {
        return 'llama-3.2-90b-vision-preview';
    }

    /**
     * Groq does not support embeddings - returns empty array.
     *
     * @param string|array<int, string> $input
     * @param array<string, mixed>      $options
     */
    public function embeddings(string|array $input, array $options = []): never
    {
        throw new BadMethodCallException('Groq does not support embeddings. Use OpenAI or Mistral for embeddings.', 4840547720);
    }
}
