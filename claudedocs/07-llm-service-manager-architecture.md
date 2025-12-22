# LlmServiceManager Architecture Design

> Document: nr_llm Extension - Main Facade Design
> Date: 2025-12-22
> Status: Implementation Ready

---

## 1. Executive Summary

This document defines the **LlmServiceManager** - the primary public API for the `nr_llm` TYPO3 extension. It serves as the stable, simple entry point for all consuming extensions (textdb, rte-ckeditor-image, contexts).

### Design Principles

1. **Simplicity First**: Single facade for all LLM operations
2. **Stable API**: Public methods won't change between versions
3. **Provider Agnostic**: Swap providers without code changes
4. **Safety First**: Built-in validation, rate limiting, error handling
5. **Developer Friendly**: Clear methods, fluent interfaces, intuitive defaults

---

## 2. Architecture Overview

```
┌─────────────────────────────────────────────────────┐
│         Consuming Extensions Layer                   │
│  (textdb, rte-ckeditor-image, contexts)             │
└────────────────────┬────────────────────────────────┘
                     │
┌────────────────────▼────────────────────────────────┐
│           LlmServiceManager (Main Facade)            │
│  • complete()   • stream()   • translate()          │
│  • analyzeImage()   • embed()                       │
│  • setProvider()    • getProvider()                 │
└─┬──────────────────┬──────────────────────────┬────┘
  │                  │                          │
┌─▼────────┐  ┌─────▼──────┐  ┌──────────────▼──────┐
│ Request  │  │ Response   │  │  Stream             │
│ Builder  │  │ Parser     │  │  Handler            │
└──────────┘  └────────────┘  └─────────────────────┘
  │                  │                          │
┌─▼──────────────────▼──────────────────────────▼────┐
│         ProviderInterface Implementation            │
│  OpenAI │ Anthropic │ Gemini │ DeepL │ Ollama      │
└─────────────────────────────────────────────────────┘
```

---

## 3. Core Classes

### 3.1 LlmServiceManager

**Purpose**: Main entry point for all LLM operations

**Responsibilities**:
- Route requests to appropriate providers
- Handle caching transparently
- Apply rate limiting and quotas
- Provide fluent interface for configuration
- Normalize responses across providers

**Public API Contract**:
```php
// Completion requests
complete(string $prompt, array $options = []): LlmResponse
stream(string $prompt, callable $callback, array $options = []): void

// Specialized features
translate(string $text, string $targetLang, ?string $sourceLang = null): TranslationResponse
analyzeImage(string $imageUrl, string $prompt): VisionResponse
embed(string|array $text): EmbeddingResponse

// Provider management
setProvider(string $providerName): self
getProvider(): ProviderInterface
getAvailableProviders(): array
getDefaultProvider(): string

// Configuration
withOptions(array $options): self
withCache(bool $enabled, ?int $ttl = null): self
withRateLimit(bool $enabled): self
```

### 3.2 RequestBuilder

**Purpose**: Fluent interface for building validated requests

**Responsibilities**:
- Validate parameters before sending
- Merge default options intelligently
- Convert between different parameter formats
- Handle special cases (images, arrays)

**Public API**:
```php
prompt(string $prompt): self
systemPrompt(string $systemPrompt): self
model(string $model): self
temperature(float $temp): self
maxTokens(int $tokens): self
stopSequences(array $sequences): self
responseFormat(string $format): self  // 'text', 'json', 'markdown'
images(array $images): self
build(): array
validate(): bool
```

### 3.3 ResponseParser

**Purpose**: Normalize provider responses to consistent format

**Responsibilities**:
- Extract content from different response structures
- Parse token usage data
- Handle streaming chunks
- Extract metadata (model, finish_reason)
- Normalize errors

**Public API**:
```php
parse(array|object $rawResponse, string $providerName): LlmResponse
parseStream(string $chunk, string $providerName): ?StreamChunk
parseError(array|object $errorResponse): LlmException
```

### 3.4 StreamHandler

**Purpose**: Manage streaming responses efficiently

**Responsibilities**:
- Handle SSE (Server-Sent Events) streams
- Buffer management for partial chunks
- Connection timeout handling
- Progress callbacks
- Error recovery

