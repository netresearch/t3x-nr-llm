# AI Base Extension - Detailed Implementation Plan

> Synthesized from parallel component planning sessions
> Created: 2025-12-23
> Status: Ready for Review

---

## 1. Provider Abstraction Layer

### Core Interface Design

```php
<?php
namespace Netresearch\AiBase\Service\Provider;

interface ProviderInterface
{
    // Core capabilities
    public function complete(string $prompt, array $options = []): CompletionResponse;
    public function stream(string $prompt, callable $callback, array $options = []): void;
    public function embed(string|array $text, array $options = []): EmbeddingResponse;
    public function analyzeImage(string $imageUrl, string $prompt, array $options = []): VisionResponse;
    public function translate(string $text, string $targetLanguage, ?string $sourceLanguage = null, array $options = []): TranslationResponse;

    // Provider metadata
    public function getCapabilities(): ProviderCapabilities;
    public function estimateCost(int $inputTokens, int $outputTokens): float;
    public function isAvailable(): bool;
    public function getIdentifier(): string;
}
```

### Abstract Base Provider

```php
<?php
namespace Netresearch\AiBase\Service\Provider;

abstract class AbstractProvider implements ProviderInterface
{
    protected HttpClientInterface $httpClient;
    protected LoggerInterface $logger;
    protected EventDispatcherInterface $eventDispatcher;

    // Shared functionality
    protected function makeRequest(string $endpoint, array $payload): array;
    protected function handleResponse(ResponseInterface $response): array;
    protected function logUsage(int $inputTokens, int $outputTokens, float $cost): void;
    protected function dispatchBeforeRequest(AiRequest $request): AiRequest;
    protected function dispatchAfterResponse(AiResponse $response): AiResponse;

    // Template method pattern for provider-specific handling
    abstract protected function getBaseUrl(): string;
    abstract protected function getAuthHeaders(): array;
    abstract protected function buildPayload(string $prompt, array $options): array;
    abstract protected function parseResponse(array $response): mixed;
}
```

### Provider Capabilities Value Object

```php
<?php
namespace Netresearch\AiBase\Domain\ValueObject;

final class ProviderCapabilities
{
    public function __construct(
        public readonly bool $supportsChat,
        public readonly bool $supportsVision,
        public readonly bool $supportsEmbeddings,
        public readonly bool $supportsTranslation,
        public readonly bool $supportsStreaming,
        public readonly bool $supportsToolCalling,
        public readonly array $supportedModels,
        public readonly int $maxTokens,
        public readonly bool $isLocal,
    ) {}

    public function supports(string $feature): bool
    {
        return match($feature) {
            'chat' => $this->supportsChat,
            'vision' => $this->supportsVision,
            'embeddings' => $this->supportsEmbeddings,
            'translation' => $this->supportsTranslation,
            'streaming' => $this->supportsStreaming,
            'tools' => $this->supportsToolCalling,
            default => false,
        };
    }
}
```

### Provider Factory with Auto-Discovery

```php
<?php
namespace Netresearch\AiBase\Service\Provider;

class ProviderFactory
{
    private array $providers = [];
    private ConfigurationManager $configManager;

    // Register providers via DI (Services.yaml tagging)
    public function registerProvider(ProviderInterface $provider): void
    {
        $this->providers[$provider->getIdentifier()] = $provider;
    }

    public function create(string $identifier): ProviderInterface
    {
        if (!isset($this->providers[$identifier])) {
            throw new ProviderNotFoundException($identifier);
        }
        return $this->providers[$identifier];
    }

    public function getAvailableProviders(): array
    {
        return array_filter(
            $this->providers,
            fn(ProviderInterface $p) => $p->isAvailable()
        );
    }

    public function getProviderForCapability(string $capability): ?ProviderInterface
    {
        foreach ($this->getAvailableProviders() as $provider) {
            if ($provider->getCapabilities()->supports($capability)) {
                return $provider;
            }
        }
        return null;
    }
}
```

---

## 2. Provider Implementations

### OpenAI Provider

```php
<?php
namespace Netresearch\AiBase\Service\Provider;

class OpenAiProvider extends AbstractProvider
{
    private const BASE_URL = 'https://api.openai.com/v1';

    protected function getBaseUrl(): string
    {
        return self::BASE_URL;
    }

    protected function getAuthHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->getApiKey(),
            'Content-Type' => 'application/json',
        ];
    }

    public function complete(string $prompt, array $options = []): CompletionResponse
    {
        $model = $options['model'] ?? 'gpt-4o';
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => $options['temperature'] ?? 0.7,
            'max_tokens' => $options['max_tokens'] ?? 1000,
        ];

        if (isset($options['system'])) {
            array_unshift($payload['messages'], [
                'role' => 'system',
                'content' => $options['system']
            ]);
        }

        $response = $this->makeRequest('/chat/completions', $payload);
        return $this->parseCompletionResponse($response);
    }

    public function analyzeImage(string $imageUrl, string $prompt, array $options = []): VisionResponse
    {
        $payload = [
            'model' => $options['model'] ?? 'gpt-4o',
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => $imageUrl]]
                ]
            ]],
            'max_tokens' => $options['max_tokens'] ?? 300,
        ];

        $response = $this->makeRequest('/chat/completions', $payload);
        return $this->parseVisionResponse($response);
    }

    public function embed(string|array $text, array $options = []): EmbeddingResponse
    {
        $payload = [
            'model' => $options['model'] ?? 'text-embedding-3-small',
            'input' => $text,
        ];

        $response = $this->makeRequest('/embeddings', $payload);
        return $this->parseEmbeddingResponse($response);
    }

    public function getCapabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            supportsChat: true,
            supportsVision: true,
            supportsEmbeddings: true,
            supportsTranslation: true,
            supportsStreaming: true,
            supportsToolCalling: true,
            supportedModels: ['gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo', 'o1', 'o3-mini'],
            maxTokens: 128000,
            isLocal: false,
        );
    }
}
```

### Anthropic Provider

```php
<?php
namespace Netresearch\AiBase\Service\Provider;

class AnthropicProvider extends AbstractProvider
{
    private const BASE_URL = 'https://api.anthropic.com/v1';
    private const API_VERSION = '2023-06-01';

    protected function getAuthHeaders(): array
    {
        return [
            'x-api-key' => $this->getApiKey(),
            'anthropic-version' => self::API_VERSION,
            'Content-Type' => 'application/json',
        ];
    }

    public function complete(string $prompt, array $options = []): CompletionResponse
    {
        $payload = [
            'model' => $options['model'] ?? 'claude-sonnet-4-20250514',
            'max_tokens' => $options['max_tokens'] ?? 1024,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
        ];

        if (isset($options['system'])) {
            $payload['system'] = $options['system'];
        }

        $response = $this->makeRequest('/messages', $payload);
        return $this->parseCompletionResponse($response);
    }

    public function analyzeImage(string $imageUrl, string $prompt, array $options = []): VisionResponse
    {
        // Anthropic requires base64 images
        $imageData = $this->fetchAndEncodeImage($imageUrl);

        $payload = [
            'model' => $options['model'] ?? 'claude-sonnet-4-20250514',
            'max_tokens' => $options['max_tokens'] ?? 1024,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $imageData['media_type'],
                            'data' => $imageData['data'],
                        ]
                    ],
                    ['type' => 'text', 'text' => $prompt]
                ]
            ]],
        ];

        $response = $this->makeRequest('/messages', $payload);
        return $this->parseVisionResponse($response);
    }

    public function getCapabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            supportsChat: true,
            supportsVision: true,
            supportsEmbeddings: false,
            supportsTranslation: true,
            supportsStreaming: true,
            supportsToolCalling: true,
            supportedModels: ['claude-opus-4-20250514', 'claude-sonnet-4-20250514', 'claude-3-5-haiku-20241022'],
            maxTokens: 200000,
            isLocal: false,
        );
    }
}
```

