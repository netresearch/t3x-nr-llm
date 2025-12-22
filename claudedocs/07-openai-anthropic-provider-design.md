# OpenAI and Anthropic Provider Design & Implementation

> Created: 2025-12-22
> Purpose: Comprehensive design for OpenAI (MVP 0.1) and Anthropic (MVP 0.2) providers
> Status: Architecture & Implementation Specification

---

## 1. Executive Summary

This document provides complete implementation specifications for the two primary AI providers in the `ai_base` extension:
- **OpenAI**: MVP 0.1 target (GPT-4, GPT-4o, embeddings, vision)
- **Anthropic**: MVP 0.2 target (Claude 3 family, native vision)

Both providers include:
- Full API integration with streaming support
- Model mapping and aliasing
- Rate limit handling (429 responses)
- Token counting algorithms
- Error response normalization
- HTTP client abstraction (PSR-18)
- Comprehensive unit and integration tests

---

## 2. OpenAI Provider Specification

### 2.1 API Endpoints

| Endpoint | Purpose | Method | Response Type |
|----------|---------|--------|---------------|
| `/v1/chat/completions` | Chat completion | POST | JSON/SSE |
| `/v1/embeddings` | Text embeddings | POST | JSON |
| `/v1/images/generations` | Image generation | POST | JSON |
| `/v1/models` | List models | GET | JSON |

### 2.2 Authentication

```http
POST https://api.openai.com/v1/chat/completions
Authorization: Bearer sk-proj-...
Content-Type: application/json
OpenAI-Organization: org-... (optional)
```

### 2.3 Model Catalog

#### Chat Models
```php
private const CHAT_MODELS = [
    // GPT-4 Family
    'gpt-4' => [
        'name' => 'gpt-4',
        'context_window' => 8192,
        'input_cost' => 0.03,  // per 1K tokens
        'output_cost' => 0.06,
        'supports_vision' => false,
        'supports_functions' => true,
        'max_output_tokens' => 4096,
    ],
    'gpt-4-turbo' => [
        'name' => 'gpt-4-turbo-2024-04-09',
        'context_window' => 128000,
        'input_cost' => 0.01,
        'output_cost' => 0.03,
        'supports_vision' => true,
        'supports_functions' => true,
        'max_output_tokens' => 4096,
    ],
    'gpt-4o' => [
        'name' => 'gpt-4o-2024-11-20',
        'context_window' => 128000,
        'input_cost' => 0.0025,
        'output_cost' => 0.01,
        'supports_vision' => true,
        'supports_functions' => true,
        'max_output_tokens' => 16384,
    ],
    'gpt-4o-mini' => [
        'name' => 'gpt-4o-mini-2024-07-18',
        'context_window' => 128000,
        'input_cost' => 0.00015,
        'output_cost' => 0.0006,
        'supports_vision' => true,
        'supports_functions' => true,
        'max_output_tokens' => 16384,
    ],

    // GPT-3.5 Family
    'gpt-3.5-turbo' => [
        'name' => 'gpt-3.5-turbo-0125',
        'context_window' => 16385,
        'input_cost' => 0.0005,
        'output_cost' => 0.0015,
        'supports_vision' => false,
        'supports_functions' => true,
        'max_output_tokens' => 4096,
    ],
];
```

#### Embedding Models
```php
private const EMBEDDING_MODELS = [
    'text-embedding-3-small' => [
        'name' => 'text-embedding-3-small',
        'dimensions' => 1536,
        'cost' => 0.00002, // per 1K tokens
        'max_input_tokens' => 8191,
    ],
    'text-embedding-3-large' => [
        'name' => 'text-embedding-3-large',
        'dimensions' => 3072,
        'cost' => 0.00013,
        'max_input_tokens' => 8191,
    ],
    'text-embedding-ada-002' => [
        'name' => 'text-embedding-ada-002',
        'dimensions' => 1536,
        'cost' => 0.0001,
        'max_input_tokens' => 8191,
    ],
];
```

### 2.4 Model Aliases

```php
private const MODEL_ALIASES = [
    // User-friendly aliases
    'gpt4' => 'gpt-4o',
    'gpt4-turbo' => 'gpt-4-turbo',
    'gpt4o' => 'gpt-4o',
    'gpt4o-mini' => 'gpt-4o-mini',
    'gpt35' => 'gpt-3.5-turbo',
    'gpt-3.5' => 'gpt-3.5-turbo',

    // Vision aliases
    'gpt-vision' => 'gpt-4o',
    'gpt-4-vision' => 'gpt-4-turbo',

    // Default selections
    'default' => 'gpt-4o',
    'fast' => 'gpt-4o-mini',
    'smart' => 'gpt-4o',
    'embedding' => 'text-embedding-3-small',
];
```

### 2.5 Request/Response Formats

#### Chat Completion Request
```json
{
  "model": "gpt-4o",
  "messages": [
    {
      "role": "system",
      "content": "You are a helpful assistant."
    },
    {
      "role": "user",
      "content": "Translate this to German: Hello world"
    }
  ],
  "temperature": 0.7,
  "max_tokens": 1000,
  "top_p": 1.0,
  "frequency_penalty": 0.0,
  "presence_penalty": 0.0,
  "stream": false,
  "response_format": { "type": "text" }
}
```

#### Chat Completion Response
```json
{
  "id": "chatcmpl-abc123",
  "object": "chat.completion",
  "created": 1677858242,
  "model": "gpt-4o-2024-11-20",
  "usage": {
    "prompt_tokens": 13,
    "completion_tokens": 7,
    "total_tokens": 20
  },
  "choices": [
    {
      "message": {
        "role": "assistant",
        "content": "Hallo Welt"
      },
      "finish_reason": "stop",
      "index": 0
    }
  ]
}
```

#### Streaming Response (SSE)
```
data: {"id":"chatcmpl-abc123","object":"chat.completion.chunk","created":1677858242,"model":"gpt-4o","choices":[{"index":0,"delta":{"role":"assistant","content":""},"finish_reason":null}]}

data: {"id":"chatcmpl-abc123","object":"chat.completion.chunk","created":1677858242,"model":"gpt-4o","choices":[{"index":0,"delta":{"content":"Hallo"},"finish_reason":null}]}

data: {"id":"chatcmpl-abc123","object":"chat.completion.chunk","created":1677858242,"model":"gpt-4o","choices":[{"index":0,"delta":{"content":" Welt"},"finish_reason":null}]}

data: {"id":"chatcmpl-abc123","object":"chat.completion.chunk","created":1677858242,"model":"gpt-4o","choices":[{"index":0,"delta":{},"finish_reason":"stop"}]}

data: [DONE]
```

#### Vision Request (Multi-modal)
```json
{
  "model": "gpt-4o",
  "messages": [
    {
      "role": "user",
      "content": [
        {
          "type": "text",
          "text": "What's in this image?"
        },
        {
          "type": "image_url",
          "image_url": {
            "url": "https://example.com/image.jpg",
            "detail": "high"
          }
        }
      ]
    }
  ],
  "max_tokens": 300
}
```

#### Embedding Request
```json
{
  "model": "text-embedding-3-small",
  "input": "The quick brown fox jumps over the lazy dog",
  "encoding_format": "float"
}
```

#### Embedding Response
```json
{
  "object": "list",
  "data": [
    {
      "object": "embedding",
      "embedding": [0.0023064255, -0.009327292, ...],
      "index": 0
    }
  ],
  "model": "text-embedding-3-small",
  "usage": {
    "prompt_tokens": 8,
    "total_tokens": 8
  }
}
```

### 2.6 Error Responses

#### Rate Limit (429)
```json
{
  "error": {
    "message": "Rate limit reached for requests",
    "type": "rate_limit_error",
    "param": null,
    "code": "rate_limit_exceeded"
  }
}
```

**Headers**:
```
x-ratelimit-limit-requests: 500
x-ratelimit-limit-tokens: 10000
x-ratelimit-remaining-requests: 0
x-ratelimit-remaining-tokens: 5234
x-ratelimit-reset-requests: 2m34s
x-ratelimit-reset-tokens: 1m12s
```

#### Invalid API Key (401)
```json
{
  "error": {
    "message": "Incorrect API key provided",
    "type": "invalid_request_error",
    "param": null,
    "code": "invalid_api_key"
  }
}
```

#### Context Length Exceeded (400)
```json
{
  "error": {
    "message": "This model's maximum context length is 128000 tokens. However, your messages resulted in 130000 tokens.",
    "type": "invalid_request_error",
    "param": "messages",
    "code": "context_length_exceeded"
  }
}
```

#### Server Error (500, 503)
```json
{
  "error": {
    "message": "The server had an error while processing your request. Sorry about that!",
    "type": "server_error",
    "param": null,
    "code": null
  }
}
```

### 2.7 Token Counting

OpenAI uses the **tiktoken** algorithm. For MVP, we'll use approximation:

```php
/**
 * Approximate token count (1 token ≈ 4 characters for English)
 * For production, integrate tiktoken library or use OpenAI's tokenizer API
 */
private function estimateTokenCount(string $text): int
{
    // Simple approximation: ~4 chars per token
    $charCount = mb_strlen($text);
    $tokens = (int) ceil($charCount / 4);

    // Add overhead for message structure (role, etc.)
    return $tokens + 4;
}

/**
 * More accurate: Count via OpenAI's tokenizer endpoint
 */
private function countTokensAccurate(string $text, string $model): int
{
    // For production implementation
    // Use: https://github.com/openai/tiktoken-php
    // or call OpenAI's tokenizer API
}
```

### 2.8 Rate Limit Handling Strategy

```php
private function handleRateLimit(ResponseInterface $response): void
{
    $headers = $response->getHeaders();

    $remainingRequests = (int) ($headers['x-ratelimit-remaining-requests'][0] ?? 0);
    $remainingTokens = (int) ($headers['x-ratelimit-remaining-tokens'][0] ?? 0);
    $resetRequests = $headers['x-ratelimit-reset-requests'][0] ?? '0s';
    $resetTokens = $headers['x-ratelimit-reset-tokens'][0] ?? '0s';

    if ($remainingRequests < 5 || $remainingTokens < 1000) {
        // Log warning - approaching rate limit
        $this->logger->warning('OpenAI rate limit warning', [
            'remaining_requests' => $remainingRequests,
            'remaining_tokens' => $remainingTokens,
        ]);
    }

    if ($response->getStatusCode() === 429) {
        $waitTime = $this->parseResetTime($resetRequests);

        throw new RateLimitException(
            sprintf('Rate limit exceeded. Retry after %d seconds', $waitTime),
            $waitTime,
            $remainingRequests,
            $remainingTokens
        );
    }
}

private function parseResetTime(string $resetString): int
{
    // Parse "2m34s" format
    if (preg_match('/(\d+)m(\d+)s/', $resetString, $matches)) {
        return ($matches[1] * 60) + $matches[2];
    }
    if (preg_match('/(\d+)s/', $resetString, $matches)) {
        return (int) $matches[1];
    }
    return 60; // Default: 1 minute
}
```

---

## 3. Anthropic Provider Specification

### 3.1 API Endpoints

| Endpoint | Purpose | Method | Response Type |
|----------|---------|--------|---------------|
| `/v1/messages` | Create message | POST | JSON/SSE |
| `/v1/models` | List models | GET | JSON |

### 3.2 Authentication

```http
POST https://api.anthropic.com/v1/messages
x-api-key: sk-ant-...
anthropic-version: 2023-06-01
Content-Type: application/json
anthropic-dangerous-direct-browser-access: true (if needed)
```

### 3.3 Model Catalog

```php
private const MODELS = [
    // Claude 3.5 Sonnet (Latest)
    'claude-3-5-sonnet-20241022' => [
        'name' => 'claude-3-5-sonnet-20241022',
        'display_name' => 'Claude 3.5 Sonnet',
        'context_window' => 200000,
        'max_output_tokens' => 8192,
        'input_cost' => 0.003,  // per 1K tokens
        'output_cost' => 0.015,
        'supports_vision' => true,
        'supports_tools' => true,
        'cache_writes_cost' => 0.00375,
        'cache_reads_cost' => 0.0003,
    ],

    // Claude 3 Opus
    'claude-3-opus-20240229' => [
        'name' => 'claude-3-opus-20240229',
        'display_name' => 'Claude 3 Opus',
        'context_window' => 200000,
        'max_output_tokens' => 4096,
        'input_cost' => 0.015,
        'output_cost' => 0.075,
        'supports_vision' => true,
        'supports_tools' => true,
    ],

    // Claude 3 Sonnet
    'claude-3-sonnet-20240229' => [
        'name' => 'claude-3-sonnet-20240229',
        'display_name' => 'Claude 3 Sonnet',
        'context_window' => 200000,
        'max_output_tokens' => 4096,
        'input_cost' => 0.003,
        'output_cost' => 0.015,
        'supports_vision' => true,
        'supports_tools' => true,
    ],

    // Claude 3 Haiku
    'claude-3-haiku-20240307' => [
        'name' => 'claude-3-haiku-20240307',
        'display_name' => 'Claude 3 Haiku',
        'context_window' => 200000,
        'max_output_tokens' => 4096,
        'input_cost' => 0.00025,
        'output_cost' => 0.00125,
        'supports_vision' => true,
        'supports_tools' => true,
    ],

    // Claude 3.5 Haiku
    'claude-3-5-haiku-20241022' => [
        'name' => 'claude-3-5-haiku-20241022',
        'display_name' => 'Claude 3.5 Haiku',
        'context_window' => 200000,
        'max_output_tokens' => 8192,
        'input_cost' => 0.0008,
        'output_cost' => 0.004,
        'supports_vision' => false,
        'supports_tools' => true,
    ],
];
```

### 3.4 Model Aliases

```php
private const MODEL_ALIASES = [
    // User-friendly aliases
    'claude' => 'claude-3-5-sonnet-20241022',
    'claude-3' => 'claude-3-5-sonnet-20241022',
    'claude-3.5' => 'claude-3-5-sonnet-20241022',
    'claude-sonnet' => 'claude-3-5-sonnet-20241022',
    'claude-opus' => 'claude-3-opus-20240229',
    'claude-haiku' => 'claude-3-5-haiku-20241022',

    // Performance tiers
    'default' => 'claude-3-5-sonnet-20241022',
    'fast' => 'claude-3-5-haiku-20241022',
    'smart' => 'claude-3-opus-20240229',
    'balanced' => 'claude-3-5-sonnet-20241022',
];
```

### 3.5 Request/Response Formats

#### Messages Request
```json
{
  "model": "claude-3-5-sonnet-20241022",
  "max_tokens": 1024,
  "system": "You are a helpful assistant that translates text.",
  "messages": [
    {
      "role": "user",
      "content": "Translate to German: Hello world"
    }
  ],
  "temperature": 0.7,
  "top_p": 1.0,
  "top_k": 5,
  "stream": false
}
```

#### Messages Response
```json
{
  "id": "msg_01XFDUDYJgAACzvnptvVoYEL",
  "type": "message",
  "role": "assistant",
  "content": [
    {
      "type": "text",
      "text": "Hallo Welt"
    }
  ],
  "model": "claude-3-5-sonnet-20241022",
  "stop_reason": "end_turn",
  "stop_sequence": null,
  "usage": {
    "input_tokens": 16,
    "output_tokens": 3
  }
}
```

#### Streaming Response (SSE)
```
event: message_start
data: {"type":"message_start","message":{"id":"msg_01XFDUDYJgAACzvnptvVoYEL","type":"message","role":"assistant","content":[],"model":"claude-3-5-sonnet-20241022","stop_reason":null,"stop_sequence":null,"usage":{"input_tokens":16,"output_tokens":0}}}

event: content_block_start
data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}

event: content_block_delta
data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Hallo"}}

event: content_block_delta
data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":" Welt"}}

event: content_block_stop
data: {"type":"content_block_stop","index":0}

event: message_delta
data: {"type":"message_delta","delta":{"stop_reason":"end_turn","stop_sequence":null},"usage":{"output_tokens":3}}

event: message_stop
data: {"type":"message_stop"}
```

#### Vision Request (Multi-modal)
```json
{
  "model": "claude-3-5-sonnet-20241022",
  "max_tokens": 1024,
  "messages": [
    {
      "role": "user",
      "content": [
        {
          "type": "image",
          "source": {
            "type": "url",
            "url": "https://example.com/image.jpg"
          }
        },
        {
          "type": "text",
          "text": "What's in this image?"
        }
      ]
    }
  ]
}
```

#### Vision with Base64
```json
{
  "model": "claude-3-5-sonnet-20241022",
  "max_tokens": 1024,
  "messages": [
    {
      "role": "user",
      "content": [
        {
          "type": "image",
          "source": {
            "type": "base64",
            "media_type": "image/jpeg",
            "data": "/9j/4AAQSkZJRg..."
          }
        },
        {
          "type": "text",
          "text": "Describe this image"
        }
      ]
    }
  ]
}
```

### 3.6 Error Responses

#### Rate Limit (429)
```json
{
  "type": "error",
  "error": {
    "type": "rate_limit_error",
    "message": "Rate limit exceeded"
  }
}
```

**Headers**:
```
anthropic-ratelimit-requests-limit: 1000
anthropic-ratelimit-requests-remaining: 0
anthropic-ratelimit-requests-reset: 2024-01-01T00:00:00Z
anthropic-ratelimit-tokens-limit: 100000
anthropic-ratelimit-tokens-remaining: 50000
anthropic-ratelimit-tokens-reset: 2024-01-01T00:00:00Z
retry-after: 60
```