**Public API**:
```php
handle(resource $stream, callable $callback): void
setBufferSize(int $bytes): self
setTimeout(int $seconds): self
onProgress(callable $callback): self
onComplete(callable $callback): self
onError(callable $callback): self
```

---

## 4. Response Models

### 4.1 LlmResponse

```php
class LlmResponse
{
    public function __construct(
        private string $content,
        private ?TokenUsage $usage = null,
        private ?array $metadata = null,
        private ?string $finishReason = null
    ) {}

    public function getContent(): string
    public function getUsage(): ?TokenUsage
    public function getMetadata(?string $key = null): mixed
    public function getFinishReason(): ?string
    public function toArray(): array
    public function toJson(): string

    // Convenience methods
    public function isEmpty(): bool
    public function hasUsageData(): bool
    public function getPromptTokens(): int
    public function getCompletionTokens(): int
    public function getTotalTokens(): int
    public function getCostEstimate(): float
}
```

### 4.2 TranslationResponse

```php
class TranslationResponse extends LlmResponse
{
    public function __construct(
        private string $translation,
        private ?float $confidence = null,
        private ?array $alternatives = null,
        ...$parentArgs
    ) {
        parent::__construct($translation, ...$parentArgs);
    }

    public function getTranslation(): string
    public function getConfidence(): ?float
    public function getAlternatives(): array
    public function hasAlternatives(): bool
}
```

### 4.3 VisionResponse

```php
class VisionResponse extends LlmResponse
{
    public function __construct(
        private string $description,
        private ?array $objects = null,
        private ?array $scene = null,
        private ?float $confidence = null,
        ...$parentArgs
    ) {
        parent::__construct($description, ...$parentArgs);
    }

    public function getDescription(): string
    public function getObjects(): array
    public function getScene(): array
    public function getConfidence(): ?float
}
```

### 4.4 EmbeddingResponse

```php
class EmbeddingResponse extends LlmResponse
{
    public function __construct(
        private array $embeddings,
        private ?string $model = null,
        ...$parentArgs
    ) {
        parent::__construct('', ...$parentArgs);
    }

    public function getEmbeddings(): array
    public function getEmbedding(): array  // Single embedding if only one input
    public function getDimensions(): int
    public function getModel(): ?string
}
```

### 4.5 TokenUsage

```php
class TokenUsage
{
    public function __construct(
        private int $promptTokens,
        private int $completionTokens,
        private int $totalTokens
    ) {}

    public function getPromptTokens(): int
    public function getCompletionTokens(): int
    public function getTotalTokens(): int
    public function toArray(): array
}
```

### 4.6 StreamChunk

```php
class StreamChunk
{
    public function __construct(
        private string $content,
        private bool $isComplete = false,
        private ?string $finishReason = null
    ) {}

    public function getContent(): string
    public function isComplete(): bool
    public function getFinishReason(): ?string
}
```

---

## 5. Error Handling Strategy

### Exception Hierarchy

```
LlmException (base)
├── ProviderException (provider-specific errors)
│   ├── ProviderConnectionException (network/timeout)
│   ├── ProviderAuthenticationException (invalid API key)
│   ├── ProviderQuotaException (rate limits, usage limits)
│   └── ProviderResponseException (malformed response)
├── ValidationException (invalid request parameters)
├── ConfigurationException (missing/invalid config)
├── QuotaExceededException (local quota limits)
└── UnsupportedFeatureException (provider doesn't support feature)
```

### When to Retry vs Fail

**Auto-Retry** (with exponential backoff):
- Network timeouts
- 5xx server errors
- Rate limit errors (with backoff)

**Fail Immediately**:
- Authentication errors
- Validation errors
- Quota exceeded (local)
- Unsupported features

**Report to Caller**:
- All exceptions bubble up with context
- Original provider error preserved in metadata
- Suggestions for resolution included

### Error Context

```php
class LlmException extends \RuntimeException
{
    public function __construct(
        string $message,
        private ?string $providerName = null,
        private ?array $context = null,
        private ?string $suggestion = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getProviderName(): ?string
    public function getContext(): array
    public function getSuggestion(): ?string
    public function toArray(): array
}
```

---

## 6. Usage Examples

### 6.1 Basic Completion (textdb)