### DeepL Provider (Translation Specialist)

```php
<?php
namespace Netresearch\AiBase\Service\Provider;

class DeepLProvider extends AbstractProvider
{
    private const BASE_URL = 'https://api.deepl.com/v2';
    private const FREE_URL = 'https://api-free.deepl.com/v2';

    public function translate(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        array $options = []
    ): TranslationResponse {
        $payload = [
            'text' => [$text],
            'target_lang' => strtoupper($targetLanguage),
        ];

        if ($sourceLanguage) {
            $payload['source_lang'] = strtoupper($sourceLanguage);
        }

        // DeepL-specific options
        if (isset($options['formality'])) {
            $payload['formality'] = $options['formality']; // less, more, default
        }
        if (isset($options['preserve_formatting'])) {
            $payload['preserve_formatting'] = $options['preserve_formatting'];
        }
        if (isset($options['glossary_id'])) {
            $payload['glossary_id'] = $options['glossary_id'];
        }

        $response = $this->makeRequest('/translate', $payload);
        return $this->parseTranslationResponse($response);
    }

    public function getCapabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            supportsChat: false,
            supportsVision: false,
            supportsEmbeddings: false,
            supportsTranslation: true,
            supportsStreaming: false,
            supportsToolCalling: false,
            supportedModels: ['deepl-v2'],
            maxTokens: 10000,
            isLocal: false,
        );
    }

    // DeepL-specific: Get usage stats
    public function getUsage(): array
    {
        $response = $this->makeRequest('/usage', [], 'GET');
        return [
            'character_count' => $response['character_count'],
            'character_limit' => $response['character_limit'],
        ];
    }
}
```

### Ollama Provider (Local Models)

```php
<?php
namespace Netresearch\AiBase\Service\Provider;

class OllamaProvider extends AbstractProvider
{
    private string $baseUrl;

    public function __construct(
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
        string $ollamaHost = 'http://localhost:11434'
    ) {
        parent::__construct($httpClient, $logger);
        $this->baseUrl = rtrim($ollamaHost, '/');
    }

    protected function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    protected function getAuthHeaders(): array
    {
        return ['Content-Type' => 'application/json'];
    }

    public function complete(string $prompt, array $options = []): CompletionResponse
    {
        $payload = [
            'model' => $options['model'] ?? 'llama3.2',
            'prompt' => $prompt,
            'stream' => false,
            'options' => [
                'temperature' => $options['temperature'] ?? 0.7,
                'num_predict' => $options['max_tokens'] ?? 1000,
            ],
        ];

        if (isset($options['system'])) {
            $payload['system'] = $options['system'];
        }

        $response = $this->makeRequest('/api/generate', $payload);
        return $this->parseCompletionResponse($response);
    }

    public function analyzeImage(string $imageUrl, string $prompt, array $options = []): VisionResponse
    {
        $imageData = $this->fetchAndEncodeImage($imageUrl);

        $payload = [
            'model' => $options['model'] ?? 'llava',
            'prompt' => $prompt,
            'images' => [$imageData['data']],
            'stream' => false,
        ];

        $response = $this->makeRequest('/api/generate', $payload);
        return $this->parseVisionResponse($response);
    }

    public function embed(string|array $text, array $options = []): EmbeddingResponse
    {
        $texts = is_array($text) ? $text : [$text];
        $embeddings = [];

        foreach ($texts as $t) {
            $payload = [
                'model' => $options['model'] ?? 'nomic-embed-text',
                'prompt' => $t,
            ];

            $response = $this->makeRequest('/api/embeddings', $payload);
            $embeddings[] = $response['embedding'];
        }

        return new EmbeddingResponse($embeddings);
    }

    public function isAvailable(): bool
    {
        try {
            $response = $this->httpClient->request('GET', $this->baseUrl . '/api/tags');
            return $response->getStatusCode() === 200;
        } catch (\Throwable) {
            return false;
        }
    }

    public function getAvailableModels(): array
    {
        $response = $this->makeRequest('/api/tags', [], 'GET');
        return array_column($response['models'] ?? [], 'name');
    }

    public function getCapabilities(): ProviderCapabilities
    {
        $models = $this->isAvailable() ? $this->getAvailableModels() : [];

        return new ProviderCapabilities(
            supportsChat: true,
            supportsVision: in_array('llava', $models, true),
            supportsEmbeddings: true,
            supportsTranslation: true,
            supportsStreaming: true,
            supportsToolCalling: false,
            supportedModels: $models,
            maxTokens: 4096,
            isLocal: true,
        );
    }

    public function estimateCost(int $inputTokens, int $outputTokens): float
    {
        return 0.0; // Local models have no API cost
    }
}
```

### OpenRouter Provider (Multi-Model Gateway)

```php
<?php
namespace Netresearch\AiBase\Service\Provider;

class OpenRouterProvider extends AbstractProvider
{
    private const BASE_URL = 'https://openrouter.ai/api/v1';

    protected function getAuthHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->getApiKey(),
            'HTTP-Referer' => $this->getSiteUrl(),
            'X-Title' => 'TYPO3 AI Base',
            'Content-Type' => 'application/json',
        ];
    }

    public function complete(string $prompt, array $options = []): CompletionResponse
    {
        $payload = [
            'model' => $options['model'] ?? 'anthropic/claude-3.5-sonnet',
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'max_tokens' => $options['max_tokens'] ?? 1000,
        ];

        $response = $this->makeRequest('/chat/completions', $payload);
        return $this->parseCompletionResponse($response);
    }

    public function getAvailableModels(): array
    {
        $response = $this->makeRequest('/models', [], 'GET');
        return array_map(
            fn($m) => [
                'id' => $m['id'],
                'name' => $m['name'],
                'pricing' => $m['pricing'],
                'context_length' => $m['context_length'],
            ],
            $response['data'] ?? []
        );
    }

    public function getCapabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            supportsChat: true,
            supportsVision: true,
            supportsEmbeddings: true,
            supportsTranslation: true,
            supportsStreaming: true,
            supportsToolCalling: true,
            supportedModels: ['anthropic/claude-3.5-sonnet', 'openai/gpt-4o', 'google/gemini-pro'],
            maxTokens: 200000,
            isLocal: false,
        );
    }
}
```

### Gemini Provider

```php
<?php
namespace Netresearch\AiBase\Service\Provider;

class GeminiProvider extends AbstractProvider
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    public function complete(string $prompt, array $options = []): CompletionResponse
    {
        $model = $options['model'] ?? 'gemini-2.0-flash';
        $endpoint = "/models/{$model}:generateContent";

        $payload = [
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ],
            'generationConfig' => [
                'temperature' => $options['temperature'] ?? 0.7,
                'maxOutputTokens' => $options['max_tokens'] ?? 1000,
            ],
        ];

        $response = $this->makeRequest($endpoint, $payload);
        return $this->parseCompletionResponse($response);
    }

    public function embed(string|array $text, array $options = []): EmbeddingResponse
    {
        $model = $options['model'] ?? 'text-embedding-004';
        $endpoint = "/models/{$model}:embedContent";

        $texts = is_array($text) ? $text : [$text];
        $embeddings = [];

        foreach ($texts as $t) {
            $payload = [
                'content' => ['parts' => [['text' => $t]]]
            ];
            $response = $this->makeRequest($endpoint, $payload);
            $embeddings[] = $response['embedding']['values'];
        }

        return new EmbeddingResponse($embeddings);
    }

    public function getCapabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            supportsChat: true,
            supportsVision: true,
            supportsEmbeddings: true,
            supportsTranslation: true,
            supportsStreaming: true,
            supportsToolCalling: true,
            supportedModels: ['gemini-2.0-flash', 'gemini-1.5-pro', 'gemini-1.5-flash'],
            maxTokens: 1000000,
            isLocal: false,
        );
    }
}
```

