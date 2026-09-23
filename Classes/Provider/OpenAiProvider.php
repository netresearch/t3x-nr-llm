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
use Netresearch\NrLlm\Domain\Enum\ReasoningEffort;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Domain\ValueObject\VisionContent;
use Netresearch\NrLlm\Provider\Contract\StreamingCapableInterface;
use Netresearch\NrLlm\Provider\Contract\ToolCapableInterface;
use Netresearch\NrLlm\Provider\Contract\VisionCapableInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\OpenAi\OpenAiCallMetadata;
use Netresearch\NrLlm\Provider\OpenAi\OpenAiModelProfile;
use Netresearch\NrLlm\Provider\OpenAi\OpenAiModelProfiles;
use Netresearch\NrLlm\Provider\OpenAi\ResponsesPayloadBuilder;
use Netresearch\NrLlm\Provider\OpenAi\ResponsesResultParser;
use Psr\Http\Message\RequestInterface;

#[AsLlmProvider(priority: 100)]
final class OpenAiProvider extends AbstractProvider implements
    VisionCapableInterface,
    StreamingCapableInterface,
    ToolCapableInterface
{
    use OpenAiResponseFormatTrait;

    /** @var array<string> */
    protected array $supportedFeatures = [
        self::FEATURE_CHAT,
        self::FEATURE_COMPLETION,
        self::FEATURE_EMBEDDINGS,
        self::FEATURE_VISION,
        self::FEATURE_STREAMING,
        self::FEATURE_TOOLS,
    ];

    private const ENDPOINT_CHAT_COMPLETIONS = 'chat/completions';

    /** The endpoint a tool call to a reasoning model uses (ADR-203). */
    private const ENDPOINT_RESPONSES = 'responses';

    /**
     * Configuration option that overrides the transport choice, for an
     * OpenAI-compatible endpoint whose support this extension cannot know.
     * It is set in the configuration record's options JSON and takes the two
     * endpoint names as its values.
     */
    private const OPTION_TOOLS_TRANSPORT = 'openai_tools_transport';

    /**
     * Only this host is known to serve `/v1/responses`. A compatible gateway
     * reaches the transport through {@see self::OPTION_TOOLS_TRANSPORT}.
     */
    private const OPENAI_HOST = 'api.openai.com';

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
     * Send the configured organization ID as the `OpenAI-Organization`
     * request header. This also serves the azure_openai, together,
     * fireworks, perplexity and custom adapter types, which all map to
     * this provider class — OpenAI-compatible APIs ignore the header
     * when it does not apply.
     */
    protected function addProviderSpecificHeaders(RequestInterface $request): RequestInterface
    {
        if ($this->organizationId !== '') {
            return $request->withHeader('OpenAI-Organization', $this->organizationId);
        }

        return $request;
    }

    /**
     * @return array<string, string>
     */
    public function getAvailableModels(): array
    {
        return [
            // GPT-6 Series — reasoning models; their tool calls go through the
            // Responses API (ADR-203).
            'gpt-6-astra' => 'GPT-6 Astra (Most capable)',
            'gpt-6-sol' => 'GPT-6 Sol (Coding & agents)',
            'gpt-6-luna' => 'GPT-6 Luna (Efficient)',
            // GPT-5 Series
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

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'max_completion_tokens' => $this->getInt($options, 'max_tokens', 4096),
            ...$this->buildSamplingParams($model, $options),
        ];

        // A plain completion honours the reasoning effort too (ADR-204). Chat
        // Completions refuses only *tools* at a non-zero effort, so this call
        // stays where it is and merely gains the parameter.
        $effort = $this->resolveReasoningEffort(OpenAiModelProfiles::forModel($model), $options);
        if ($effort instanceof ReasoningEffort) {
            $payload['reasoning_effort'] = $effort->value;
        }

        $responseFormat = $this->buildResponseFormat($options);
        if ($responseFormat !== null) {
            $payload['response_format'] = $responseFormat;
        }

        if (isset($options['stop'])) {
            $payload['stop'] = $options['stop'];
        } else {
            // ChatOptions emits stop sequences as `stop_sequences`; the
            // OpenAI-compatible API expects them under `stop`.
            $stopSequences = $options['stop_sequences'] ?? null;
            if (is_array($stopSequences) && $stopSequences !== []) {
                $payload['stop'] = $stopSequences;
            }
        }

        $response = $this->sendRequest(self::ENDPOINT_CHAT_COMPLETIONS, $payload, timeout: $this->resolveRequestTimeout($options));

        $choices = $this->getList($response, 'choices');
        $choice = $this->asArray($choices[0] ?? []);
        $message = $this->getArray($choice, 'message');
        $usage = $this->getArray($response, 'usage');

        [$content, $thinking] = $this->extractThinkingBlocks($this->getString($message, 'content'));

        $metadata = [OpenAiCallMetadata::KEY_TRANSPORT => OpenAiCallMetadata::TRANSPORT_CHAT_COMPLETIONS];
        if ($effort instanceof ReasoningEffort) {
            $metadata[OpenAiCallMetadata::KEY_REASONING_EFFORT] = $effort->value;
            $metadata[OpenAiCallMetadata::KEY_EFFORT_SOURCE]    = OpenAiCallMetadata::EFFORT_SOURCE_REQUEST;
        }

        return new CompletionResponse(
            content: $content,
            model: $this->getString($response, 'model', $model),
            usage: $this->createUsageStatistics(
                promptTokens: $this->getInt($usage, 'prompt_tokens'),
                completionTokens: $this->getInt($usage, 'completion_tokens'),
            ),
            finishReason: $this->getString($choice, 'finish_reason', 'stop'),
            provider: $this->getIdentifier(),
            metadata: $this->rawResponseMetadata($options, $response, $metadata),
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
        $model   = $this->getString($options, 'model', $this->getDefaultModel());
        $profile = OpenAiModelProfiles::forModel($model);
        $effort  = $this->resolveReasoningEffort($profile, $options);

        return $this->usesResponsesTransport($profile, $options)
            ? $this->toolsViaResponses($messages, $tools, $options, $model, $effort)
            : $this->toolsViaChatCompletions($messages, $tools, $options, $model, $effort);
    }

    /**
     * Which endpoint serves this tool request (ADR-203).
     *
     * A model that cannot take function tools on `chat/completions` is the
     * only reason to move — GPT-6 Astra takes none there at all, and Sol and
     * Luna take them only with reasoning switched off. Every other model,
     * including `gpt-4.1-mini` and the GPT-5 and o-series reasoning models,
     * keeps the endpoint it works on.
     *
     * The host check is the second condition and not a formality: the
     * `openai` adapter also serves OpenAI-compatible gateways, and one of
     * those need not implement `/v1/responses`. An operator whose gateway does
     * says so in the configuration's options JSON.
     *
     * @param array<string, mixed> $options
     */
    private function usesResponsesTransport(OpenAiModelProfile $profile, array $options): bool
    {
        $forced = $this->getString($options, self::OPTION_TOOLS_TRANSPORT);
        if ($forced === self::ENDPOINT_RESPONSES) {
            return true;
        }

        if ($forced === self::ENDPOINT_CHAT_COMPLETIONS) {
            return false;
        }

        return !$profile->toolsOnChatCompletions && $this->endpointIsOpenAi();
    }

    /**
     * Whether the configured endpoint is OpenAI's own.
     *
     * The host is compared, not the whole URL: an empty configuration falls
     * back to {@see self::getDefaultBaseUrl()}, and
     * {@see \Netresearch\NrLlm\Hook\ProviderEndpointNormalizationHook} may have
     * rewritten the string.
     */
    private function endpointIsOpenAi(): bool
    {
        $baseUrl = trim($this->baseUrl);
        if ($baseUrl === '') {
            $baseUrl = $this->getDefaultBaseUrl();
        }

        $forParsing = preg_match('#^[a-z][a-z0-9+.\-]*://#i', $baseUrl) === 1
            ? $baseUrl
            : '//' . $baseUrl;

        $host = parse_url($forParsing, PHP_URL_HOST);

        return is_string($host) && strtolower($host) === self::OPENAI_HOST;
    }

    /**
     * The reasoning effort this call applies, or null to leave the model's own
     * default in place (ADR-204).
     *
     * An explicit `reasoning_effort` wins over the coarse `think` switch. A
     * `think` of false asks for no reasoning, which becomes the lowest effort
     * the model allows — `none` where it has one, `low` on GPT-6 Astra, which
     * rejects `none` with HTTP 400. The clamp is recorded on the response, so
     * a request that could not be honoured as asked is readable.
     *
     * @param array<string, mixed> $options
     */
    private function resolveReasoningEffort(OpenAiModelProfile $profile, array $options): ?ReasoningEffort
    {
        if (!$profile->hasEffortScale()) {
            return null;
        }

        $requested = ReasoningEffort::tryFromOption($options['reasoning_effort'] ?? null);

        if (!$requested instanceof ReasoningEffort && ($options['think'] ?? null) === false) {
            $requested = ReasoningEffort::None;
        }

        return $requested instanceof ReasoningEffort ? $profile->clamp($requested) : null;
    }

    /**
     * Tool calling over `/v1/responses` (ADR-203).
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param list<ToolSpec>                         $tools
     * @param array<string, mixed>                   $options
     */
    private function toolsViaResponses(
        array $messages,
        array $tools,
        array $options,
        string $model,
        ?ReasoningEffort $effort,
    ): CompletionResponse {
        // The Responses builder needs the value objects, not the Chat
        // Completions wire arrays: the opaque provider items that keep a
        // reasoning model's thinking alive across steps live on the message
        // object and are deliberately absent from `toArray()`.
        // A message with list-shaped content — text and images — stays an
        // array: ChatMessage models string content only, and the builder maps
        // those parts to the Responses vocabulary itself.
        $typed = array_map(
            static fn(ChatMessage|array $m): ChatMessage|array => $m instanceof ChatMessage || is_array($m['content'] ?? null)
                ? $m
                : ChatMessage::fromArray($m),
            $messages,
        );

        $builder = new ResponsesPayloadBuilder();

        // Sampling parameters are empty for a reasoning model. A non-reasoning
        // model only reaches this transport through the explicit override,
        // and there it keeps the temperature and top_p it would have had on
        // Chat Completions — the Responses API defines those two and no
        // frequency or presence penalty, so the penalties are not sent.
        $extra      = array_intersect_key(
            $this->buildSamplingParams($model, $options),
            ['temperature' => true, 'top_p' => true],
        );
        $textFormat = $builder->textFormat($this->buildResponseFormat($options));
        if ($textFormat !== null) {
            $extra['text'] = $textFormat;
        }

        if (isset($options['tool_choice'])) {
            $extra['tool_choice'] = $options['tool_choice'];
        }

        $payload = $builder->build(
            model: $model,
            messages: array_values($typed),
            tools: $tools,
            maxOutputTokens: $this->getInt($options, 'max_tokens', 4096),
            effort: $effort,
            extra: $extra,
        );

        $response = $this->sendRequest(
            self::ENDPOINT_RESPONSES,
            $payload,
            timeout: $this->resolveRequestTimeout($options),
        );

        $result = (new ResponsesResultParser())->parse($response, $model);

        $metadata = [
            OpenAiCallMetadata::KEY_TRANSPORT => OpenAiCallMetadata::TRANSPORT_RESPONSES,
            OpenAiCallMetadata::KEY_PROVIDER_ITEMS => $result->providerItems,
        ];

        // The provider's own answer beats what was sent: a clamp, or a default
        // this extension never chose, is only visible this way.
        $applied = $result->appliedEffort ?? $effort;
        if ($applied instanceof ReasoningEffort) {
            $metadata[OpenAiCallMetadata::KEY_REASONING_EFFORT] = $applied->value;
            $metadata[OpenAiCallMetadata::KEY_EFFORT_SOURCE]    = $result->appliedEffort instanceof ReasoningEffort
                ? OpenAiCallMetadata::EFFORT_SOURCE_PROVIDER
                : OpenAiCallMetadata::EFFORT_SOURCE_REQUEST;
        }

        return new CompletionResponse(
            content: $result->content,
            model: $result->model,
            usage: $this->createUsageStatistics(
                promptTokens: $result->promptTokens,
                completionTokens: $result->completionTokens,
            ),
            finishReason: $result->finishReason,
            provider: $this->getIdentifier(),
            toolCalls: $result->toolCalls,
            metadata: $this->rawResponseMetadata($options, $response, $metadata),
        );
    }

    /**
     * Tool calling over `/v1/chat/completions` — the path every non-reasoning
     * model keeps.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param list<ToolSpec>                         $tools
     * @param array<string, mixed>                   $options
     */
    private function toolsViaChatCompletions(
        array $messages,
        array $tools,
        array $options,
        string $model,
        ?ReasoningEffort $effort,
    ): CompletionResponse {
        $messages = array_map(
            static fn(ChatMessage|array $m): array
                => $m instanceof ChatMessage ? $m->toArray() : $m,
            $messages,
        );

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'tools' => array_map(static fn(ToolSpec $spec): array => $spec->toArray(), $tools),
            'max_completion_tokens' => $this->getInt($options, 'max_tokens', 4096),
            ...$this->buildSamplingParams($model, $options),
        ];

        if ($effort instanceof ReasoningEffort) {
            $payload['reasoning_effort'] = $effort->value;
        }

        $responseFormat = $this->buildResponseFormat($options);
        if ($responseFormat !== null) {
            $payload['response_format'] = $responseFormat;
        }

        if (isset($options['tool_choice'])) {
            $payload['tool_choice'] = $options['tool_choice'];
        }

        $response = $this->sendRequest(self::ENDPOINT_CHAT_COMPLETIONS, $payload, timeout: $this->resolveRequestTimeout($options));

        $choices = $this->getList($response, 'choices');
        $choice = $this->asArray($choices[0] ?? []);
        $message = $this->getArray($choice, 'message');
        $usage = $this->getArray($response, 'usage');

        $toolCalls    = null;
        $rawToolCalls = $this->getArray($message, 'tool_calls');
        if ($rawToolCalls !== []) {
            $toolCalls = [];
            foreach ($rawToolCalls as $tc) {
                // Untrusted provider output: skip a malformed tool call (missing
                // id/name) instead of crashing the whole completion.
                $call = ToolCall::tryFromArray($this->asArray($tc));
                if ($call instanceof ToolCall) {
                    $toolCalls[] = $call;
                }
            }
        }

        [$content, $thinking] = $this->extractThinkingBlocks($this->getString($message, 'content'));

        $metadata = [OpenAiCallMetadata::KEY_TRANSPORT => OpenAiCallMetadata::TRANSPORT_CHAT_COMPLETIONS];

        // Chat Completions echoes no effort back, so what was sent is what can
        // be recorded — and the source key says so (ADR-204).
        if ($effort instanceof ReasoningEffort) {
            $metadata[OpenAiCallMetadata::KEY_REASONING_EFFORT] = $effort->value;
            $metadata[OpenAiCallMetadata::KEY_EFFORT_SOURCE]    = OpenAiCallMetadata::EFFORT_SOURCE_REQUEST;
        }

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
            metadata: $this->rawResponseMetadata($options, $response, $metadata),
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

        $response = $this->sendRequest('embeddings', $payload, timeout: $this->resolveRequestTimeout($options));

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
                'content' => array_values(array_map(static fn(VisionContent $vc): array => $vc->toArray(), $content)),
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

        $response = $this->sendRequest(self::ENDPOINT_CHAT_COMPLETIONS, $payload, timeout: $this->resolveRequestTimeout($options));

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

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'max_completion_tokens' => $this->getInt($options, 'max_tokens', 4096),
            'stream' => true,
            ...$this->buildSamplingParams($model, $options),
        ];

        $url = rtrim($this->baseUrl, '/') . '/' . self::ENDPOINT_CHAT_COMPLETIONS;

        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'text/event-stream');

        // Streaming bypasses sendRequest(), so both the organization header
        // and operator-configured custom headers must be applied here.
        $request = $this->applyCustomHeaders($this->addProviderSpecificHeaders($request));

        $body = $this->streamFactory->createStream(json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        $request = $request->withBody($body);

        $response = $this->getHttpClient($this->resolveRequestTimeout($options))->sendRequest($request);
        $this->assertStreamingResponseOk($response, self::ENDPOINT_CHAT_COMPLETIONS);
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

                    $content = $this->extractStreamDelta($data);
                    if ($content !== null) {
                        yield $content;
                    }
                }
            }

            $this->guardStreamLineBuffer($buffer);
        }
    }

    /**
     * Extract the streamed delta content from a single SSE `data:` payload.
     *
     * Returns the chunk's content string, or `null` when the payload is
     * malformed JSON, not an object, or carries no content delta.
     */
    private function extractStreamDelta(string $data): ?string
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
        $choices = $this->getList($json, 'choices');
        $firstChoice = $this->asArray($choices[0] ?? []);
        $delta = $this->getArray($firstChoice, 'delta');
        $content = $this->getString($delta, 'content');

        return $content !== '' ? $content : null;
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    /**
     * Check if a model doesn't support sampling parameters
     * (temperature, top_p, frequency_penalty, presence_penalty).
     *
     * This used to be the regex `/^(o[1-9]|gpt-5)/`, which excluded the whole
     * GPT-6 family — so `temperature` was still being sent to a reasoning
     * model. The answer now comes from {@see OpenAiModelProfiles}, where the
     * families are written down once and the same table decides the transport
     * (ADR-203).
     */
    private function isReasoningModel(string $model): bool
    {
        return OpenAiModelProfiles::forModel($model)->isReasoningModel;
    }

    // buildResponseFormat() is provided by OpenAiResponseFormatTrait
    // (ADR-128): json_schema strict when the schema qualifies, json_object
    // otherwise / for plain `'json'`.

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