```php
use Netresearch\NrLlm\Service\LlmServiceManager;

class TranslationSuggestionService
{
    public function __construct(
        private LlmServiceManager $llm
    ) {}

    public function suggestTranslation(string $text, string $targetLang): string
    {
        try {
            $response = $this->llm->translate(
                text: $text,
                targetLang: $targetLang,
                sourceLang: 'en'
            );

            return $response->getTranslation();
        } catch (QuotaExceededException $e) {
            // Handle quota exceeded
            throw new \RuntimeException('Translation quota exceeded', 0, $e);
        } catch (LlmException $e) {
            // Log error and return fallback
            $this->logger->error('LLM translation failed', [
                'error' => $e->getMessage(),
                'suggestion' => $e->getSuggestion()
            ]);
            return '';
        }
    }
}
```

### 6.2 Image Analysis (rte-ckeditor-image)

```php
use Netresearch\NrLlm\Service\LlmServiceManager;

class ImageAltTextService
{
    public function __construct(
        private LlmServiceManager $llm
    ) {}

    public function generateAltText(string $imageUrl): string
    {
        $response = $this->llm
            ->setProvider('openai')  // Force GPT-4V for vision
            ->analyzeImage(
                imageUrl: $imageUrl,
                prompt: 'Generate concise, descriptive alt text for accessibility (max 125 characters)'
            );

        return $response->getDescription();
    }

    public function analyzeImageFull(string $imageUrl): array
    {
        $response = $this->llm->analyzeImage(
            imageUrl: $imageUrl,
            prompt: 'Analyze this image and provide: alt text, objects detected, scene description, and dominant colors'
        );

        return [
            'altText' => $response->getDescription(),
            'objects' => $response->getObjects(),
            'scene' => $response->getScene(),
            'confidence' => $response->getConfidence()
        ];
    }
}
```

### 6.3 Natural Language Processing (contexts)

```php
use Netresearch\NrLlm\Service\LlmServiceManager;

class RuleGeneratorService
{
    public function __construct(
        private LlmServiceManager $llm
    ) {}

    public function generateRule(string $naturalLanguage): array
    {
        $prompt = $this->buildPrompt($naturalLanguage);

        $response = $this->llm
            ->withOptions([
                'response_format' => 'json',
                'temperature' => 0.3  // Lower for more deterministic output
            ])
            ->complete($prompt);

        return json_decode($response->getContent(), true);
    }

    private function buildPrompt(string $description): string
    {
        return <<<PROMPT
You are a TYPO3 contexts rule generator. Convert natural language to context configuration.

Available context types:
- domain: Match HTTP_HOST
- ip: Match client IP (CIDR notation)
- header: Match HTTP headers
- getparam: Match URL query parameters
- combination: Boolean expressions

User request: {$description}

Respond with JSON:
{
    "contextType": "...",
    "configuration": {...},
    "explanation": "...",
    "confidence": 0.0-1.0
}
PROMPT;
    }
}
```

### 6.4 Streaming Response

```php
use Netresearch\NrLlm\Service\LlmServiceManager;

class ContentGeneratorService
{
    public function __construct(
        private LlmServiceManager $llm
    ) {}

    public function generateWithProgress(string $prompt, callable $progressCallback): string
    {
        $fullContent = '';

        $this->llm->stream(
            prompt: $prompt,
            callback: function(StreamChunk $chunk) use (&$fullContent, $progressCallback) {
                $fullContent .= $chunk->getContent();
                $progressCallback($chunk->getContent(), $chunk->isComplete());
            },
            options: [
                'temperature' => 0.7,
                'max_tokens' => 2000
            ]
        );

        return $fullContent;
    }
}
```

### 6.5 Fluent API

```php
$response = $llm
    ->setProvider('anthropic')
    ->withCache(true, 3600)
    ->withRateLimit(true)
    ->withOptions([
        'model' => 'claude-3-sonnet-20240229',
        'temperature' => 0.8,
        'max_tokens' => 1000
    ])
    ->complete('Your prompt here');
```

---

## 7. Configuration (Services.yaml)