---

## 3. LlmServiceManager Facade

```php
<?php
namespace Netresearch\AiBase\Service;

class LlmServiceManager
{
    public function __construct(
        private ProviderFactory $providerFactory,
        private ConfigurationManager $configManager,
        private CacheManager $cacheManager,
        private RateLimiterService $rateLimiter,
        private UsageTracker $usageTracker,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
    ) {}

    /**
     * Main entry point for AI requests
     */
    public function execute(
        string $feature,
        array $params,
        array $options = []
    ): AiResponse {
        // 1. Check rate limits
        $this->rateLimiter->checkLimit($feature);

        // 2. Check cache
        $cacheKey = $this->buildCacheKey($feature, $params);
        if ($cached = $this->cacheManager->get($cacheKey)) {
            return $cached;
        }

        // 3. Dispatch before event
        $event = new BeforeAiRequestEvent($feature, $params, $options);
        $this->eventDispatcher->dispatch($event);

        // 4. Get appropriate provider
        $provider = $this->resolveProvider($feature, $options);

        // 5. Execute request
        $response = match($feature) {
            'complete' => $provider->complete($params['prompt'], $options),
            'translate' => $provider->translate(
                $params['text'],
                $params['targetLanguage'],
                $params['sourceLanguage'] ?? null,
                $options
            ),
            'vision' => $provider->analyzeImage(
                $params['imageUrl'],
                $params['prompt'],
                $options
            ),
            'embed' => $provider->embed($params['text'], $options),
            default => throw new UnsupportedFeatureException($feature),
        };

        // 6. Track usage
        $this->usageTracker->track($provider, $response);

        // 7. Cache response
        $this->cacheManager->set($cacheKey, $response, $options['cache_ttl'] ?? 3600);

        // 8. Dispatch after event
        $afterEvent = new AfterAiResponseEvent($response, $feature, $provider);
        $this->eventDispatcher->dispatch($afterEvent);

        return $response;
    }

    /**
     * Convenience methods
     */
    public function complete(string $prompt, array $options = []): CompletionResponse
    {
        return $this->execute('complete', ['prompt' => $prompt], $options);
    }

    public function translate(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        array $options = []
    ): TranslationResponse {
        return $this->execute('translate', [
            'text' => $text,
            'targetLanguage' => $targetLanguage,
            'sourceLanguage' => $sourceLanguage,
        ], $options);
    }

    public function generateImageAltText(string $imageUrl, array $options = []): string
    {
        $response = $this->execute('vision', [
            'imageUrl' => $imageUrl,
            'prompt' => 'Generate a concise, descriptive alt text for this image that is suitable for screen readers. Focus on the main subject and context.',
        ], $options);

        return $response->getContent();
    }

    public function generateEmbedding(string|array $text, array $options = []): array
    {
        $response = $this->execute('embed', ['text' => $text], $options);
        return $response->getEmbeddings();
    }

    /**
     * Get provider with fallback logic
     */
    private function resolveProvider(string $feature, array $options): ProviderInterface
    {
        // 1. Explicit provider requested
        if (isset($options['provider'])) {
            return $this->providerFactory->create($options['provider']);
        }

        // 2. Feature-specific default
        $featureDefault = $this->configManager->getFeatureProvider($feature);
        if ($featureDefault) {
            $provider = $this->providerFactory->create($featureDefault);
            if ($provider->isAvailable()) {
                return $provider;
            }
        }

        // 3. Global default
        $globalDefault = $this->configManager->getDefaultProvider();
        $provider = $this->providerFactory->create($globalDefault);
        if ($provider->isAvailable()) {
            return $provider;
        }

        // 4. Fallback to any available provider
        return $this->providerFactory->getProviderForCapability($feature)
            ?? throw new NoAvailableProviderException($feature);
    }
}
```

---

## 4. Feature Services

### TranslationService

```php
<?php
namespace Netresearch\AiBase\Service\Feature;

class TranslationService
{
    private const DEEPL_PREFERRED_LANGUAGES = ['de', 'en', 'fr', 'es', 'it', 'nl', 'pl', 'pt', 'ru', 'ja', 'zh'];

    public function __construct(
        private LlmServiceManager $llmManager,
        private ProviderFactory $providerFactory,
        private LanguageService $languageService,
    ) {}

    public function translate(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        array $options = []
    ): TranslationResult {
        // Auto-detect source language if not provided
        if (!$sourceLanguage) {
            $sourceLanguage = $this->detectLanguage($text);
        }

        // Route to best provider
        $provider = $this->selectTranslationProvider($targetLanguage, $options);

        $response = $this->llmManager->translate(
            $text,
            $targetLanguage,
            $sourceLanguage,
            array_merge($options, ['provider' => $provider])
        );

        return new TranslationResult(
            translation: $response->getTranslation(),
            sourceLanguage: $sourceLanguage,
            targetLanguage: $targetLanguage,
            provider: $provider,
            confidence: $response->getConfidence(),
            alternatives: $response->getAlternatives(),
        );
    }

    public function translateBatch(
        array $texts,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        array $options = []
    ): array {
        $results = [];

        // Batch processing for efficiency
        foreach (array_chunk($texts, 50) as $batch) {
            foreach ($batch as $key => $text) {
                $results[$key] = $this->translate($text, $targetLanguage, $sourceLanguage, $options);
            }
        }

        return $results;
    }

    private function selectTranslationProvider(string $targetLanguage, array $options): string
    {
        // Use DeepL for supported languages if available
        if (in_array(strtolower($targetLanguage), self::DEEPL_PREFERRED_LANGUAGES, true)) {
            $deepl = $this->providerFactory->create('deepl');
            if ($deepl->isAvailable()) {
                return 'deepl';
            }
        }

        // Fall back to configured default
        return $options['provider'] ?? 'openai';
    }

    private function detectLanguage(string $text): string
    {
        // Simple heuristic or use provider's detection
        return $this->languageService->detect($text);
    }
}
```

### ImageDescriptionService

```php
<?php
namespace Netresearch\AiBase\Service\Feature;

class ImageDescriptionService
{
    public function __construct(
        private LlmServiceManager $llmManager,
        private PromptRepository $promptRepository,
    ) {}

    public function generateAltText(
        string $imageUrl,
        array $options = []
    ): ImageDescription {
        $prompt = $this->promptRepository->findByIdentifier('image_alt_text')
            ?? $this->getDefaultAltTextPrompt();

        $response = $this->llmManager->execute('vision', [
            'imageUrl' => $imageUrl,
            'prompt' => $prompt->getUserPrompt(),
        ], array_merge($options, [
            'system' => $prompt->getSystemPrompt(),
            'max_tokens' => 150,
        ]));

        return new ImageDescription(
            altText: $this->sanitizeAltText($response->getContent()),
            caption: $this->generateCaption($response->getContent()),
            tags: $this->extractTags($response),
        );
    }

    public function generateDetailedDescription(
        string $imageUrl,
        array $options = []
    ): ImageDescription {
        $prompt = $this->promptRepository->findByIdentifier('image_detailed_description')
            ?? $this->getDefaultDetailedPrompt();

        $response = $this->llmManager->execute('vision', [
            'imageUrl' => $imageUrl,
            'prompt' => $prompt->getUserPrompt(),
        ], array_merge($options, [
            'system' => $prompt->getSystemPrompt(),
            'max_tokens' => 500,
        ]));

        return new ImageDescription(
            altText: $this->sanitizeAltText($response->getContent()),
            caption: $response->getContent(),
            tags: $this->extractTags($response),
        );
    }

    public function analyzeAccessibility(string $imageUrl): AccessibilityReport
    {
        $description = $this->generateDetailedDescription($imageUrl);

        return new AccessibilityReport(
            hasAltText: !empty($description->altText),
            altTextQuality: $this->assessAltTextQuality($description->altText),
            suggestedAltText: $description->altText,
            issues: $this->detectAccessibilityIssues($description),
        );
    }

    private function sanitizeAltText(string $text): string
    {
        // Remove quotes, newlines, excessive length
        $text = preg_replace('/^["\']|["\']$/', '', trim($text));
        $text = preg_replace('/\s+/', ' ', $text);

        if (strlen($text) > 125) {
            $text = substr($text, 0, 122) . '...';
        }

        return $text;
    }

    private function getDefaultAltTextPrompt(): Prompt
    {
        return new Prompt(
            systemPrompt: 'You are an accessibility expert. Generate concise, descriptive alt text for images.',
            userPrompt: 'Generate a single sentence alt text (max 125 characters) that describes this image for screen reader users. Focus on the main subject and action. Do not start with "Image of" or "Picture of".',
        );
    }
}
```