#### Invalid API Key (401)
```json
{
  "type": "error",
  "error": {
    "type": "authentication_error",
    "message": "invalid x-api-key"
  }
}
```

#### Invalid Request (400)
```json
{
  "type": "error",
  "error": {
    "type": "invalid_request_error",
    "message": "messages.0.content: Input should be a valid string"
  }
}
```

#### Overloaded (529)
```json
{
  "type": "error",
  "error": {
    "type": "overloaded_error",
    "message": "Overloaded"
  }
}
```

### 3.7 Token Counting

Anthropic provides token counts in the response. For estimation:

```php
/**
 * Anthropic token counting (similar to tiktoken)
 * Approximate: 1 token ≈ 4 characters for English
 */
private function estimateTokenCount(string $text): int
{
    // Similar to OpenAI's approximation
    $charCount = mb_strlen($text);
    $tokens = (int) ceil($charCount / 4);

    // Add overhead for message structure
    return $tokens + 3;
}

/**
 * Extract actual token usage from response
 */
private function extractTokenUsage(array $response): array
{
    return [
        'input_tokens' => $response['usage']['input_tokens'] ?? 0,
        'output_tokens' => $response['usage']['output_tokens'] ?? 0,
        'cache_creation_tokens' => $response['usage']['cache_creation_input_tokens'] ?? 0,
        'cache_read_tokens' => $response['usage']['cache_read_input_tokens'] ?? 0,
    ];
}
```

### 3.8 Rate Limit Handling Strategy

```php
private function handleRateLimit(ResponseInterface $response): void
{
    $headers = $response->getHeaders();

    $requestsRemaining = (int) ($headers['anthropic-ratelimit-requests-remaining'][0] ?? 0);
    $tokensRemaining = (int) ($headers['anthropic-ratelimit-tokens-remaining'][0] ?? 0);
    $requestsReset = $headers['anthropic-ratelimit-requests-reset'][0] ?? null;
    $retryAfter = (int) ($headers['retry-after'][0] ?? 60);

    if ($requestsRemaining < 10 || $tokensRemaining < 1000) {
        $this->logger->warning('Anthropic rate limit warning', [
            'requests_remaining' => $requestsRemaining,
            'tokens_remaining' => $tokensRemaining,
        ]);
    }

    if ($response->getStatusCode() === 429) {
        throw new RateLimitException(
            sprintf('Rate limit exceeded. Retry after %d seconds', $retryAfter),
            $retryAfter,
            $requestsRemaining,
            $tokensRemaining
        );
    }

    // Handle 529 overloaded separately
    if ($response->getStatusCode() === 529) {
        throw new ProviderOverloadedException(
            'Anthropic service is overloaded. Please retry later.',
            30 // Shorter retry for temporary overload
        );
    }
}
```

---

## 4. Common Provider Architecture

### 4.1 Provider Interface

```php
<?php
declare(strict_types=1);

namespace Netresearch\AiBase\Service\Provider;

use Netresearch\AiBase\Domain\Model\LlmRequest;
use Netresearch\AiBase\Domain\Model\LlmResponse;

interface ProviderInterface
{
    /**
     * Send a completion request
     *
     * @param LlmRequest $request The normalized request
     * @return LlmResponse The normalized response
     * @throws ProviderException On API errors
     * @throws RateLimitException On rate limits
     */
    public function complete(LlmRequest $request): LlmResponse;

    /**
     * Stream a completion response with callback
     *
     * @param LlmRequest $request The normalized request
     * @param callable $callback Callback receiving chunks: function(string $chunk): void
     * @return LlmResponse Final aggregated response
     * @throws ProviderException On API errors
     */
    public function stream(LlmRequest $request, callable $callback): LlmResponse;

    /**
     * Generate embeddings for text
     *
     * @param string|array<string> $text Single text or batch
     * @param array<string, mixed> $options Additional options
     * @return LlmResponse Response with embedding vectors
     * @throws ProviderException On API errors
     */
    public function embed(string|array $text, array $options = []): LlmResponse;

    /**
     * Analyze an image (vision capabilities)
     *
     * @param string $imageUrl URL or base64 data
     * @param string $prompt Analysis prompt
     * @param array<string, mixed> $options Additional options
     * @return LlmResponse Response with image analysis
     * @throws ProviderException On API errors
     */
    public function analyzeImage(string $imageUrl, string $prompt, array $options = []): LlmResponse;

    /**
     * Get provider identifier
     *
     * @return string Provider name (openai, anthropic, etc.)
     */
    public function getIdentifier(): string;

    /**
     * Get provider capabilities
     *
     * @return array<string, bool> Capability flags
     */
    public function getCapabilities(): array;

    /**
     * Estimate cost for token usage
     *
     * @param int $inputTokens Input token count
     * @param int $outputTokens Output token count
     * @param string|null $model Model identifier
     * @return float Cost in USD
     */
    public function estimateCost(int $inputTokens, int $outputTokens, ?string $model = null): float;

    /**
     * Check if provider is available
     *
     * @return bool True if provider can handle requests
     */
    public function isAvailable(): bool;

    /**
     * Normalize a model name or alias
     *
     * @param string $modelAlias User-provided model name
     * @return string Actual API model identifier
     */
    public function normalizeModelName(string $modelAlias): string;
}
```

### 4.2 Abstract Provider Base Class

```php
<?php
declare(strict_types=1);

namespace Netresearch\AiBase\Service\Provider;

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Netresearch\AiBase\Domain\Model\LlmRequest;
use Netresearch\AiBase\Domain\Model\LlmResponse;
use Netresearch\AiBase\Exception\ProviderException;
use Netresearch\AiBase\Exception\RateLimitException;

abstract class AbstractProvider implements ProviderInterface
{
    protected const DEFAULT_TIMEOUT = 60;
    protected const STREAM_TIMEOUT = 120;

    public function __construct(
        protected readonly ClientInterface $httpClient,
        protected readonly LoggerInterface $logger,
        protected readonly string $apiKey,
        protected readonly array $config = []
    ) {
    }

    /**
     * Get base API URL for provider
     */
    abstract protected function getBaseUrl(): string;

    /**
     * Build request headers
     *
     * @return array<string, string>
     */
    abstract protected function buildHeaders(bool $isStreaming = false): array;

    /**
     * Transform normalized request to provider-specific format
     *
     * @param LlmRequest $request
     * @return array<string, mixed>
     */
    abstract protected function transformRequest(LlmRequest $request): array;

    /**
     * Transform provider response to normalized format
     *
     * @param array<string, mixed> $response
     * @return LlmResponse
     */
    abstract protected function transformResponse(array $response): LlmResponse;

    /**
     * Handle provider-specific errors
     *
     * @throws ProviderException
     * @throws RateLimitException
     */
    abstract protected function handleError(ResponseInterface $response): never;

    /**
     * Parse streaming chunk
     *
     * @return array<string, mixed>|null
     */
    abstract protected function parseStreamChunk(string $line): ?array;

    /**
     * {@inheritdoc}
     */
    public function complete(LlmRequest $request): LlmResponse
    {
        $requestBody = $this->transformRequest($request);

        $this->logger->debug('Sending completion request', [
            'provider' => $this->getIdentifier(),
            'model' => $request->getModel(),
            'estimated_input_tokens' => $this->estimateInputTokens($request),
        ]);

        try {
            $response = $this->httpClient->request('POST', $this->getBaseUrl() . '/completions', [
                'headers' => $this->buildHeaders(),
                'json' => $requestBody,
                'timeout' => self::DEFAULT_TIMEOUT,
            ]);

            $responseData = json_decode((string) $response->getBody(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new ProviderException('Invalid JSON response from provider');
            }

            return $this->transformResponse($responseData);

        } catch (\Throwable $e) {
            if ($e instanceof ProviderException || $e instanceof RateLimitException) {
                throw $e;
            }

            $this->logger->error('Provider request failed', [
                'provider' => $this->getIdentifier(),
                'error' => $e->getMessage(),
            ]);

            throw new ProviderException(
                sprintf('Request failed: %s', $e->getMessage()),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stream(LlmRequest $request, callable $callback): LlmResponse
    {
        $requestBody = $this->transformRequest($request);
        $requestBody['stream'] = true;

        $this->logger->debug('Starting streaming request', [
            'provider' => $this->getIdentifier(),
            'model' => $request->getModel(),
        ]);

        $aggregatedContent = '';
        $metadata = [];

        try {
            $response = $this->httpClient->request('POST', $this->getBaseUrl() . '/completions', [
                'headers' => $this->buildHeaders(true),
                'json' => $requestBody,
                'timeout' => self::STREAM_TIMEOUT,
                'stream' => true,
            ]);

            $body = $response->getBody();

            while (!$body->eof()) {
                $line = $this->readLine($body);

                if (empty($line) || $line === 'data: [DONE]') {
                    continue;
                }

                $chunk = $this->parseStreamChunk($line);

                if ($chunk !== null && isset($chunk['content'])) {
                    $aggregatedContent .= $chunk['content'];
                    $callback($chunk['content']);

                    if (isset($chunk['metadata'])) {
                        $metadata = array_merge($metadata, $chunk['metadata']);
                    }
                }
            }

            // Build final response
            return new LlmResponse(
                content: $aggregatedContent,
                model: $request->getModel(),
                provider: $this->getIdentifier(),
                usage: $metadata['usage'] ?? [],
                metadata: $metadata
            );

        } catch (\Throwable $e) {
            $this->logger->error('Streaming request failed', [
                'provider' => $this->getIdentifier(),
                'error' => $e->getMessage(),
            ]);

            throw new ProviderException(
                sprintf('Streaming failed: %s', $e->getMessage()),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isAvailable(): bool
    {
        if (empty($this->apiKey)) {
            return false;
        }

        // Optional: Ping provider health endpoint
        return true;
    }

    /**
     * Estimate input tokens from request
     */
    protected function estimateInputTokens(LlmRequest $request): int
    {
        $text = '';

        if ($request->getSystemPrompt()) {
            $text .= $request->getSystemPrompt() . ' ';
        }

        foreach ($request->getMessages() as $message) {
            $text .= ($message['content'] ?? '') . ' ';
        }

        return $this->estimateTokenCount($text);
    }

    /**
     * Simple token estimation (4 chars per token)
     */
    protected function estimateTokenCount(string $text): int
    {
        $charCount = mb_strlen($text);
        return (int) ceil($charCount / 4) + 4; // +4 for message overhead
    }

    /**
     * Read a line from stream
     */
    protected function readLine($stream): string
    {
        $line = '';

        while (!$stream->eof()) {
            $char = $stream->read(1);

            if ($char === "\n") {
                break;
            }

            $line .= $char;
        }

        return trim($line);
    }

    /**
     * Parse retry-after header to seconds
     */
    protected function parseRetryAfter(string|int $retryAfter): int
    {
        if (is_numeric($retryAfter)) {
            return (int) $retryAfter;
        }

        // Parse HTTP date format
        $timestamp = strtotime($retryAfter);

        if ($timestamp !== false) {
            return max(0, $timestamp - time());
        }

        return 60; // Default: 1 minute
    }
}
```