```yaml
services:
  _defaults:
    autowire: true
    autoconfigure: true
    public: false

  Netresearch\NrLlm\:
    resource: '../Classes/*'

  # Main facade (public)
  Netresearch\NrLlm\Service\LlmServiceManager:
    public: true
    arguments:
      $providerFactory: '@Netresearch\NrLlm\Service\Provider\ProviderFactory'
      $requestBuilder: '@Netresearch\NrLlm\Service\Request\RequestBuilder'
      $responseParser: '@Netresearch\NrLlm\Service\Response\ResponseParser'
      $streamHandler: '@Netresearch\NrLlm\Service\Stream\StreamHandler'
      $cache: '@cache.llm_responses'
      $rateLimiter: '@Netresearch\NrLlm\Service\RateLimit\RateLimiter'
      $logger: '@logger.llm'

  # Request builder
  Netresearch\NrLlm\Service\Request\RequestBuilder:
    public: false

  # Response parser
  Netresearch\NrLlm\Service\Response\ResponseParser:
    public: false

  # Stream handler
  Netresearch\NrLlm\Service\Stream\StreamHandler:
    public: false
    arguments:
      $bufferSize: 8192
      $timeout: 30

  # Provider factory
  Netresearch\NrLlm\Service\Provider\ProviderFactory:
    public: false
    arguments:
      $providers: !tagged_iterator llm.provider
      $configManager: '@Netresearch\NrLlm\Service\Configuration\ConfigurationManager'

  # Individual providers (tagged)
  Netresearch\NrLlm\Provider\OpenAiProvider:
    tags:
      - { name: 'llm.provider', identifier: 'openai', priority: 100 }

  Netresearch\NrLlm\Provider\AnthropicProvider:
    tags:
      - { name: 'llm.provider', identifier: 'anthropic', priority: 90 }

  Netresearch\NrLlm\Provider\GeminiProvider:
    tags:
      - { name: 'llm.provider', identifier: 'gemini', priority: 80 }

  # Cache
  cache.llm_responses:
    class: TYPO3\CMS\Core\Cache\Frontend\VariableFrontend
    factory: ['@TYPO3\CMS\Core\Cache\CacheManager', 'getCache']
    arguments: ['llm_responses']

  # Logger
  logger.llm:
    class: Psr\Log\LoggerInterface
    factory: ['@TYPO3\CMS\Core\Log\LogManager', 'getLogger']
    arguments: ['NrLlm']

  # Rate limiter
  Netresearch\NrLlm\Service\RateLimit\RateLimiter:
    public: false
    arguments:
      $configManager: '@Netresearch\NrLlm\Service\Configuration\ConfigurationManager'
```

---

## 8. Unit Tests Structure

### LlmServiceManagerTest.php

```php
class LlmServiceManagerTest extends \TYPO3\TestingFramework\Core\Unit\UnitTestCase
{
    private LlmServiceManager $subject;
    private ProviderInterface $mockProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockProvider = $this->createMock(ProviderInterface::class);
        $mockFactory = $this->createMock(ProviderFactory::class);
        $mockFactory->method('create')->willReturn($this->mockProvider);

        $this->subject = new LlmServiceManager(
            $mockFactory,
            new RequestBuilder(),
            new ResponseParser(),
            new StreamHandler(),
            $this->createMock(CacheFrontend::class),
            $this->createMock(RateLimiter::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * @test
     */
    public function completeCallsProviderWithCorrectParameters(): void
    {
        $prompt = 'Test prompt';
        $options = ['temperature' => 0.7];

        $this->mockProvider->expects($this->once())
            ->method('complete')
            ->with($prompt, $this->arrayHasKey('temperature'))
            ->willReturn(new CompletionResponse('Response', new TokenUsage(10, 20, 30)));

        $result = $this->subject->complete($prompt, $options);

        $this->assertInstanceOf(LlmResponse::class, $result);
        $this->assertEquals('Response', $result->getContent());
    }

    /**
     * @test
     */
    public function setProviderReturnsFluentInterface(): void
    {
        $result = $this->subject->setProvider('openai');

        $this->assertSame($this->subject, $result);
    }

    /**
     * @test
     */
    public function translateReturnsTranslationResponse(): void
    {
        $this->mockProvider->method('translate')
            ->willReturn(new TranslationResponse('Bonjour', 0.95));

        $result = $this->subject->translate('Hello', 'fr');

        $this->assertInstanceOf(TranslationResponse::class, $result);
        $this->assertEquals('Bonjour', $result->getTranslation());
        $this->assertEquals(0.95, $result->getConfidence());
    }

    /**
     * @test
     */
    public function cacheIsUsedWhenEnabled(): void
    {
        $mockCache = $this->createMock(CacheFrontend::class);
        $cachedResponse = new LlmResponse('Cached');

        $mockCache->expects($this->once())
            ->method('get')
            ->willReturn($cachedResponse);

        // Re-create subject with mock cache
        $this->subject = new LlmServiceManager(
            $this->createMock(ProviderFactory::class),
            new RequestBuilder(),
            new ResponseParser(),
            new StreamHandler(),
            $mockCache,
            $this->createMock(RateLimiter::class),
            $this->createMock(LoggerInterface::class)
        );

        $result = $this->subject->withCache(true)->complete('Test');

        $this->assertEquals('Cached', $result->getContent());
    }
}
```