### ContentGenerationService

```php
<?php
namespace Netresearch\AiBase\Service\Feature;

class ContentGenerationService
{
    public function __construct(
        private LlmServiceManager $llmManager,
        private PromptRepository $promptRepository,
    ) {}

    public function generateContent(
        string $prompt,
        string $contentType = 'general',
        array $options = []
    ): GeneratedContent {
        $systemPrompt = $this->getSystemPromptForType($contentType);

        $response = $this->llmManager->complete($prompt, array_merge($options, [
            'system' => $systemPrompt,
        ]));

        return new GeneratedContent(
            content: $response->getContent(),
            contentType: $contentType,
            metadata: [
                'tokens_used' => $response->getTokensUsed(),
                'model' => $response->getModel(),
            ],
        );
    }

    public function summarize(
        string $text,
        int $maxLength = 200,
        array $options = []
    ): string {
        $response = $this->llmManager->complete(
            "Summarize the following text in {$maxLength} characters or less:\n\n{$text}",
            array_merge($options, [
                'system' => 'You are a skilled editor. Create concise, accurate summaries.',
                'max_tokens' => $maxLength,
            ])
        );

        return $response->getContent();
    }

    public function improveWriting(
        string $text,
        string $style = 'professional',
        array $options = []
    ): string {
        $styleInstructions = match($style) {
            'professional' => 'Improve for professional business communication',
            'casual' => 'Make more conversational and friendly',
            'academic' => 'Make more formal and academic',
            'marketing' => 'Make more engaging and persuasive',
            default => 'Improve the writing quality',
        };

        $response = $this->llmManager->complete(
            "{$styleInstructions}:\n\n{$text}",
            array_merge($options, [
                'system' => 'You are an expert editor. Improve text while preserving meaning.',
            ])
        );

        return $response->getContent();
    }

    private function getSystemPromptForType(string $contentType): string
    {
        $prompt = $this->promptRepository->findByIdentifier("content_{$contentType}");

        return $prompt?->getSystemPrompt() ?? match($contentType) {
            'blog' => 'You are a skilled blog writer. Write engaging, SEO-friendly content.',
            'product' => 'You are a product copywriter. Write compelling product descriptions.',
            'email' => 'You are a professional email writer. Write clear, effective emails.',
            'social' => 'You are a social media expert. Write engaging posts.',
            default => 'You are a helpful writing assistant.',
        };
    }
}
```

### EmbeddingService

```php
<?php
namespace Netresearch\AiBase\Service\Feature;

class EmbeddingService
{
    public function __construct(
        private LlmServiceManager $llmManager,
        private CacheManager $cacheManager,
    ) {}

    public function generateEmbedding(string $text, array $options = []): array
    {
        $cacheKey = 'embedding_' . md5($text . json_encode($options));

        if ($cached = $this->cacheManager->get($cacheKey)) {
            return $cached;
        }

        $embeddings = $this->llmManager->generateEmbedding($text, $options);

        $this->cacheManager->set($cacheKey, $embeddings, 86400 * 7); // 7 days

        return $embeddings;
    }

    public function generateBatchEmbeddings(array $texts, array $options = []): array
    {
        $results = [];
        $toProcess = [];

        // Check cache for each text
        foreach ($texts as $key => $text) {
            $cacheKey = 'embedding_' . md5($text . json_encode($options));
            if ($cached = $this->cacheManager->get($cacheKey)) {
                $results[$key] = $cached;
            } else {
                $toProcess[$key] = $text;
            }
        }

        // Process uncached in batch
        if (!empty($toProcess)) {
            $embeddings = $this->llmManager->generateEmbedding(array_values($toProcess), $options);

            foreach (array_keys($toProcess) as $i => $key) {
                $results[$key] = $embeddings[$i];
                $cacheKey = 'embedding_' . md5($toProcess[$key] . json_encode($options));
                $this->cacheManager->set($cacheKey, $embeddings[$i], 86400 * 7);
            }
        }

        ksort($results);
        return $results;
    }

    public function calculateSimilarity(array $embedding1, array $embedding2): float
    {
        // Cosine similarity
        $dotProduct = 0;
        $norm1 = 0;
        $norm2 = 0;

        for ($i = 0; $i < count($embedding1); $i++) {
            $dotProduct += $embedding1[$i] * $embedding2[$i];
            $norm1 += $embedding1[$i] ** 2;
            $norm2 += $embedding2[$i] ** 2;
        }

        return $dotProduct / (sqrt($norm1) * sqrt($norm2));
    }

    public function findSimilar(
        string $query,
        array $candidates,
        int $limit = 5,
        float $threshold = 0.7
    ): array {
        $queryEmbedding = $this->generateEmbedding($query);
        $candidateEmbeddings = $this->generateBatchEmbeddings($candidates);

        $similarities = [];
        foreach ($candidateEmbeddings as $key => $embedding) {
            $similarity = $this->calculateSimilarity($queryEmbedding, $embedding);
            if ($similarity >= $threshold) {
                $similarities[$key] = $similarity;
            }
        }

        arsort($similarities);

        return array_slice($similarities, 0, $limit, true);
    }
}
```

---

## 5. Caching & Performance

### Cache Strategy

```php
<?php
namespace Netresearch\AiBase\Service\Cache;

class AiResponseCache
{
    private const CACHE_IDENTIFIER = 'ai_base_responses';

    public function __construct(
        private CacheManager $typo3CacheManager,
        private ConfigurationManager $configManager,
    ) {}

    public function get(string $key): ?AiResponse
    {
        $cache = $this->getCache();

        if ($cache->has($key)) {
            $data = $cache->get($key);
            return unserialize($data);
        }

        return null;
    }

    public function set(string $key, AiResponse $response, int $ttl = 3600): void
    {
        if (!$this->isCacheable($response)) {
            return;
        }

        $cache = $this->getCache();
        $cache->set($key, serialize($response), ['ai_base'], $ttl);
    }

    public function buildKey(string $feature, array $params, array $options = []): string
    {
        // Normalize options that affect the response
        $keyOptions = array_intersect_key($options, array_flip([
            'model', 'temperature', 'max_tokens', 'provider'
        ]));

        return md5(json_encode([
            'feature' => $feature,
            'params' => $params,
            'options' => $keyOptions,
        ]));
    }

    public function invalidate(array $tags = []): void
    {
        $cache = $this->getCache();
        $cache->flushByTags(empty($tags) ? ['ai_base'] : $tags);
    }

    private function isCacheable(AiResponse $response): bool
    {
        // Don't cache errors or low-confidence responses
        if ($response->hasError()) {
            return false;
        }

        // Don't cache streaming responses
        if ($response->isStreaming()) {
            return false;
        }

        return true;
    }

    private function getCache(): FrontendInterface
    {
        return $this->typo3CacheManager->getCache(self::CACHE_IDENTIFIER);
    }
}
```

### Cache Configuration (ext_localconf.php)