---

## 5. Full PHP Implementation

### 5.1 OpenAI Provider Implementation

```php
<?php
declare(strict_types=1);

namespace Netresearch\AiBase\Service\Provider;

use Netresearch\AiBase\Domain\Model\LlmRequest;
use Netresearch\AiBase\Domain\Model\LlmResponse;
use Netresearch\AiBase\Exception\ProviderException;
use Netresearch\AiBase\Exception\RateLimitException;
use Psr\Http\Message\ResponseInterface;

final class OpenAiProvider extends AbstractProvider
{
    private const BASE_URL = 'https://api.openai.com/v1';

    private const CHAT_MODELS = [
        'gpt-4o' => [
            'name' => 'gpt-4o-2024-11-20',
            'context_window' => 128000,
            'input_cost' => 0.0025,
            'output_cost' => 0.01,
            'supports_vision' => true,
            'max_output_tokens' => 16384,
        ],
        'gpt-4o-mini' => [
            'name' => 'gpt-4o-mini-2024-07-18',
            'context_window' => 128000,
            'input_cost' => 0.00015,
            'output_cost' => 0.0006,
            'supports_vision' => true,
            'max_output_tokens' => 16384,
        ],
        'gpt-4-turbo' => [
            'name' => 'gpt-4-turbo-2024-04-09',
            'context_window' => 128000,
            'input_cost' => 0.01,
            'output_cost' => 0.03,
            'supports_vision' => true,
            'max_output_tokens' => 4096,
        ],
        'gpt-4' => [
            'name' => 'gpt-4',
            'context_window' => 8192,
            'input_cost' => 0.03,
            'output_cost' => 0.06,
            'supports_vision' => false,
            'max_output_tokens' => 4096,
        ],
        'gpt-3.5-turbo' => [
            'name' => 'gpt-3.5-turbo-0125',
            'context_window' => 16385,
            'input_cost' => 0.0005,
            'output_cost' => 0.0015,
            'supports_vision' => false,
            'max_output_tokens' => 4096,
        ],
    ];

    private const EMBEDDING_MODELS = [
        'text-embedding-3-small' => [
            'name' => 'text-embedding-3-small',
            'dimensions' => 1536,
            'cost' => 0.00002,
            'max_input_tokens' => 8191,
        ],
        'text-embedding-3-large' => [
            'name' => 'text-embedding-3-large',
            'dimensions' => 3072,
            'cost' => 0.00013,
            'max_input_tokens' => 8191,
        ],
    ];

    private const MODEL_ALIASES = [
        'gpt4' => 'gpt-4o',
        'gpt4o' => 'gpt-4o',
        'gpt4o-mini' => 'gpt-4o-mini',
        'gpt-4-turbo' => 'gpt-4-turbo',
        'gpt35' => 'gpt-3.5-turbo',
        'gpt-3.5' => 'gpt-3.5-turbo',
        'default' => 'gpt-4o',
        'fast' => 'gpt-4o-mini',
        'smart' => 'gpt-4o',
        'embedding' => 'text-embedding-3-small',
    ];

    /**
     * {@inheritdoc}
     */
    public function getIdentifier(): string
    {
        return 'openai';
    }

    /**
     * {@inheritdoc}
     */
    public function getCapabilities(): array
    {
        return [
            'chat' => true,
            'vision' => true,
            'embeddings' => true,
            'streaming' => true,
            'function_calling' => true,
            'json_mode' => true,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function normalizeModelName(string $modelAlias): string
    {
        $normalized = strtolower(trim($modelAlias));

        if (isset(self::MODEL_ALIASES[$normalized])) {
            return self::MODEL_ALIASES[$normalized];
        }

        if (isset(self::CHAT_MODELS[$normalized])) {
            return self::CHAT_MODELS[$normalized]['name'];
        }

        return $normalized; // Return as-is if not found
    }

    /**
     * {@inheritdoc}
     */
    public function estimateCost(int $inputTokens, int $outputTokens, ?string $model = null): float
    {
        $model = $model ?? 'gpt-4o';
        $modelConfig = self::CHAT_MODELS[$model] ?? self::CHAT_MODELS['gpt-4o'];

        $inputCost = ($inputTokens / 1000) * $modelConfig['input_cost'];
        $outputCost = ($outputTokens / 1000) * $modelConfig['output_cost'];

        return $inputCost + $outputCost;
    }

    /**
     * {@inheritdoc}
     */
    public function embed(string|array $text, array $options = []): LlmResponse
    {
        $model = $options['model'] ?? 'text-embedding-3-small';
        $modelConfig = self::EMBEDDING_MODELS[$model] ?? self::EMBEDDING_MODELS['text-embedding-3-small'];

        $this->logger->debug('Generating embeddings', [
            'provider' => 'openai',
            'model' => $model,
            'input_type' => is_array($text) ? 'batch' : 'single',
        ]);

        try {
            $response = $this->httpClient->request('POST', self::BASE_URL . '/embeddings', [
                'headers' => $this->buildHeaders(),
                'json' => [
                    'model' => $modelConfig['name'],
                    'input' => $text,
                    'encoding_format' => 'float',
                ],
                'timeout' => self::DEFAULT_TIMEOUT,
            ]);

            $this->checkHttpError($response);

            $data = json_decode((string) $response->getBody(), true);

            return new LlmResponse(
                content: '', // No text content for embeddings
                model: $model,
                provider: 'openai',
                usage: [
                    'prompt_tokens' => $data['usage']['prompt_tokens'] ?? 0,
                    'total_tokens' => $data['usage']['total_tokens'] ?? 0,
                ],
                metadata: [
                    'embeddings' => array_map(
                        fn($item) => $item['embedding'],
                        $data['data']
                    ),
                    'dimensions' => $modelConfig['dimensions'],
                ]
            );

        } catch (\Throwable $e) {
            throw new ProviderException(
                sprintf('Embedding generation failed: %s', $e->getMessage()),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function analyzeImage(string $imageUrl, string $prompt, array $options = []): LlmResponse
    {
        $model = $options['model'] ?? 'gpt-4o';
        $maxTokens = $options['max_tokens'] ?? 300;

        $request = new LlmRequest(
            model: $model,
            messages: [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $prompt,
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => $imageUrl,
                                'detail' => $options['detail'] ?? 'auto',
                            ],
                        ],
                    ],
                ],
            ],
            maxTokens: $maxTokens,
            temperature: $options['temperature'] ?? 0.7
        );

        return $this->complete($request);
    }

    /**
     * {@inheritdoc}
     */
    protected function getBaseUrl(): string
    {
        return $this->config['base_url'] ?? self::BASE_URL;
    }

    /**
     * {@inheritdoc}
     */
    protected function buildHeaders(bool $isStreaming = false): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ];

        if (!empty($this->config['organization'])) {
            $headers['OpenAI-Organization'] = $this->config['organization'];
        }

        if ($isStreaming) {
            $headers['Accept'] = 'text/event-stream';
        }

        return $headers;
    }

    /**
     * {@inheritdoc}
     */
    protected function transformRequest(LlmRequest $request): array
    {
        $model = $this->normalizeModelName($request->getModel());
        $modelConfig = self::CHAT_MODELS[$model] ?? self::CHAT_MODELS['gpt-4o'];

        $requestData = [
            'model' => $modelConfig['name'],
            'messages' => $this->buildMessages($request),
            'temperature' => $request->getTemperature(),
            'max_tokens' => min($request->getMaxTokens(), $modelConfig['max_output_tokens']),
        ];

        if ($request->getTopP() !== null) {
            $requestData['top_p'] = $request->getTopP();
        }

        if ($request->getFrequencyPenalty() !== null) {
            $requestData['frequency_penalty'] = $request->getFrequencyPenalty();
        }

        if ($request->getPresencePenalty() !== null) {
            $requestData['presence_penalty'] = $request->getPresencePenalty();
        }

        if ($request->getResponseFormat() === 'json') {
            $requestData['response_format'] = ['type' => 'json_object'];
        }

        return $requestData;
    }

    /**
     * Build OpenAI messages array
     *
     * @return array<array<string, mixed>>
     */
    private function buildMessages(LlmRequest $request): array
    {
        $messages = [];

        if ($request->getSystemPrompt()) {
            $messages[] = [
                'role' => 'system',
                'content' => $request->getSystemPrompt(),
            ];
        }

        foreach ($request->getMessages() as $message) {
            $messages[] = [
                'role' => $message['role'],
                'content' => $message['content'],
            ];
        }

        return $messages;
    }

    /**
     * {@inheritdoc}
     */
    protected function transformResponse(array $response): LlmResponse
    {
        $choice = $response['choices'][0] ?? null;

        if ($choice === null) {
            throw new ProviderException('No choices in response');
        }

        return new LlmResponse(
            content: $choice['message']['content'] ?? '',
            model: $response['model'],
            provider: 'openai',
            usage: [
                'prompt_tokens' => $response['usage']['prompt_tokens'] ?? 0,
                'completion_tokens' => $response['usage']['completion_tokens'] ?? 0,
                'total_tokens' => $response['usage']['total_tokens'] ?? 0,
            ],
            metadata: [
                'id' => $response['id'] ?? null,
                'finish_reason' => $choice['finish_reason'] ?? null,
                'created' => $response['created'] ?? null,
            ]
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function parseStreamChunk(string $line): ?array
    {
        if (!str_starts_with($line, 'data: ')) {
            return null;
        }

        $data = substr($line, 6);

        if ($data === '[DONE]') {
            return null;
        }

        $chunk = json_decode($data, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        $delta = $chunk['choices'][0]['delta'] ?? null;

        if ($delta === null || !isset($delta['content'])) {
            return null;
        }

        return [
            'content' => $delta['content'],
            'metadata' => [
                'finish_reason' => $chunk['choices'][0]['finish_reason'] ?? null,
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function handleError(ResponseInterface $response): never
    {
        $statusCode = $response->getStatusCode();
        $body = json_decode((string) $response->getBody(), true);
        $errorMessage = $body['error']['message'] ?? 'Unknown error';
        $errorType = $body['error']['type'] ?? 'unknown';

        // Rate limit handling
        if ($statusCode === 429) {
            $headers = $response->getHeaders();
            $retryAfter = $this->extractRetryAfter($headers);
            $remainingRequests = (int) ($headers['x-ratelimit-remaining-requests'][0] ?? 0);
            $remainingTokens = (int) ($headers['x-ratelimit-remaining-tokens'][0] ?? 0);

            throw new RateLimitException(
                sprintf('OpenAI rate limit exceeded: %s', $errorMessage),
                $retryAfter,
                $remainingRequests,
                $remainingTokens
            );
        }

        // Other errors
        throw new ProviderException(
            sprintf('OpenAI API error (%s): %s', $errorType, $errorMessage),
            $statusCode
        );
    }

    /**
     * Extract retry-after time from headers
     */
    private function extractRetryAfter(array $headers): int
    {
        $resetRequests = $headers['x-ratelimit-reset-requests'][0] ?? null;

        if ($resetRequests && preg_match('/(\d+)m(\d+)s/', $resetRequests, $matches)) {
            return ($matches[1] * 60) + $matches[2];
        }

        return 60; // Default: 1 minute
    }

    /**
     * Check for HTTP errors and handle them
     */
    private function checkHttpError(ResponseInterface $response): void
    {
        if ($response->getStatusCode() >= 400) {
            $this->handleError($response);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function complete(LlmRequest $request): LlmResponse
    {
        $requestBody = $this->transformRequest($request);

        $this->logger->debug('Sending OpenAI completion request', [
            'model' => $request->getModel(),
            'estimated_input_tokens' => $this->estimateInputTokens($request),
        ]);

        try {
            $response = $this->httpClient->request('POST', $this->getBaseUrl() . '/chat/completions', [
                'headers' => $this->buildHeaders(),
                'json' => $requestBody,
                'timeout' => self::DEFAULT_TIMEOUT,
            ]);

            $this->checkHttpError($response);

            $responseData = json_decode((string) $response->getBody(), true);

            return $this->transformResponse($responseData);

        } catch (\Throwable $e) {
            if ($e instanceof ProviderException || $e instanceof RateLimitException) {
                throw $e;
            }

            throw new ProviderException(
                sprintf('OpenAI request failed: %s', $e->getMessage()),
                (int) $e->getCode(),
                $e
            );
        }
    }
}
```

