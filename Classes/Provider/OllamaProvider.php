<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider;

use Generator;
use JsonException;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Provider\Contract\StreamingCapableInterface;
use Override;
use Throwable;

/**
 * Ollama provider for local LLM deployments.
 *
 * Ollama provides an OpenAI-compatible API for local model serving.
 *
 * @see https://ollama.com/
 */
final class OllamaProvider extends AbstractProvider implements StreamingCapableInterface
{
    /** @var array<string> */
    protected array $supportedFeatures = [
        self::FEATURE_CHAT,
        self::FEATURE_COMPLETION,
        self::FEATURE_EMBEDDINGS,
        self::FEATURE_STREAMING,
    ];

    private const string DEFAULT_MODEL = 'llama3.2';

    public function getName(): string
    {
        return 'Ollama';
    }

    public function getIdentifier(): string
    {
        return 'ollama';
    }

    protected function getDefaultBaseUrl(): string
    {
        return 'http://localhost:11434';
    }

    #[Override]
    public function getDefaultModel(): string
    {
        return $this->defaultModel !== '' ? $this->defaultModel : self::DEFAULT_MODEL;
    }

    #[Override]
    public function isAvailable(): bool
    {
        // Ollama doesn't require an API key
        return $this->baseUrl !== '';
    }

    #[Override]
    protected function validateConfiguration(): void
    {
        // Ollama doesn't require API key validation
        if ($this->baseUrl === '') {
            $this->baseUrl = $this->getDefaultBaseUrl();
        }
    }

    /**
     * @return array<string, string>
     */
    public function getAvailableModels(): array
    {
        try {
            $response = $this->sendRequest('api/tags', [], 'GET');
            $models = $this->getList($response, 'models');

            $result = [];
            foreach ($models as $model) {
                $modelArray = $this->asArray($model);
                $name = $this->getString($modelArray, 'name');
                if ($name !== '') {
                    $result[$name] = $name;
                }
            }

            return $result;
        } catch (Throwable) {
            // Return default models if we can't fetch from server
            return [
                'llama3.2' => 'Llama 3.2',
                'llama3.2:70b' => 'Llama 3.2 70B',
                'mistral' => 'Mistral',
                'codellama' => 'Code Llama',
                'phi3' => 'Phi-3',
            ];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed>             $options
     */
    public function chatCompletion(array $messages, array $options = []): CompletionResponse
    {
        $model = $this->getString($options, 'model', $this->getDefaultModel());

        /** @var array<string, mixed> $payloadOptions */
        $payloadOptions = [];

        // Add optional parameters
        if (isset($options['temperature'])) {
            $payloadOptions['temperature'] = $this->getFloat($options, 'temperature');
        }

        if (isset($options['top_p'])) {
            $payloadOptions['top_p'] = $this->getFloat($options, 'top_p');
        }

        if (isset($options['num_predict']) || isset($options['max_tokens'])) {
            $payloadOptions['num_predict'] = $this->getInt($options, 'num_predict', $this->getInt($options, 'max_tokens', 4096));
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
        ];

        if ($payloadOptions !== []) {
            $payload['options'] = $payloadOptions;
        }

        $response = $this->sendRequest('api/chat', $payload);

        $message = $this->getArray($response, 'message');

        return $this->createCompletionResponse(
            content: $this->getString($message, 'content'),
            model: $this->getString($response, 'model', $model),
            usage: $this->createUsageStatistics(
                promptTokens: $this->getInt($response, 'prompt_eval_count'),
                completionTokens: $this->getInt($response, 'eval_count'),
            ),
            finishReason: $this->getString($response, 'done_reason', 'stop'),
        );
    }

    /**
     * @param string|array<int, string> $input
     * @param array<string, mixed>      $options
     */
    public function embeddings(string|array $input, array $options = []): EmbeddingResponse
    {
        $model = $this->getString($options, 'model', 'nomic-embed-text');
        $inputs = is_array($input) ? $input : [$input];

        $embeddings = [];
        $totalTokens = 0;

        foreach ($inputs as $text) {
            $response = $this->sendRequest('api/embeddings', [
                'model' => $model,
                'prompt' => $text,
            ]);

            /** @var array<int, float> $embedding */
            $embedding = $this->getList($response, 'embedding');
            $embeddings[] = $embedding;
            $totalTokens += $this->getInt($response, 'prompt_eval_count', 0);
        }

        return $this->createEmbeddingResponse(
            embeddings: $embeddings,
            model: $model,
            usage: $this->createUsageStatistics(
                promptTokens: $totalTokens,
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
        $model = $this->getString($options, 'model', $this->getDefaultModel());

        /** @var array<string, mixed> $payloadOptions */
        $payloadOptions = [];

        if (isset($options['temperature'])) {
            $payloadOptions['temperature'] = $this->getFloat($options, 'temperature');
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'stream' => true,
        ];

        if ($payloadOptions !== []) {
            $payload['options'] = $payloadOptions;
        }

        $url = rtrim($this->baseUrl, '/') . '/api/chat';

        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json');

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

                if (trim($line) === '') {
                    continue;
                }

                try {
                    $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        $json = $this->asArray($decoded);
                        $message = $this->getArray($json, 'message');
                        $content = $this->getString($message, 'content');
                        if ($content !== '') {
                            yield $content;
                        }

                        // Check if done
                        if ($this->getBool($json, 'done')) {
                            return;
                        }
                    }
                } catch (JsonException) {
                    // Skip malformed JSON
                }
            }
        }
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    /**
     * Test the connection to Ollama.
     *
     * Unlike getAvailableModels(), this method does NOT return fallback models
     * on failure. It makes an actual HTTP request and throws on any error.
     *
     *
     * @throws \Netresearch\NrLlm\Provider\Exception\ProviderConnectionException on connection failure
     *
     * @return array{success: bool, message: string, models?: array<string, string>}
     */
    #[Override]
    public function testConnection(): array
    {
        // Make actual HTTP request - do NOT catch exceptions
        $response = $this->sendRequest('api/tags', [], 'GET');
        $models = $this->getList($response, 'models');

        $result = [];
        foreach ($models as $model) {
            $modelArray = $this->asArray($model);
            $name = $this->getString($modelArray, 'name');
            if ($name !== '') {
                $result[$name] = $name;
            }
        }

        return [
            'success' => true,
            'message' => sprintf('Connection successful. Found %d models.', count($result)),
            'models' => $result,
        ];
    }
}