```php
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['ai_base_responses'] ??= [];
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['ai_base_responses']['frontend']
    ??= \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class;
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['ai_base_responses']['backend']
    ??= \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class;
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['ai_base_responses']['options'] = [
    'defaultLifetime' => 3600,
];
```

---

## 6. Rate Limiting & Quotas

### Rate Limiter Service

```php
<?php
namespace Netresearch\AiBase\Service\RateLimiter;

class RateLimiterService
{
    public function __construct(
        private QuotaRepository $quotaRepository,
        private CacheManager $cacheManager,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
    ) {}

    public function checkLimit(string $feature, ?int $userId = null): void
    {
        $userId = $userId ?? $this->getCurrentUserId();

        // Check rate limit (requests per minute)
        if (!$this->checkRateLimit($userId, $feature)) {
            throw new RateLimitExceededException(
                "Rate limit exceeded for feature: {$feature}"
            );
        }

        // Check quota (daily/monthly limits)
        if (!$this->checkQuota($userId, $feature)) {
            $this->eventDispatcher->dispatch(new QuotaExceededEvent($userId, $feature));
            throw new QuotaExceededException(
                "Quota exceeded for feature: {$feature}"
            );
        }
    }

    public function incrementUsage(int $userId, string $feature, int $tokens, float $cost): void
    {
        $quota = $this->quotaRepository->findByUser($userId);

        if (!$quota) {
            $quota = new Quota();
            $quota->setUserId($userId);
            $quota->setQuotaPeriod('daily');
        }

        $quota->setRequestsUsed($quota->getRequestsUsed() + 1);
        $quota->setCostUsed($quota->getCostUsed() + $cost);

        $this->quotaRepository->update($quota);
    }

    private function checkRateLimit(int $userId, string $feature): bool
    {
        $key = "rate_limit_{$userId}_{$feature}";
        $cache = $this->cacheManager->getCache('ai_base_rate_limits');

        $current = (int)$cache->get($key);
        $limit = $this->getRequestsPerMinuteLimit($feature);

        if ($current >= $limit) {
            return false;
        }

        $cache->set($key, $current + 1, [], 60);
        return true;
    }

    private function checkQuota(int $userId, string $feature): bool
    {
        $quota = $this->quotaRepository->findByUser($userId);

        if (!$quota) {
            return true; // No quota set = unlimited
        }

        // Check if quota period needs reset
        if ($this->shouldResetQuota($quota)) {
            $quota->setRequestsUsed(0);
            $quota->setCostUsed(0);
            $this->quotaRepository->update($quota);
            return true;
        }

        // Check request limit
        if ($quota->getRequestsLimit() > 0 && $quota->getRequestsUsed() >= $quota->getRequestsLimit()) {
            return false;
        }

        // Check cost limit
        if ($quota->getCostLimit() > 0 && $quota->getCostUsed() >= $quota->getCostLimit()) {
            return false;
        }

        return true;
    }

    private function getRequestsPerMinuteLimit(string $feature): int
    {
        return match($feature) {
            'embed' => 100,
            'translate' => 50,
            'complete' => 20,
            'vision' => 10,
            default => 30,
        };
    }

    private function shouldResetQuota(Quota $quota): bool
    {
        $lastReset = $quota->getLastReset();
        $now = new \DateTime();

        return match($quota->getQuotaPeriod()) {
            'hourly' => $now->diff($lastReset)->h >= 1,
            'daily' => $now->diff($lastReset)->d >= 1,
            'weekly' => $now->diff($lastReset)->d >= 7,
            'monthly' => $now->diff($lastReset)->m >= 1,
            default => false,
        };
    }
}
```

### Usage Tracker

```php
<?php
namespace Netresearch\AiBase\Service;

class UsageTracker
{
    public function __construct(
        private UsageRepository $usageRepository,
        private RateLimiterService $rateLimiter,
        private LoggerInterface $logger,
    ) {}

    public function track(ProviderInterface $provider, AiResponse $response): void
    {
        $userId = $this->getCurrentUserId();

        $usage = new UsageRecord();
        $usage->setUserId($userId);
        $usage->setProvider($provider->getIdentifier());
        $usage->setFeature($response->getFeature());
        $usage->setPromptTokens($response->getPromptTokens());
        $usage->setCompletionTokens($response->getCompletionTokens());
        $usage->setEstimatedCost($provider->estimateCost(
            $response->getPromptTokens(),
            $response->getCompletionTokens()
        ));

        $this->usageRepository->add($usage);

        // Update quota
        $this->rateLimiter->incrementUsage(
            $userId,
            $response->getFeature(),
            $response->getTotalTokens(),
            $usage->getEstimatedCost()
        );
    }

    public function getUsageStats(
        int $userId,
        ?\DateTime $from = null,
        ?\DateTime $to = null
    ): array {
        return $this->usageRepository->getStats($userId, $from, $to);
    }

    public function getCostReport(
        ?\DateTime $from = null,
        ?\DateTime $to = null
    ): array {
        return $this->usageRepository->getCostReport($from, $to);
    }
}
```

---

## 7. Security Layer

### API Key Manager

```php
<?php
namespace Netresearch\AiBase\Security;

class ApiKeyManager
{
    private const CIPHER = 'aes-256-gcm';

    public function __construct(
        private ApiKeyRepository $repository,
        private ConfigurationManager $configManager,
    ) {}

    public function getApiKey(string $provider, string $scope = 'global', int $scopeId = 0): ?string
    {
        // 1. Check environment variable first
        $envKey = getenv("AIBASE_{$provider}_API_KEY") ?: null;
        if ($envKey) {
            return $envKey;
        }

        // 2. Check database (encrypted)
        $apiKey = $this->repository->findByProvider($provider, $scope, $scopeId);
        if ($apiKey) {
            return $this->decrypt($apiKey->getEncryptedKey());
        }

        // 3. Fall back to extension configuration
        return $this->configManager->getProviderApiKey($provider);
    }

    public function storeApiKey(
        string $provider,
        string $key,
        string $scope = 'global',
        int $scopeId = 0
    ): void {
        $apiKey = $this->repository->findByProvider($provider, $scope, $scopeId)
            ?? new ApiKey();

        $apiKey->setProvider($provider);
        $apiKey->setScope($scope);
        $apiKey->setScopeId($scopeId);
        $apiKey->setEncryptedKey($this->encrypt($key));
        $apiKey->setIsActive(true);

        if ($apiKey->getUid()) {
            $this->repository->update($apiKey);
        } else {
            $this->repository->add($apiKey);
        }
    }

    public function validateApiKey(string $provider, string $key): bool
    {
        try {
            $providerInstance = GeneralUtility::makeInstance(
                ProviderFactory::class
            )->create($provider);

            // Temporarily set the key and check availability
            return $providerInstance->validateCredentials($key);
        } catch (\Throwable) {
            return false;
        }
    }

    private function encrypt(string $plaintext): string
    {
        $key = $this->getEncryptionKey();
        $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER));

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return base64_encode($iv . $tag . $ciphertext);
    }

    private function decrypt(string $encrypted): string
    {
        $key = $this->getEncryptionKey();
        $data = base64_decode($encrypted);

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = substr($data, 0, $ivLength);
        $tag = substr($data, $ivLength, 16);
        $ciphertext = substr($data, $ivLength + 16);

        return openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
    }

    private function getEncryptionKey(): string
    {
        $encryptionKey = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '';
        return hash('sha256', $encryptionKey . 'ai_base_key', true);
    }
}
```

### Access Control