### 5.2 Anthropic Provider Implementation

```php
<?php
declare(strict_types=1);

namespace Netresearch\AiBase\Service\Provider;

use Netresearch\AiBase\Domain\Model\LlmRequest;
use Netresearch\AiBase\Domain\Model\LlmResponse;
use Netresearch\AiBase\Exception\ProviderException;
use Netresearch\AiBase\Exception\RateLimitException;
use Netresearch\AiBase\Exception\ProviderOverloadedException;
use Psr\Http\Message\ResponseInterface;

final class AnthropicProvider extends AbstractProvider
{
    private const BASE_URL = 'https://api.anthropic.com/v1';
    private const API_VERSION = '2023-06-01';

    private const MODELS = [
        'claude-3-5-sonnet-20241022' => [
            'name' => 'claude-3-5-sonnet-20241022',
            'display_name' => 'Claude 3.5 Sonnet',
            'context_window' => 200000,
            'max_output_tokens' => 8192,
            'input_cost' => 0.003,
            'output_cost' => 0.015,
            'supports_vision' => true,
        ],
        'claude-3-5-haiku-20241022' => [
            'name' => 'claude-3-5-haiku-20241022',
            'display_name' => 'Claude 3.5 Haiku',
            'context_window' => 200000,
            'max_output_tokens' => 8192,
            'input_cost' => 0.0008,
            'output_cost' => 0.004,
            'supports_vision' => false,
        ],
        'claude-3-opus-20240229' => [
            'name' => 'claude-3-opus-20240229',
            'display_name' => 'Claude 3 Opus',
            'context_window' => 200000,
            'max_output_tokens' => 4096,
            'input_cost' => 0.015,
            'output_cost' => 0.075,
            'supports_vision' => true,
        ],
        'claude-3-sonnet-20240229' => [
            'name' => 'claude-3-sonnet-20240229',
            'display_name' => 'Claude 3 Sonnet',
            'context_window' => 200000,
            'max_output_tokens' => 4096,
            'input_cost' => 0.003,
            'output_cost' => 0.015,
            'supports_vision' => true,
        ],
        'claude-3-haiku-20240307' => [
            'name' => 'claude-3-haiku-20240307',
            'display_name' => 'Claude 3 Haiku',
            'context_window' => 200000,
            'max_output_tokens' => 4096,
            'input_cost' => 0.00025,
            'output_cost' => 0.00125,
            'supports_vision' => true,
        ],
    ];

    private const MODEL_ALIASES = [
        'claude' => 'claude-3-5-sonnet-20241022',
        'claude-3' => 'claude-3-5-sonnet-20241022',
        'claude-3.5' => 'claude-3-5-sonnet-20241022',
        'claude-sonnet' => 'claude-3-5-sonnet-20241022',
        'claude-opus' => 'claude-3-opus-20240229',
        'claude-haiku' => 'claude-3-5-haiku-20241022',
        'default' => 'claude-3-5-sonnet-20241022',
        'fast' => 'claude-3-5-haiku-20241022',
        'smart' => 'claude-3-opus-20240229',
    ];

    /**
     * {@inheritdoc}
     */
    public function getIdentifier(): string
    {
        return 'anthropic';
    }

    /**
     * {@inheritdoc}
     */
    public function getCapabilities(): array
    {
        return [
            'chat' => true,
            'vision' => true,
            'embeddings' => false, // Anthropic doesn't provide embeddings
            'streaming' => true,
            'function_calling' => true, // Via tool use
            'json_mode' => false,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function normalizeModelName(string $modelAlias): string
    {
        $normalized = strtolower(trim($modelAlias));

        if (isset(self::MODEL_ALIASES[$normalized])) {
            return self::MODEL_ALIASES[$normalized];
        }

        if (isset(self::MODELS[$normalized])) {
            return self::MODELS[$normalized]['name'];
        }

        return $normalized;
    }

    /**
     * {@inheritdoc}
     */
    public function estimateCost(int $inputTokens, int $outputTokens, ?string $model = null): float
    {
        $model = $model ?? 'claude-3-5-sonnet-20241022';
        $modelConfig = self::MODELS[$model] ?? self::MODELS['claude-3-5-sonnet-20241022'];

        $inputCost = ($inputTokens / 1000) * $modelConfig['input_cost'];
        $outputCost = ($outputTokens / 1000) * $modelConfig['output_cost'];

        return $inputCost + $outputCost;
    }

    /**
     * {@inheritdoc}
     */
    public function embed(string|array $text, array $options = []): LlmResponse
    {
        throw new ProviderException('Anthropic does not support embeddings. Use OpenAI or another provider.');
    }

    /**
     * {@inheritdoc}
     */
    public function analyzeImage(string $imageUrl, string $prompt, array $options = []): LlmResponse
    {
        $model = $options['model'] ?? 'claude-3-5-sonnet-20241022';
        $maxTokens = $options['max_tokens'] ?? 1024;

        // Determine if URL or base64
        $imageSource = $this->buildImageSource($imageUrl);

        $request = new LlmRequest(
            model: $model,
            messages: [
                [
                    'role' => 'user',
                    'content' => [
                        $imageSource,
                        [
                            'type' => 'text',
                            'text' => $prompt,
                        ],
                    ],
                ],
            ],
            maxTokens: $maxTokens,
            temperature: $options['temperature'] ?? 0.7
        );

        return $this->complete($request);
    }

    /**
     * Build image source object for Anthropic API
     *
     * @return array<string, mixed>
     */
    private function buildImageSource(string $imageUrl): array
    {
        // Check if base64 encoded
        if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $imageUrl, $matches)) {
            return [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => 'image/' . $matches[1],
                    'data' => $matches[2],
                ],
            ];
        }

        // URL format
        return [
            'type' => 'image',
            'source' => [
                'type' => 'url',
                'url' => $imageUrl,
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function getBaseUrl(): string
    {
        return $this->config['base_url'] ?? self::BASE_URL;
    }

    /**
     * {@inheritdoc}
     */
    protected function buildHeaders(bool $isStreaming = false): array
    {
        $headers = [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => self::API_VERSION,
            'Content-Type' => 'application/json',
        ];

        if ($isStreaming) {
            $headers['Accept'] = 'text/event-stream';
        }

        return $headers;
    }

    /**
     * {@inheritdoc}
     */
    protected function transformRequest(LlmRequest $request): array
    {
        $model = $this->normalizeModelName($request->getModel());
        $modelConfig = self::MODELS[$model] ?? self::MODELS['claude-3-5-sonnet-20241022'];

        $requestData = [
            'model' => $modelConfig['name'],
            'messages' => $this->buildMessages($request),
            'max_tokens' => min($request->getMaxTokens(), $modelConfig['max_output_tokens']),
            'temperature' => $request->getTemperature(),
        ];

        if ($request->getSystemPrompt()) {
            $requestData['system'] = $request->getSystemPrompt();
        }

        if ($request->getTopP() !== null) {
            $requestData['top_p'] = $request->getTopP();
        }

        if ($request->getTopK() !== null) {
            $requestData['top_k'] = $request->getTopK();
        }

        return $requestData;
    }

    /**
     * Build Anthropic messages array
     *
     * @return array<array<string, mixed>>
     */
    private function buildMessages(LlmRequest $request): array
    {
        $messages = [];

        foreach ($request->getMessages() as $message) {
            $messages[] = [
                'role' => $message['role'],
                'content' => $message['content'],
            ];
        }

        return $messages;
    }

    /**
     * {@inheritdoc}
     */
    protected function transformResponse(array $response): LlmResponse
    {
        $content = '';

        foreach ($response['content'] as $block) {
            if ($block['type'] === 'text') {
                $content .= $block['text'];
            }
        }

        return new LlmResponse(
            content: $content,
            model: $response['model'],
            provider: 'anthropic',
            usage: [
                'prompt_tokens' => $response['usage']['input_tokens'] ?? 0,
                'completion_tokens' => $response['usage']['output_tokens'] ?? 0,
                'total_tokens' => ($response['usage']['input_tokens'] ?? 0) + ($response['usage']['output_tokens'] ?? 0),
            ],
            metadata: [
                'id' => $response['id'] ?? null,
                'finish_reason' => $response['stop_reason'] ?? null,
                'stop_sequence' => $response['stop_sequence'] ?? null,
            ]
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function parseStreamChunk(string $line): ?array
    {
        // Anthropic uses "event: type" and "data: json" format
        static $currentEvent = null;

        if (str_starts_with($line, 'event: ')) {
            $currentEvent = trim(substr($line, 7));
            return null;
        }

        if (!str_starts_with($line, 'data: ')) {
            return null;
        }

        $data = substr($line, 6);
        $chunk = json_decode($data, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        // Handle content_block_delta events
        if ($currentEvent === 'content_block_delta' && $chunk['type'] === 'content_block_delta') {
            $delta = $chunk['delta'] ?? null;

            if ($delta && $delta['type'] === 'text_delta') {
                return [
                    'content' => $delta['text'],
                ];
            }
        }

        // Handle message_delta for final usage stats
        if ($currentEvent === 'message_delta' && isset($chunk['usage'])) {
            return [
                'content' => '',
                'metadata' => [
                    'usage' => $chunk['usage'],
                    'finish_reason' => $chunk['delta']['stop_reason'] ?? null,
                ],
            ];
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    protected function handleError(ResponseInterface $response): never
    {
        $statusCode = $response->getStatusCode();
        $body = json_decode((string) $response->getBody(), true);
        $errorMessage = $body['error']['message'] ?? 'Unknown error';
        $errorType = $body['error']['type'] ?? 'unknown';

        // Rate limit handling (429)
        if ($statusCode === 429) {
            $headers = $response->getHeaders();
            $retryAfter = (int) ($headers['retry-after'][0] ?? 60);
            $remainingRequests = (int) ($headers['anthropic-ratelimit-requests-remaining'][0] ?? 0);
            $remainingTokens = (int) ($headers['anthropic-ratelimit-tokens-remaining'][0] ?? 0);

            throw new RateLimitException(
                sprintf('Anthropic rate limit exceeded: %s', $errorMessage),
                $retryAfter,
                $remainingRequests,
                $remainingTokens
            );
        }

        // Overloaded (529)
        if ($statusCode === 529) {
            throw new ProviderOverloadedException(
                'Anthropic service is currently overloaded. Please retry later.',
                30
            );
        }

        // Other errors
        throw new ProviderException(
            sprintf('Anthropic API error (%s): %s', $errorType, $errorMessage),
            $statusCode
        );
    }

    /**
     * {@inheritdoc}
     */
    public function complete(LlmRequest $request): LlmResponse
    {
        $requestBody = $this->transformRequest($request);

        $this->logger->debug('Sending Anthropic messages request', [
            'model' => $request->getModel(),
            'estimated_input_tokens' => $this->estimateInputTokens($request),
        ]);

        try {
            $response = $this->httpClient->request('POST', $this->getBaseUrl() . '/messages', [
                'headers' => $this->buildHeaders(),
                'json' => $requestBody,
                'timeout' => self::DEFAULT_TIMEOUT,
            ]);

            if ($response->getStatusCode() >= 400) {
                $this->handleError($response);
            }

            $responseData = json_decode((string) $response->getBody(), true);

            return $this->transformResponse($responseData);

        } catch (\Throwable $e) {
            if ($e instanceof ProviderException || $e instanceof RateLimitException) {
                throw $e;
            }

            throw new ProviderException(
                sprintf('Anthropic request failed: %s', $e->getMessage()),
                (int) $e->getCode(),
                $e
            );
        }
    }
}
```