---

## 9. Integration Patterns for Consuming Extensions

### Pattern 1: Simple Injection (Recommended)

```php
class MyService
{
    public function __construct(
        private LlmServiceManager $llm
    ) {}

    public function doSomething(): void
    {
        $result = $this->llm->complete('prompt');
    }
}
```

### Pattern 2: Optional Dependency

```php
class MyService
{
    public function __construct(
        private ?LlmServiceManager $llm = null
    ) {}

    public function doSomething(): void
    {
        if ($this->llm === null) {
            // Fallback behavior
            return;
        }

        $result = $this->llm->complete('prompt');
    }
}
```

### Pattern 3: Feature Toggle

```php
class MyService
{
    public function __construct(
        private LlmServiceManager $llm,
        private ConfigurationManager $config
    ) {}

    public function doSomething(): void
    {
        if (!$this->config->isFeatureEnabled('ai_suggestions')) {
            return;
        }

        $result = $this->llm->complete('prompt');
    }
}
```

---

## 10. Security Considerations

### Input Validation

```php
class RequestBuilder
{
    public function validate(): bool
    {
        // Validate prompt length
        if (strlen($this->prompt) > 100000) {
            throw new ValidationException('Prompt exceeds maximum length');
        }

        // Validate temperature range
        if ($this->temperature < 0 || $this->temperature > 2) {
            throw new ValidationException('Temperature must be between 0 and 2');
        }

        // Validate image URLs
        foreach ($this->images as $image) {
            if (!filter_var($image, FILTER_VALIDATE_URL)) {
                throw new ValidationException('Invalid image URL');
            }
        }

        return true;
    }
}
```

### Rate Limiting

```php
class RateLimiter
{
    public function assertNotExceeded(int $userId): void
    {
        $usage = $this->getUsage($userId);

        if ($usage['requests'] >= $this->getLimit('requests')) {
            throw new QuotaExceededException(
                'Daily request limit exceeded',
                suggestion: 'Wait until tomorrow or contact admin to increase quota'
            );
        }

        if ($usage['cost'] >= $this->getLimit('cost')) {
            throw new QuotaExceededException(
                'Daily cost limit exceeded',
                suggestion: 'Reduce usage or contact admin to increase budget'
            );
        }
    }
}
```

### Secure Streaming

```php
class StreamHandler
{
    public function handle(resource $stream, callable $callback): void
    {
        $startTime = time();
        $buffer = '';

        while (!feof($stream)) {
            // Timeout check
            if (time() - $startTime > $this->timeout) {
                fclose($stream);
                throw new ProviderConnectionException('Stream timeout exceeded');
            }

            // Read chunk
            $chunk = fread($stream, $this->bufferSize);
            if ($chunk === false) {
                throw new ProviderConnectionException('Stream read error');
            }

            // Process and callback
            $buffer .= $chunk;
            $this->processBuffer($buffer, $callback);
        }

        fclose($stream);
    }
}
```

---

## 11. Performance Optimization

### Caching Strategy

```php
class LlmServiceManager
{
    private function getCacheKey(string $feature, array $params): string
    {
        // Generate deterministic cache key
        $normalizedParams = $this->normalizeParams($params);
        return hash('sha256', $feature . json_encode($normalizedParams));
    }

    private function shouldCache(array $options): bool
    {
        // Don't cache streaming
        if ($options['stream'] ?? false) {
            return false;
        }

        // Don't cache high-temperature requests (more random)
        if (($options['temperature'] ?? 0.7) > 0.9) {
            return false;
        }

        return $this->cacheEnabled;
    }
}
```