```php
<?php
namespace Netresearch\AiBase\Security;

class AccessControl
{
    public function __construct(
        private Context $context,
        private ConfigurationManager $configManager,
    ) {}

    public function canUseProvider(string $provider): bool
    {
        $user = $this->context->getPropertyFromAspect('backend.user', 'user');

        if (!$user) {
            return false;
        }

        // Admins can use all providers
        if ($user['admin']) {
            return true;
        }

        // Check user group permissions
        $allowedProviders = $this->getAllowedProviders($user);
        return in_array($provider, $allowedProviders, true);
    }

    public function canUseFeature(string $feature): bool
    {
        $user = $this->context->getPropertyFromAspect('backend.user', 'user');

        if (!$user) {
            return false;
        }

        if ($user['admin']) {
            return true;
        }

        $allowedFeatures = $this->getAllowedFeatures($user);
        return in_array($feature, $allowedFeatures, true);
    }

    public function getQuotaLimit(int $userId): array
    {
        // Return quota limits based on user groups
        return [
            'daily_requests' => $this->configManager->get('quotas.dailyLimit') ?? 100,
            'monthly_cost' => $this->configManager->get('quotas.monthlyCostLimit') ?? 50.0,
        ];
    }

    private function getAllowedProviders(array $user): array
    {
        // Parse from TSconfig or extension configuration
        $config = BackendUtility::getPagesTSconfig(0)['aibase.']['allowedProviders'] ?? '';
        return GeneralUtility::trimExplode(',', $config, true);
    }

    private function getAllowedFeatures(array $user): array
    {
        $config = BackendUtility::getPagesTSconfig(0)['aibase.']['allowedFeatures'] ?? '';
        return GeneralUtility::trimExplode(',', $config, true);
    }
}
```

---

## 8. Event System

### PSR-14 Events

```php
<?php
namespace Netresearch\AiBase\Event;

final class BeforeAiRequestEvent
{
    public function __construct(
        private string $feature,
        private array $params,
        private array $options,
        private ?string $provider = null,
    ) {}

    public function getFeature(): string { return $this->feature; }
    public function getParams(): array { return $this->params; }
    public function getOptions(): array { return $this->options; }
    public function getProvider(): ?string { return $this->provider; }

    public function setParams(array $params): void { $this->params = $params; }
    public function setOptions(array $options): void { $this->options = $options; }
    public function setProvider(string $provider): void { $this->provider = $provider; }
}

final class AfterAiResponseEvent
{
    public function __construct(
        private AiResponse $response,
        private string $feature,
        private ProviderInterface $provider,
    ) {}

    public function getResponse(): AiResponse { return $this->response; }
    public function getFeature(): string { return $this->feature; }
    public function getProvider(): ProviderInterface { return $this->provider; }

    public function setResponse(AiResponse $response): void { $this->response = $response; }
}

final class QuotaExceededEvent
{
    public function __construct(
        private int $userId,
        private string $feature,
        private array $quotaInfo,
    ) {}

    public function getUserId(): int { return $this->userId; }
    public function getFeature(): string { return $this->feature; }
    public function getQuotaInfo(): array { return $this->quotaInfo; }
}

final class ProviderFailoverEvent
{
    public function __construct(
        private string $originalProvider,
        private string $fallbackProvider,
        private \Throwable $exception,
    ) {}

    public function getOriginalProvider(): string { return $this->originalProvider; }
    public function getFallbackProvider(): string { return $this->fallbackProvider; }
    public function getException(): \Throwable { return $this->exception; }
}
```

---

## 9. Backend Module

### Controller Structure

```php
<?php
namespace Netresearch\AiBase\Controller\Backend;

#[AsController]
class AiControlPanelController extends ActionController
{
    public function __construct(
        private LlmServiceManager $llmManager,
        private UsageTracker $usageTracker,
        private ProviderFactory $providerFactory,
        private ApiKeyManager $apiKeyManager,
    ) {}

    public function dashboardAction(): ResponseInterface
    {
        $this->view->assignMultiple([
            'providers' => $this->getProviderStatus(),
            'usageStats' => $this->usageTracker->getUsageStats(
                $this->getCurrentUserId(),
                new \DateTime('-30 days')
            ),
            'recentRequests' => $this->getRecentRequests(10),
        ]);

        return $this->htmlResponse();
    }

    public function providersAction(): ResponseInterface
    {
        $this->view->assign('providers', $this->getDetailedProviderInfo());
        return $this->htmlResponse();
    }

    public function apiKeysAction(): ResponseInterface
    {
        $this->view->assign('apiKeys', $this->apiKeyManager->getAll());
        return $this->htmlResponse();
    }

    public function saveApiKeyAction(string $provider, string $key): ResponseInterface
    {
        if ($this->apiKeyManager->validateApiKey($provider, $key)) {
            $this->apiKeyManager->storeApiKey($provider, $key);
            $this->addFlashMessage('API key saved successfully');
        } else {
            $this->addFlashMessage('Invalid API key', '', FlashMessage::ERROR);
        }

        return $this->redirect('apiKeys');
    }

    public function usageAction(): ResponseInterface
    {
        $this->view->assign('costReport', $this->usageTracker->getCostReport(
            new \DateTime('-30 days')
        ));
        return $this->htmlResponse();
    }

    public function testProviderAction(string $provider): ResponseInterface
    {
        try {
            $response = $this->llmManager->complete(
                'Say "Hello from AI Base!" in exactly those words.',
                ['provider' => $provider, 'max_tokens' => 20]
            );

            return $this->jsonResponse([
                'success' => true,
                'response' => $response->getContent(),
            ]);
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

### Module Registration (Configuration/Backend/Modules.php)

```php
<?php
return [
    'aibase' => [
        'parent' => 'system',
        'position' => ['after' => 'config'],
        'access' => 'admin',
        'iconIdentifier' => 'module-aibase',
        'labels' => 'LLL:EXT:ai_base/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => \Netresearch\AiBase\Controller\Backend\AiControlPanelController::class . '::dashboardAction',
            ],
        ],
    ],
];
```

---

## 10. Testing Strategy

### Unit Test Structure

```
Tests/
├── Unit/
│   ├── Service/
│   │   ├── Provider/
│   │   │   ├── OpenAiProviderTest.php
│   │   │   ├── AnthropicProviderTest.php
│   │   │   └── ProviderFactoryTest.php
│   │   ├── LlmServiceManagerTest.php
│   │   ├── Feature/
│   │   │   ├── TranslationServiceTest.php
│   │   │   └── ImageDescriptionServiceTest.php
│   │   └── Cache/
│   │       └── AiResponseCacheTest.php
│   └── Security/
│       ├── ApiKeyManagerTest.php
│       └── AccessControlTest.php
├── Functional/
│   ├── Provider/
│   │   └── ProviderIntegrationTest.php
│   └── Service/
│       └── LlmServiceManagerIntegrationTest.php
└── Fixtures/
    ├── MockResponses/
    │   ├── openai_completion.json
    │   ├── anthropic_message.json
    │   └── deepl_translation.json
    └── TestData/
```

### Provider Test Example

```php
<?php
namespace Netresearch\AiBase\Tests\Unit\Service\Provider;