---

## 6. Domain Models

### 6.1 LlmRequest Model

```php
<?php
declare(strict_types=1);

namespace Netresearch\AiBase\Domain\Model;

final class LlmRequest
{
    /**
     * @param string $model Model identifier or alias
     * @param array<array<string, mixed>> $messages Conversation messages
     * @param string|null $systemPrompt System instruction
     * @param int $maxTokens Maximum completion tokens
     * @param float $temperature Sampling temperature (0-2)
     * @param float|null $topP Nucleus sampling parameter
     * @param int|null $topK Top-k sampling parameter (Anthropic)
     * @param float|null $frequencyPenalty Frequency penalty (OpenAI)
     * @param float|null $presencePenalty Presence penalty (OpenAI)
     * @param string|null $responseFormat Response format (text, json)
     * @param array<string, mixed> $metadata Additional metadata
     */
    public function __construct(
        private readonly string $model,
        private readonly array $messages = [],
        private readonly ?string $systemPrompt = null,
        private readonly int $maxTokens = 1000,
        private readonly float $temperature = 0.7,
        private readonly ?float $topP = null,
        private readonly ?int $topK = null,
        private readonly ?float $frequencyPenalty = null,
        private readonly ?float $presencePenalty = null,
        private readonly ?string $responseFormat = null,
        private readonly array $metadata = []
    ) {
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function getSystemPrompt(): ?string
    {
        return $this->systemPrompt;
    }

    public function getMaxTokens(): int
    {
        return $this->maxTokens;
    }

    public function getTemperature(): float
    {
        return $this->temperature;
    }

    public function getTopP(): ?float
    {
        return $this->topP;
    }

    public function getTopK(): ?int
    {
        return $this->topK;
    }

    public function getFrequencyPenalty(): ?float
    {
        return $this->frequencyPenalty;
    }

    public function getPresencePenalty(): ?float
    {
        return $this->presencePenalty;
    }

    public function getResponseFormat(): ?string
    {
        return $this->responseFormat;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
```