### Connection Pooling

```php
class ProviderFactory
{
    private array $instances = [];

    public function create(?string $providerName = null): ProviderInterface
    {
        $providerName ??= $this->getDefaultProvider();

        // Reuse existing instance
        if (isset($this->instances[$providerName])) {
            return $this->instances[$providerName];
        }

        // Create new instance
        $provider = $this->instantiateProvider($providerName);
        $this->instances[$providerName] = $provider;

        return $provider;
    }
}
```

---

## 12. Migration Path for Consuming Extensions

### Step 1: Add Dependency

```json
{
    "require": {
        "netresearch/nr-llm": "^1.0"
    }
}
```

### Step 2: Update Services.yaml

```yaml
services:
  My\Extension\Service\MyAiService:
    arguments:
      $llm: '@Netresearch\NrLlm\Service\LlmServiceManager'
```

### Step 3: Implement Features

```php
class MyAiService
{
    public function __construct(
        private LlmServiceManager $llm
    ) {}

    public function myFeature(): string
    {
        return $this->llm->complete('prompt')->getContent();
    }
}
```

---

## 13. Versioning and Stability

### Semantic Versioning

- **Major (X.0.0)**: Breaking API changes
- **Minor (1.X.0)**: New features, backward compatible
- **Patch (1.0.X)**: Bug fixes, backward compatible

### API Stability Promise

**Never Change** (guaranteed until v2.0):
- `complete()` method signature
- `translate()` method signature
- `analyzeImage()` method signature
- `embed()` method signature
- Response class structures

**May Add** (backward compatible):
- New optional parameters
- New methods
- New response properties
- New providers

**Internal Details** (may change):
- RequestBuilder internals
- ResponseParser implementation
- StreamHandler buffering logic
- Cache key generation

---

## 14. Documentation Requirements

### PHPDoc Standards

```php
/**
 * Send a completion request to the LLM provider
 *
 * @param string $prompt The user prompt to send
 * @param array $options Optional parameters:
 *   - string 'model': Model name (provider-specific)
 *   - float 'temperature': 0.0-2.0, controls randomness (default: 0.7)
 *   - int 'max_tokens': Maximum tokens to generate
 *   - array 'stop_sequences': Stop generation at these sequences
 *   - string 'response_format': 'text', 'json', or 'markdown'
 * @return LlmResponse The normalized response
 * @throws ValidationException If parameters are invalid
 * @throws QuotaExceededException If rate limit exceeded
 * @throws ProviderException If provider request fails
 */
public function complete(string $prompt, array $options = []): LlmResponse
```

### User Guide Sections

1. **Quick Start**: 5-minute setup guide
2. **Core Concepts**: Providers, responses, caching
3. **API Reference**: Every public method documented
4. **Best Practices**: Error handling, performance, security
5. **Examples**: Real-world integration patterns
6. **Troubleshooting**: Common issues and solutions

---

## 15. Next Steps

### Immediate Implementation Order

1. **Week 1**: Core interfaces and response models
2. **Week 2**: LlmServiceManager + RequestBuilder
3. **Week 3**: ResponseParser + error handling
4. **Week 4**: StreamHandler + caching
5. **Week 5**: Unit tests (>80% coverage)
6. **Week 6**: Integration examples for textdb/rte-ckeditor-image/contexts
7. **Week 7**: Documentation and polish

### Success Criteria

- [ ] All public API methods working
- [ ] >80% test coverage
- [ ] 3 consuming extensions integrated successfully
- [ ] Documentation complete
- [ ] Performance benchmarks met (<50ms overhead)
- [ ] Security audit passed

---

## Document Metadata

**Related Documents**:
- `00-implementation-roadmap.md` - Overall project plan
- `01-ai-base-architecture.md` - System architecture
- `04-textdb-extension-analysis.md` - TextDB integration
- `05-rte-ckeditor-image-analysis.md` - Image extension integration
- `02-contexts-extension-analysis.md` - Contexts integration

**Status**: Ready for implementation
**Review Date**: 2025-12-22
**Next Review**: After Phase 1 completion