class OpenAiProviderTest extends UnitTestCase
{
    private OpenAiProvider $subject;
    private MockObject $httpClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->subject = new OpenAiProvider(
            $this->httpClient,
            $this->createMock(LoggerInterface::class),
            $this->createMock(EventDispatcherInterface::class),
            'test-api-key'
        );
    }

    #[Test]
    public function completeReturnsValidResponse(): void
    {
        $mockResponse = $this->createMockResponse([
            'choices' => [['message' => ['content' => 'Test response']]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ]);

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->willReturn($mockResponse);

        $result = $this->subject->complete('Test prompt');

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertEquals('Test response', $result->getContent());
        self::assertEquals(10, $result->getPromptTokens());
    }

    #[Test]
    public function getCapabilitiesReturnsCorrectFeatures(): void
    {
        $capabilities = $this->subject->getCapabilities();

        self::assertTrue($capabilities->supportsChat);
        self::assertTrue($capabilities->supportsVision);
        self::assertTrue($capabilities->supportsEmbeddings);
    }
}
```

---

## 11. MVP Delivery Milestones

### MVP 1: Core Foundation (Target: Week 2)
- [ ] Extension skeleton with composer.json
- [ ] ProviderInterface and AbstractProvider
- [ ] OpenAiProvider with chat completion
- [ ] Basic LlmServiceManager
- [ ] Unit tests for core components
- [ ] **Deliverable**: `$llmManager->complete()` works with OpenAI

### MVP 2: Multi-Provider (Target: Week 4)
- [ ] AnthropicProvider implementation
- [ ] OllamaProvider for local testing
- [ ] ProviderFactory with auto-discovery
- [ ] Provider fallback mechanism
- [ ] **Deliverable**: Can switch between 3 providers

### MVP 3: Feature Services (Target: Week 6)
- [ ] TranslationService with DeepL
- [ ] ImageDescriptionService for rte-ckeditor-image
- [ ] Basic caching layer
- [ ] **Deliverable**: Translation and image alt-text generation

### MVP 4: Security & Quotas (Target: Week 8)
- [ ] ApiKeyManager with encryption
- [ ] RateLimiterService
- [ ] Usage tracking
- [ ] **Deliverable**: Production-safe API key storage

### MVP 5: Backend UI (Target: Week 10)
- [ ] Basic backend module
- [ ] Provider status dashboard
- [ ] Usage reports
- [ ] **Deliverable**: Admin can monitor and configure

### MVP 6: Polish & Release (Target: Week 12)
- [ ] Full test coverage
- [ ] Documentation
- [ ] Integration examples
- [ ] TER submission
- [ ] **Deliverable**: Public release

---

## 12. Database Schema (Complete)

```sql
-- API Keys Storage (encrypted)
CREATE TABLE tx_aibase_apikeys (
    uid INT PRIMARY KEY AUTO_INCREMENT,
    pid INT DEFAULT 0,
    provider VARCHAR(50) NOT NULL,
    scope ENUM('global', 'site', 'user') DEFAULT 'global',
    scope_id INT DEFAULT 0,
    api_key TEXT NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    tstamp INT UNSIGNED DEFAULT 0,
    crdate INT UNSIGNED DEFAULT 0
);

-- Usage Tracking
CREATE TABLE tx_aibase_usage (
    uid INT PRIMARY KEY AUTO_INCREMENT,
    pid INT DEFAULT 0,
    user_id INT NOT NULL,
    provider VARCHAR(50) NOT NULL,
    feature VARCHAR(100) NOT NULL,
    model VARCHAR(100) DEFAULT '',
    prompt_tokens INT DEFAULT 0,
    completion_tokens INT DEFAULT 0,
    estimated_cost DECIMAL(10,6) DEFAULT 0,
    request_duration INT DEFAULT 0,
    cache_hit TINYINT(1) DEFAULT 0,
    tstamp INT UNSIGNED DEFAULT 0
);

-- Quota Management
CREATE TABLE tx_aibase_quotas (
    uid INT PRIMARY KEY AUTO_INCREMENT,
    pid INT DEFAULT 0,
    user_id INT NOT NULL,
    user_group_id INT DEFAULT 0,
    quota_period ENUM('hourly', 'daily', 'weekly', 'monthly') DEFAULT 'daily',
    requests_used INT DEFAULT 0,
    requests_limit INT DEFAULT 100,
    cost_used DECIMAL(10,6) DEFAULT 0,
    cost_limit DECIMAL(10,6) DEFAULT 10,
    last_reset INT UNSIGNED DEFAULT 0,
    tstamp INT UNSIGNED DEFAULT 0
);

-- Prompt Templates
CREATE TABLE tx_aibase_prompts (
    uid INT PRIMARY KEY AUTO_INCREMENT,
    pid INT DEFAULT 0,
    title VARCHAR(255) NOT NULL,
    identifier VARCHAR(100) NOT NULL UNIQUE,
    feature VARCHAR(100) NOT NULL,
    system_prompt TEXT,
    user_prompt_template TEXT,
    provider VARCHAR(50) DEFAULT '',
    model VARCHAR(100) DEFAULT '',
    temperature DECIMAL(3,2) DEFAULT 0.70,
    max_tokens INT DEFAULT 1000,
    is_default TINYINT(1) DEFAULT 0,
    tstamp INT UNSIGNED DEFAULT 0,
    crdate INT UNSIGNED DEFAULT 0
);

-- Response Cache (optional persistent)
CREATE TABLE tx_aibase_cache (
    uid INT PRIMARY KEY AUTO_INCREMENT,
    cache_key VARCHAR(64) NOT NULL UNIQUE,
    feature VARCHAR(100) NOT NULL,
    response MEDIUMTEXT NOT NULL,
    expires INT UNSIGNED DEFAULT 0,
    tstamp INT UNSIGNED DEFAULT 0,

    INDEX idx_expires (expires),
    INDEX idx_feature (feature)
);
```

---

## 13. Configuration Reference

### ext_conf_template.txt

```
# cat=basic; type=options[openai,anthropic,gemini,ollama,openrouter]; label=Default Provider
defaultProvider = openai

# cat=basic; type=boolean; label=Enable Caching
enableCaching = 1

# cat=basic; type=int+; label=Default Cache TTL (seconds)
cacheTtl = 3600

# cat=providers/openai; type=string; label=OpenAI API Key
providers.openai.apiKey =

# cat=providers/openai; type=string; label=OpenAI Default Model
providers.openai.defaultModel = gpt-4o

# cat=providers/anthropic; type=string; label=Anthropic API Key
providers.anthropic.apiKey =

# cat=providers/anthropic; type=string; label=Anthropic Default Model
providers.anthropic.defaultModel = claude-sonnet-4-20250514

# cat=providers/gemini; type=string; label=Google Gemini API Key
providers.gemini.apiKey =

# cat=providers/deepl; type=string; label=DeepL API Key
providers.deepl.apiKey =

# cat=providers/ollama; type=string; label=Ollama Host URL
providers.ollama.host = http://localhost:11434

# cat=providers/openrouter; type=string; label=OpenRouter API Key
providers.openrouter.apiKey =

# cat=quotas; type=int+; label=Daily Request Limit per User (0 = unlimited)
quotas.dailyLimit = 100

# cat=quotas; type=string; label=Monthly Cost Limit per User (0 = unlimited)
quotas.monthlyCostLimit = 50.00

# cat=security; type=boolean; label=Allow Environment Variable API Keys
security.allowEnvKeys = 1

# cat=features/translation; type=options[deepl,openai,anthropic,gemini]; label=Preferred Translation Provider
features.translation.preferredProvider = deepl

# cat=features/vision; type=options[openai,anthropic,gemini,ollama]; label=Preferred Vision Provider
features.vision.preferredProvider = openai
```

---

## Document Status

| Section | Status | Notes |
|---------|--------|-------|
| Provider Abstraction | Complete | Ready for implementation |
| OpenAI Provider | Complete | Full specification |
| Anthropic Provider | Complete | Full specification |
| DeepL Provider | Complete | Translation specialist |
| Ollama Provider | Complete | Local model support |
| Gemini Provider | Complete | Google AI integration |
| OpenRouter Provider | Complete | Multi-model gateway |
| LlmServiceManager | Complete | Facade pattern |
| Feature Services | Complete | High-level abstractions |
| Caching | Complete | TYPO3 cache integration |
| Rate Limiting | Complete | Per-user quotas |
| Security | Complete | Encrypted key storage |
| Events | Complete | PSR-14 events |
| Backend Module | Complete | Admin UI structure |
| Testing Strategy | Complete | Unit + functional tests |
| MVP Milestones | Complete | 6 iterations defined |
| Database Schema | Complete | Full SQL definitions |
| Configuration | Complete | Extension settings |

---

---

## 14. Enhancement Recommendations (From Deep Review)

Based on comprehensive analysis with gemini-3-pro, the following enhancements are recommended:

### 14.1 Circuit Breaker Pattern (MEDIUM Priority)

Add resilience for provider failover:

```php
<?php
namespace Netresearch\AiBase\Service\Resilience;