### 6.2 LlmResponse Model

```php
<?php
declare(strict_types=1);

namespace Netresearch\AiBase\Domain\Model;

final class LlmResponse
{
    /**
     * @param string $content Generated content
     * @param string $model Model used for generation
     * @param string $provider Provider identifier
     * @param array<string, int> $usage Token usage statistics
     * @param array<string, mixed> $metadata Additional response metadata
     */
    public function __construct(
        private readonly string $content,
        private readonly string $model,
        private readonly string $provider,
        private readonly array $usage = [],
        private readonly array $metadata = []
    ) {
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getUsage(): array
    {
        return $this->usage;
    }

    public function getInputTokens(): int
    {
        return $this->usage['prompt_tokens'] ?? 0;
    }

    public function getOutputTokens(): int
    {
        return $this->usage['completion_tokens'] ?? 0;
    }

    public function getTotalTokens(): int
    {
        return $this->usage['total_tokens'] ??
               ($this->getInputTokens() + $this->getOutputTokens());
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getFinishReason(): ?string
    {
        return $this->metadata['finish_reason'] ?? null;
    }

    public function getEmbeddings(): ?array
    {
        return $this->metadata['embeddings'] ?? null;
    }
}
```

---

## 7. Exception Classes

```php
<?php
declare(strict_types=1);

namespace Netresearch\AiBase\Exception;

class ProviderException extends \RuntimeException
{
}

class RateLimitException extends ProviderException
{
    public function __construct(
        string $message,
        private readonly int $retryAfter,
        private readonly int $remainingRequests = 0,
        private readonly int $remainingTokens = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 429, $previous);
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }

    public function getRemainingRequests(): int
    {
        return $this->remainingRequests;
    }

    public function getRemainingTokens(): int
    {
        return $this->remainingTokens;
    }
}

class ProviderOverloadedException extends ProviderException
{
    public function __construct(
        string $message,
        private readonly int $retryAfter = 30,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 529, $previous);
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}
```

---

## 8. Unit Tests

### 8.1 OpenAI Provider Tests

```php
<?php
declare(strict_types=1);

namespace Netresearch\AiBase\Tests\Unit\Service\Provider;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Netresearch\AiBase\Domain\Model\LlmRequest;
use Netresearch\AiBase\Service\Provider\OpenAiProvider;
use Netresearch\AiBase\Exception\RateLimitException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class OpenAiProviderTest extends TestCase
{
    private ClientInterface $httpClient;
    private OpenAiProvider $provider;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(ClientInterface::class);
        $this->provider = new OpenAiProvider(
            $this->httpClient,
            new NullLogger(),
            'test-api-key'
        );
    }

    public function testCompleteSuccess(): void
    {
        $mockResponse = new Response(200, [], json_encode([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'created' => 1677858242,
            'model' => 'gpt-4o-2024-11-20',
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Hello! How can I help you?',
                    ],
                    'finish_reason' => 'stop',
                    'index' => 0,
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 8,
                'total_tokens' => 18,
            ],
        ]));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($mockResponse);

        $request = new LlmRequest(
            model: 'gpt-4o',
            messages: [
                ['role' => 'user', 'content' => 'Hello'],
            ]
        );

        $response = $this->provider->complete($request);

        $this->assertSame('Hello! How can I help you?', $response->getContent());
        $this->assertSame(10, $response->getInputTokens());
        $this->assertSame(8, $response->getOutputTokens());
        $this->assertSame('openai', $response->getProvider());
    }

    public function testRateLimitHandling(): void
    {
        $mockResponse = new Response(429, [
            'x-ratelimit-remaining-requests' => ['0'],
            'x-ratelimit-reset-requests' => ['2m30s'],
        ], json_encode([
            'error' => [
                'message' => 'Rate limit exceeded',
                'type' => 'rate_limit_error',
            ],
        ]));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($mockResponse);

        $request = new LlmRequest(
            model: 'gpt-4o',
            messages: [['role' => 'user', 'content' => 'Test']]
        );

        $this->expectException(RateLimitException::class);
        $this->expectExceptionMessage('Rate limit exceeded');

        $this->provider->complete($request);
    }

    public function testModelAliasNormalization(): void
    {
        $this->assertSame('gpt-4o', $this->provider->normalizeModelName('gpt4'));
        $this->assertSame('gpt-4o', $this->provider->normalizeModelName('default'));
        $this->assertSame('gpt-4o-mini', $this->provider->normalizeModelName('fast'));
        $this->assertSame('gpt-3.5-turbo', $this->provider->normalizeModelName('gpt35'));
    }

    public function testCostEstimation(): void
    {
        $cost = $this->provider->estimateCost(1000, 500, 'gpt-4o');

        // gpt-4o: $0.0025/1K input, $0.01/1K output
        // (1000/1000 * 0.0025) + (500/1000 * 0.01) = 0.0025 + 0.005 = 0.0075
        $this->assertEqualsWithDelta(0.0075, $cost, 0.0001);
    }

    public function testEmbeddingGeneration(): void
    {
        $mockResponse = new Response(200, [], json_encode([
            'object' => 'list',
            'data' => [
                [
                    'object' => 'embedding',
                    'embedding' => [0.1, 0.2, 0.3],
                    'index' => 0,
                ],
            ],
            'model' => 'text-embedding-3-small',
            'usage' => [
                'prompt_tokens' => 5,
                'total_tokens' => 5,
            ],
        ]));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($mockResponse);

        $response = $this->provider->embed('Hello world');

        $this->assertSame([0.1, 0.2, 0.3], $response->getEmbeddings()[0]);
        $this->assertSame(5, $response->getInputTokens());
    }
}
```

### 8.2 Anthropic Provider Tests

```php
<?php
declare(strict_types=1);

namespace Netresearch\AiBase\Tests\Unit\Service\Provider;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Netresearch\AiBase\Domain\Model\LlmRequest;
use Netresearch\AiBase\Service\Provider\AnthropicProvider;
use Netresearch\AiBase\Exception\RateLimitException;
use Netresearch\AiBase\Exception\ProviderException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class AnthropicProviderTest extends TestCase
{
    private ClientInterface $httpClient;
    private AnthropicProvider $provider;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(ClientInterface::class);
        $this->provider = new AnthropicProvider(
            $this->httpClient,
            new NullLogger(),
            'test-api-key'
        );
    }

    public function testCompleteSuccess(): void
    {
        $mockResponse = new Response(200, [], json_encode([
            'id' => 'msg_123',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Hello! How can I assist you?',
                ],
            ],
            'model' => 'claude-3-5-sonnet-20241022',
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => 12,
                'output_tokens' => 7,
            ],
        ]));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($mockResponse);

        $request = new LlmRequest(
            model: 'claude',
            messages: [
                ['role' => 'user', 'content' => 'Hello'],
            ]
        );

        $response = $this->provider->complete($request);

        $this->assertSame('Hello! How can I assist you?', $response->getContent());
        $this->assertSame(12, $response->getInputTokens());
        $this->assertSame(7, $response->getOutputTokens());
        $this->assertSame('anthropic', $response->getProvider());
    }

    public function testRateLimitHandling(): void
    {
        $mockResponse = new Response(429, [
            'retry-after' => ['60'],
            'anthropic-ratelimit-requests-remaining' => ['0'],
        ], json_encode([
            'type' => 'error',
            'error' => [
                'type' => 'rate_limit_error',
                'message' => 'Rate limit exceeded',
            ],
        ]));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($mockResponse);

        $request = new LlmRequest(
            model: 'claude',
            messages: [['role' => 'user', 'content' => 'Test']]
        );

        $this->expectException(RateLimitException::class);

        $this->provider->complete($request);
    }

    public function testEmbeddingNotSupported(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('does not support embeddings');

        $this->provider->embed('test text');
    }

    public function testModelAliasNormalization(): void
    {
        $this->assertSame('claude-3-5-sonnet-20241022', $this->provider->normalizeModelName('claude'));
        $this->assertSame('claude-3-5-sonnet-20241022', $this->provider->normalizeModelName('default'));
        $this->assertSame('claude-3-5-haiku-20241022', $this->provider->normalizeModelName('fast'));
        $this->assertSame('claude-3-opus-20240229', $this->provider->normalizeModelName('smart'));
    }

    public function testCostEstimation(): void
    {
        $cost = $this->provider->estimateCost(1000, 500, 'claude-3-5-sonnet-20241022');

        // Claude 3.5 Sonnet: $0.003/1K input, $0.015/1K output
        // (1000/1000 * 0.003) + (500/1000 * 0.015) = 0.003 + 0.0075 = 0.0105
        $this->assertEqualsWithDelta(0.0105, $cost, 0.0001);
    }
}
```

---

## 9. Integration Test Specifications

```php
<?php
declare(strict_types=1);

namespace Netresearch\AiBase\Tests\Integration\Service\Provider;

use Netresearch\AiBase\Domain\Model\LlmRequest;
use Netresearch\AiBase\Service\Provider\OpenAiProvider;
use Netresearch\AiBase\Service\Provider\AnthropicProvider;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Integration tests requiring actual API keys
 *
 * Set environment variables:
 * - OPENAI_API_KEY
 * - ANTHROPIC_API_KEY
 *
 * Skip if not set:
 * @group integration
 */
class ProviderIntegrationTest extends TestCase
{
    private ?string $openaiKey;
    private ?string $anthropicKey;

    protected function setUp(): void
    {
        $this->openaiKey = getenv('OPENAI_API_KEY') ?: null;
        $this->anthropicKey = getenv('ANTHROPIC_API_KEY') ?: null;
    }

    public function testOpenAiRealRequest(): void
    {
        if (!$this->openaiKey) {
            $this->markTestSkipped('OPENAI_API_KEY not set');
        }

        $provider = new OpenAiProvider(
            new Client(),
            new NullLogger(),
            $this->openaiKey
        );

        $request = new LlmRequest(
            model: 'gpt-4o-mini',
            messages: [
                ['role' => 'user', 'content' => 'Say "test successful" and nothing else'],
            ],
            maxTokens: 10
        );

        $response = $provider->complete($request);

        $this->assertStringContainsString('test successful', strtolower($response->getContent()));
        $this->assertGreaterThan(0, $response->getInputTokens());
        $this->assertGreaterThan(0, $response->getOutputTokens());
    }

    public function testAnthropicRealRequest(): void
    {
        if (!$this->anthropicKey) {
            $this->markTestSkipped('ANTHROPIC_API_KEY not set');
        }

        $provider = new AnthropicProvider(
            new Client(),
            new NullLogger(),
            $this->anthropicKey
        );

        $request = new LlmRequest(
            model: 'claude-3-5-haiku-20241022',
            messages: [
                ['role' => 'user', 'content' => 'Say "test successful" and nothing else'],
            ],
            maxTokens: 20
        );

        $response = $provider->complete($request);

        $this->assertStringContainsString('test successful', strtolower($response->getContent()));
        $this->assertGreaterThan(0, $response->getInputTokens());
        $this->assertGreaterThan(0, $response->getOutputTokens());
    }

    public function testStreamingOpenAi(): void
    {
        if (!$this->openaiKey) {
            $this->markTestSkipped('OPENAI_API_KEY not set');
        }

        $provider = new OpenAiProvider(
            new Client(),
            new NullLogger(),
            $this->openaiKey
        );

        $request = new LlmRequest(
            model: 'gpt-4o-mini',
            messages: [
                ['role' => 'user', 'content' => 'Count from 1 to 5'],
            ],
            maxTokens: 50
        );

        $chunks = [];
        $callback = function(string $chunk) use (&$chunks) {
            $chunks[] = $chunk;
        };

        $response = $provider->stream($request, $callback);

        $this->assertGreaterThan(3, count($chunks)); // Should have multiple chunks
        $this->assertNotEmpty($response->getContent());
    }
}
```

---

## 10. Configuration Examples

### 10.1 Services.yaml

```yaml
services:
  _defaults:
    autowire: true
    autoconfigure: true
    public: false

  # HTTP Client for providers
  Netresearch\AiBase\Http\ProviderHttpClient:
    class: GuzzleHttp\Client
    public: true
    arguments:
      - timeout: 60
        connect_timeout: 10
        http_errors: false

  # OpenAI Provider
  Netresearch\AiBase\Service\Provider\OpenAiProvider:
    public: true
    arguments:
      $httpClient: '@Netresearch\AiBase\Http\ProviderHttpClient'
      $logger: '@logger'
      $apiKey: '%env(OPENAI_API_KEY)%'
      $config:
        base_url: 'https://api.openai.com/v1'
        organization: '%env(OPENAI_ORGANIZATION)%'
    tags:
      - { name: 'ai.provider', identifier: 'openai' }

  # Anthropic Provider
  Netresearch\AiBase\Service\Provider\AnthropicProvider:
    public: true
    arguments:
      $httpClient: '@Netresearch\AiBase\Http\ProviderHttpClient'
      $logger: '@logger'
      $apiKey: '%env(ANTHROPIC_API_KEY)%'
      $config:
        base_url: 'https://api.anthropic.com/v1'
    tags:
      - { name: 'ai.provider', identifier: 'anthropic' }

  # Provider Factory
  Netresearch\AiBase\Service\Provider\ProviderFactory:
    public: true
    arguments:
      $providers: !tagged_iterator ai.provider
```

---

## 11. Summary & Next Steps

### Deliverables Completed

✅ **OpenAI Provider**
- Full API integration (chat, embeddings, vision)
- Model catalog with pricing
- Model aliasing system
- Streaming support with SSE parsing
- Rate limit handling (429 responses)
- Token estimation
- Error normalization
- Unit tests with mocks

✅ **Anthropic Provider**
- Full messages API integration
- Claude 3 family support
- Native vision support
- Streaming with event-based SSE
- Rate limit and overload handling
- Token counting from responses
- Error normalization
- Unit tests with mocks

✅ **Common Architecture**
- ProviderInterface abstraction
- AbstractProvider base class
- LlmRequest/LlmResponse models
- Exception hierarchy
- PSR-18 HTTP client usage
- Integration test specifications

### Implementation Checklist

**Phase 1: Core Implementation**
- [ ] Create domain models (LlmRequest, LlmResponse)
- [ ] Create exception classes
- [ ] Implement AbstractProvider base class
- [ ] Implement OpenAiProvider
- [ ] Implement AnthropicProvider
- [ ] Write unit tests

**Phase 2: Integration**
- [ ] Create Services.yaml configuration
- [ ] Set up HTTP client factory
- [ ] Configure environment variables
- [ ] Write integration tests
- [ ] Test with real API keys

**Phase 3: Optimization**
- [ ] Add response caching layer
- [ ] Implement retry logic
- [ ] Add request logging
- [ ] Performance profiling
- [ ] Token counting optimization (tiktoken integration)

**Phase 4: Documentation**
- [ ] API documentation
- [ ] Usage examples
- [ ] Configuration guide
- [ ] Migration guide from direct API usage

### File Locations

```
Classes/
├── Domain/
│   └── Model/
│       ├── LlmRequest.php
│       └── LlmResponse.php
├── Exception/
│   ├── ProviderException.php
│   ├── RateLimitException.php
│   └── ProviderOverloadedException.php
└── Service/
    └── Provider/
        ├── ProviderInterface.php
        ├── AbstractProvider.php
        ├── OpenAiProvider.php
        └── AnthropicProvider.php

Tests/
├── Unit/
│   └── Service/
│       └── Provider/
│           ├── OpenAiProviderTest.php
│           └── AnthropicProviderTest.php
└── Integration/
    └── Service/
        └── Provider/
            └── ProviderIntegrationTest.php

Configuration/
└── Services.yaml
```

---

## Document Metadata

**Created**: 2025-12-22
**Status**: Implementation Ready
**Target**: MVP 0.1 (OpenAI), MVP 0.2 (Anthropic)
**Dependencies**: PSR-18 HTTP client, PSR-3 Logger
**Test Coverage Target**: 85%+