class CircuitBreaker
{
    private const STATE_CLOSED = 'closed';
    private const STATE_OPEN = 'open';
    private const STATE_HALF_OPEN = 'half_open';

    private string $state = self::STATE_CLOSED;
    private int $failureCount = 0;
    private int $failureThreshold = 5;
    private int $recoveryTimeout = 30; // seconds
    private ?int $lastFailureTime = null;

    public function __construct(
        private CacheManager $cacheManager,
        private string $serviceId,
    ) {}

    public function isAvailable(): bool
    {
        $this->loadState();

        if ($this->state === self::STATE_CLOSED) {
            return true;
        }

        if ($this->state === self::STATE_OPEN) {
            if (time() - $this->lastFailureTime > $this->recoveryTimeout) {
                $this->state = self::STATE_HALF_OPEN;
                $this->saveState();
                return true;
            }
            return false;
        }

        return true; // HALF_OPEN allows one request
    }

    public function recordSuccess(): void
    {
        $this->failureCount = 0;
        $this->state = self::STATE_CLOSED;
        $this->saveState();
    }

    public function recordFailure(): void
    {
        $this->failureCount++;
        $this->lastFailureTime = time();

        if ($this->failureCount >= $this->failureThreshold) {
            $this->state = self::STATE_OPEN;
        }

        $this->saveState();
    }
}
```

### 14.2 Async/Queue-Based Processing (MEDIUM Priority)

For long-running AI operations, integrate with TYPO3's Symfony Messenger:

```php
<?php
namespace Netresearch\AiBase\Message;

final class AiRequestMessage
{
    public function __construct(
        public readonly string $feature,
        public readonly array $params,
        public readonly array $options,
        public readonly int $userId,
        public readonly string $callbackIdentifier,
    ) {}
}

// Handler
class AiRequestMessageHandler
{
    public function __construct(
        private LlmServiceManager $llmManager,
        private SignalSlotDispatcher $signalSlot,
    ) {}

    public function __invoke(AiRequestMessage $message): void
    {
        $response = $this->llmManager->execute(
            $message->feature,
            $message->params,
            $message->options
        );

        // Dispatch result event for async consumers
        $this->signalSlot->dispatch(
            AiAsyncResponseEvent::class,
            $message->callbackIdentifier,
            $response
        );
    }
}
```

### 14.3 Audit Logging (LOW Priority)

Add compliance-ready audit logging:

```php
<?php
namespace Netresearch\AiBase\Service;

class AuditLogger
{
    public function __construct(
        private LoggerInterface $logger,
        private AuditLogRepository $repository,
    ) {}

    public function logRequest(
        int $userId,
        string $provider,
        string $feature,
        string $promptHash, // Never log actual prompts
        int $inputTokens,
        int $outputTokens,
        float $cost,
        bool $cached,
        ?string $errorCode = null
    ): void {
        $entry = new AuditLogEntry();
        $entry->setUserId($userId);
        $entry->setProvider($provider);
        $entry->setFeature($feature);
        $entry->setPromptHash($promptHash);
        $entry->setInputTokens($inputTokens);
        $entry->setOutputTokens($outputTokens);
        $entry->setCost($cost);
        $entry->setCached($cached);
        $entry->setErrorCode($errorCode);
        $entry->setIpAddress($this->getClientIp());
        $entry->setTimestamp(new \DateTime());

        $this->repository->add($entry);

        // Also log to system logger for SIEM integration
        $this->logger->info('AI Request', [
            'user_id' => $userId,
            'provider' => $provider,
            'feature' => $feature,
            'cached' => $cached,
        ]);
    }
}
```

### 14.4 Multi-Site Configuration Isolation (LOW Priority)

Enhance site-aware configuration:

```php
<?php
namespace Netresearch\AiBase\Configuration;

class SiteAwareConfigurationManager
{
    public function __construct(
        private SiteFinder $siteFinder,
        private ConfigurationManager $baseConfigManager,
    ) {}

    public function getForCurrentSite(string $key): mixed
    {
        $site = $this->getCurrentSite();

        // 1. Check site-specific configuration
        $siteConfig = $site->getConfiguration()['aibase'] ?? [];
        if (isset($siteConfig[$key])) {
            return $siteConfig[$key];
        }

        // 2. Fall back to global configuration
        return $this->baseConfigManager->get($key);
    }

    public function getProviderForSite(string $feature, Site $site): string
    {
        $siteConfig = $site->getConfiguration()['aibase'] ?? [];

        return $siteConfig['features'][$feature]['provider']
            ?? $this->baseConfigManager->getFeatureProvider($feature);
    }

    public function getSiteQuotaLimits(Site $site): array
    {
        $siteConfig = $site->getConfiguration()['aibase'] ?? [];

        return [
            'daily_requests' => $siteConfig['quotas']['daily'] ?? 100,
            'monthly_cost' => $siteConfig['quotas']['monthly_cost'] ?? 50.0,
        ];
    }
}
```

### 14.5 Additional Database Tables for Enhancements

```sql
-- Audit Log
CREATE TABLE tx_aibase_audit_log (
    uid INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    provider VARCHAR(50) NOT NULL,
    feature VARCHAR(100) NOT NULL,
    prompt_hash VARCHAR(64) NOT NULL,
    input_tokens INT DEFAULT 0,
    output_tokens INT DEFAULT 0,
    cost DECIMAL(10,6) DEFAULT 0,
    cached TINYINT(1) DEFAULT 0,
    error_code VARCHAR(50),
    ip_address VARCHAR(45),
    site_identifier VARCHAR(100),
    tstamp INT UNSIGNED DEFAULT 0,

    INDEX idx_user (user_id),
    INDEX idx_provider (provider),
    INDEX idx_tstamp (tstamp)
);

-- Circuit Breaker State
CREATE TABLE tx_aibase_circuit_breaker (
    uid INT PRIMARY KEY AUTO_INCREMENT,
    service_id VARCHAR(100) NOT NULL UNIQUE,
    state ENUM('closed', 'open', 'half_open') DEFAULT 'closed',
    failure_count INT DEFAULT 0,
    last_failure_time INT UNSIGNED,
    tstamp INT UNSIGNED DEFAULT 0
);
```

---

## 15. Review Summary

| Aspect | Assessment | Notes |
|--------|------------|-------|
| Architecture | ✅ Solid | Strategy, Factory, Facade patterns correctly applied |
| SOLID Principles | ✅ Compliant | Clear SRP, OCP, LSP, ISP, DIP adherence |
| TYPO3 Best Practices | ✅ Compliant | Uses DI, CacheManager, PSR-14 events, Services.yaml |
| Security | ✅ Comprehensive | AES-256-GCM, env vars, access control, rate limiting |
| Performance | ✅ Optimized | Caching, rate limiting, batch operations |
| Extensibility | ✅ Excellent | PSR-14 events, provider auto-discovery, feature abstraction |
| Testing Strategy | ✅ Defined | Unit + functional tests, mock fixtures |
| Integration Patterns | ✅ Clear | textdb, rte-ckeditor-image, contexts hooks defined |

### Identified Enhancements (Incorporated Above)
1. **Circuit Breaker** - Provider failover resilience
2. **Async Processing** - Queue-based long operations
3. **Audit Logging** - Compliance-ready logging
4. **Multi-Site Isolation** - Site-aware configuration

### Recommended MVP Adjustment
Add **MVP 4.5** (Week 9): Resilience & Audit
- [ ] Circuit breaker implementation
- [ ] Audit logging
- [ ] Multi-site configuration support

---

*Plan validated and enhanced: 2025-12-23*
*Review by: gemini-3-pro-preview (thinking mode: max)*
