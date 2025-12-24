<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Netresearch\NrLlm\Domain\Model\LlmResponse;
use Netresearch\NrLlm\Domain\Model\TranslationResponse;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\StreamChunk;
use Netresearch\NrLlm\Service\Provider\ProviderInterface;
use Netresearch\NrLlm\Service\Provider\ProviderFactory;
use Netresearch\NrLlm\Service\Request\RequestBuilder;
use Netresearch\NrLlm\Service\Response\ResponseParser;
use Netresearch\NrLlm\Service\Stream\StreamHandler;
use Netresearch\NrLlm\Service\RateLimit\RateLimiter;
use Netresearch\NrLlm\Exception\LlmException;
use Netresearch\NrLlm\Exception\QuotaExceededException;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * Main facade for all LLM operations in TYPO3
 *
 * This is the primary public API for consuming extensions.
 * Provides a stable, simple interface for:
 * - Text completion
 * - Streaming responses
 * - Translation
 * - Image analysis (vision)
 * - Text embeddings
 *
 * @api This class is part of the public API and follows semantic versioning
 */
class LlmServiceManager
{
    private ?string $preferredProvider = null;
    private array $defaultOptions = [];
    private bool $cacheEnabled = true;
    private ?int $cacheTtl = 3600;
    private bool $rateLimitEnabled = true;

    public function __construct(
        private readonly ProviderFactory $providerFactory,
        private readonly RequestBuilder $requestBuilder,
        private readonly ResponseParser $responseParser,
        private readonly StreamHandler $streamHandler,
        private readonly FrontendInterface $cache,
        private readonly RateLimiter $rateLimiter,
        private readonly LoggerInterface $logger
    ) {}

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
     *   - string 'system_prompt': System message for context
     * @return LlmResponse The normalized response
     * @throws QuotaExceededException If rate limit exceeded
     * @throws LlmException If request fails
     *
     * @api
     */
    public function complete(string $prompt, array $options = []): LlmResponse
    {
        $this->logger->debug('LLM completion request', [
            'prompt_length' => strlen($prompt),
            'options' => array_keys($options),
        ]);

        // Check rate limits
        if ($this->rateLimitEnabled) {
            $this->rateLimiter->assertNotExceeded();
        }

        // Merge options
        $mergedOptions = array_merge($this->defaultOptions, $options);

        // Check cache
        if ($this->shouldCache($mergedOptions)) {
            $cacheKey = $this->getCacheKey('complete', ['prompt' => $prompt, 'options' => $mergedOptions]);
            $cached = $this->cache->get($cacheKey);
            if ($cached !== false) {
                $this->logger->debug('Cache hit for completion request', ['cache_key' => $cacheKey]);
                return $cached;
            }
        }

        // Build request
        $request = $this->requestBuilder
            ->prompt($prompt)
            ->fromArray($mergedOptions)
            ->build();

        // Get provider and execute
        $provider = $this->getProvider();
        $response = $provider->complete($prompt, $request);

        // Parse response
        $parsedResponse = $this->responseParser->parse(
            $response,
            $provider->getIdentifier()
        );

        // Cache if enabled
        if ($this->shouldCache($mergedOptions)) {
            $this->cache->set(
                $cacheKey,
                $parsedResponse,
                [],
                $this->cacheTtl ?? 3600
            );
        }

        $this->logger->info('LLM completion successful', [
            'provider' => $provider->getIdentifier(),
            'tokens' => $parsedResponse->getTotalTokens(),
        ]);

        return $parsedResponse;
    }

    /**
     * Stream a completion response in real-time
     *
     * @param string $prompt The user prompt
     * @param callable $callback Function called for each chunk: function(StreamChunk $chunk): void
     * @param array $options Same as complete()
     * @return void
     * @throws QuotaExceededException If rate limit exceeded
     * @throws LlmException If streaming fails
     *
     * @api
     */
    public function stream(string $prompt, callable $callback, array $options = []): void
    {
        $this->logger->debug('LLM streaming request', [
            'prompt_length' => strlen($prompt),
        ]);

        // Check rate limits
        if ($this->rateLimitEnabled) {
            $this->rateLimiter->assertNotExceeded();
        }

        // Merge options and force streaming
        $mergedOptions = array_merge($this->defaultOptions, $options, ['stream' => true]);

        // Build request
        $request = $this->requestBuilder
            ->prompt($prompt)
            ->fromArray($mergedOptions)
            ->build();

        // Get provider
        $provider = $this->getProvider();

        // Create internal callback that parses chunks
        $wrappedCallback = function ($rawChunk) use ($callback, $provider) {
            $parsedChunk = $this->responseParser->parseStream(
                $rawChunk,
                $provider->getIdentifier()
            );

            if ($parsedChunk !== null) {
                $callback($parsedChunk);
            }
        };

        // Execute streaming request
        $provider->stream($prompt, $wrappedCallback, $request);

        $this->logger->info('LLM streaming completed', [
            'provider' => $provider->getIdentifier(),
        ]);
    }

    /**
     * Translate text from source to target language
     *
     * @param string $text Text to translate
     * @param string $targetLang Target language code (ISO 639-1)
     * @param string|null $sourceLang Source language code (null for auto-detect)
     * @return TranslationResponse Translation with confidence and alternatives
     * @throws QuotaExceededException If rate limit exceeded
     * @throws LlmException If translation fails
     *
     * @api
     */
    public function translate(
        string $text,
        string $targetLang,
        ?string $sourceLang = null
    ): TranslationResponse {
        $this->logger->debug('Translation request', [
            'text_length' => strlen($text),
            'target_lang' => $targetLang,
            'source_lang' => $sourceLang,
        ]);

        // Check rate limits
        if ($this->rateLimitEnabled) {
            $this->rateLimiter->assertNotExceeded();
        }

        // Check cache
        if ($this->cacheEnabled) {
            $cacheKey = $this->getCacheKey('translate', [
                'text' => $text,
                'target' => $targetLang,
                'source' => $sourceLang,
            ]);
            $cached = $this->cache->get($cacheKey);
            if ($cached !== false) {
                $this->logger->debug('Cache hit for translation', ['cache_key' => $cacheKey]);
                return $cached;
            }
        }

        // Get provider and execute
        $provider = $this->getProvider();

        // Check if provider supports translation natively
        if (method_exists($provider, 'translate')) {
            $response = $provider->translate($text, $targetLang, $sourceLang);
        } else {
            // Fallback to completion-based translation
            $response = $this->translateViaCompletion($text, $targetLang, $sourceLang);
        }

        // Cache if enabled
        if ($this->cacheEnabled) {
            $this->cache->set(
                $cacheKey,
                $response,
                [],
                $this->cacheTtl ?? 3600
            );
        }

        $this->logger->info('Translation successful', [
            'provider' => $provider->getIdentifier(),
            'confidence' => $response->getConfidence(),
        ]);

        return $response;
    }

    /**
     * Analyze image and generate description
     *
     * @param string $imageUrl URL or path to image
     * @param string $prompt Analysis instructions (e.g., "Generate alt text")
     * @return VisionResponse Image analysis with description and metadata
     * @throws QuotaExceededException If rate limit exceeded
     * @throws LlmException If analysis fails
     *
     * @api
     */
    public function analyzeImage(string $imageUrl, string $prompt): VisionResponse
    {
        $this->logger->debug('Image analysis request', [
            'image_url' => $imageUrl,
            'prompt' => $prompt,
        ]);

        // Check rate limits
        if ($this->rateLimitEnabled) {
            $this->rateLimiter->assertNotExceeded();
        }

        // Check cache
        if ($this->cacheEnabled) {
            $cacheKey = $this->getCacheKey('vision', [
                'image' => $imageUrl,
                'prompt' => $prompt,
            ]);
            $cached = $this->cache->get($cacheKey);
            if ($cached !== false) {
                $this->logger->debug('Cache hit for image analysis', ['cache_key' => $cacheKey]);
                return $cached;
            }
        }

        // Get provider
        $provider = $this->getProvider();

        // Execute vision request
        $response = $provider->analyzeImage($imageUrl, $prompt);

        // Cache if enabled
        if ($this->cacheEnabled) {
            $this->cache->set(
                $cacheKey,
                $response,
                [],
                $this->cacheTtl ?? 86400  // Cache vision for 24h
            );
        }

        $this->logger->info('Image analysis successful', [
            'provider' => $provider->getIdentifier(),
            'confidence' => $response->getConfidence(),
        ]);

        return $response;
    }

    /**
     * Generate embeddings for text
     *
     * @param string|array $text Single text or array of texts
     * @return EmbeddingResponse Embedding vectors
     * @throws QuotaExceededException If rate limit exceeded
     * @throws LlmException If embedding fails
     *
     * @api
     */
    public function embed(string|array $text): EmbeddingResponse
    {
        $texts = is_array($text) ? $text : [$text];

        $this->logger->debug('Embedding request', [
            'text_count' => count($texts),
        ]);

        // Check rate limits
        if ($this->rateLimitEnabled) {
            $this->rateLimiter->assertNotExceeded();
        }

        // Check cache
        if ($this->cacheEnabled) {
            $cacheKey = $this->getCacheKey('embed', ['texts' => $texts]);
            $cached = $this->cache->get($cacheKey);
            if ($cached !== false) {
                $this->logger->debug('Cache hit for embedding', ['cache_key' => $cacheKey]);
                return $cached;
            }
        }

        // Get provider and execute
        $provider = $this->getProvider();
        $response = $provider->embed($texts);

        // Cache if enabled
        if ($this->cacheEnabled) {
            $this->cache->set(
                $cacheKey,
                $response,
                [],
                $this->cacheTtl ?? 86400  // Cache embeddings for 24h
            );
        }

        $this->logger->info('Embedding successful', [
            'provider' => $provider->getIdentifier(),
            'embedding_count' => count($response->getEmbeddings()),
        ]);

        return $response;
    }

    /**
     * Set preferred provider for subsequent requests
     *
     * @param string $providerName Provider identifier (e.g., 'openai', 'anthropic')
     * @return self Fluent interface
     *
     * @api
     */
    public function setProvider(string $providerName): self
    {
        $this->preferredProvider = $providerName;
        return $this;
    }

    /**
     * Get current provider instance
     *
     * @return ProviderInterface Active provider
     *
     * @api
     */
    public function getProvider(): ProviderInterface
    {
        return $this->providerFactory->create($this->preferredProvider);
    }

    /**
     * Get list of available provider identifiers
     *
     * @return array Provider identifiers
     *
     * @api
     */
    public function getAvailableProviders(): array
    {
        return $this->providerFactory->getAvailableProviders();
    }

    /**
     * Get default provider identifier
     *
     * @return string Default provider name
     *
     * @api
     */
    public function getDefaultProvider(): string
    {
        return $this->providerFactory->getDefaultProvider();
    }

    /**
     * Set default options for all requests
     *
     * @param array $options Default options to merge with request options
     * @return self Fluent interface
     *
     * @api
     */
    public function withOptions(array $options): self
    {
        $clone = clone $this;
        $clone->defaultOptions = array_merge($this->defaultOptions, $options);
        return $clone;
    }

    /**
     * Enable or disable caching
     *
     * @param bool $enabled Enable caching
     * @param int|null $ttl Cache TTL in seconds (null for default)
     * @return self Fluent interface
     *
     * @api
     */
    public function withCache(bool $enabled, ?int $ttl = null): self
    {
        $clone = clone $this;
        $clone->cacheEnabled = $enabled;
        if ($ttl !== null) {
            $clone->cacheTtl = $ttl;
        }
        return $clone;
    }

    /**
     * Enable or disable rate limiting
     *
     * @param bool $enabled Enable rate limiting
     * @return self Fluent interface
     *
     * @api
     */
    public function withRateLimit(bool $enabled): self
    {
        $clone = clone $this;
        $clone->rateLimitEnabled = $enabled;
        return $clone;
    }

    /**
     * Fallback translation via completion API
     *
     * @param string $text Text to translate
     * @param string $targetLang Target language
     * @param string|null $sourceLang Source language
     * @return TranslationResponse
     */
    private function translateViaCompletion(
        string $text,
        string $targetLang,
        ?string $sourceLang = null
    ): TranslationResponse {
        $sourceInfo = $sourceLang ? "from {$sourceLang} " : '';
        $prompt = "Translate the following text {$sourceInfo}to {$targetLang}. "
                  . "Provide only the translation, no explanations:\n\n{$text}";

        $response = $this->complete($prompt, [
            'temperature' => 0.3,  // Lower temperature for deterministic translation
            'max_tokens' => strlen($text) * 3,  // Estimate max tokens
        ]);

        return new TranslationResponse(
            translation: trim($response->getContent()),
            confidence: 0.8,  // Lower confidence for fallback method
            alternatives: [],
            content: $response->getContent(),
            usage: $response->getUsage(),
            metadata: $response->getMetadata()
        );
    }

    /**
     * Generate deterministic cache key
     *
     * @param string $operation Operation type
     * @param array $params Parameters
     * @return string Cache key
     */
    private function getCacheKey(string $operation, array $params): string
    {
        $normalized = $this->normalizeParams($params);
        $data = [
            'operation' => $operation,
            'provider' => $this->preferredProvider ?? 'default',
            'params' => $normalized,
        ];
        return 'llm_' . hash('sha256', json_encode($data));
    }

    /**
     * Normalize parameters for cache key generation
     *
     * @param array $params Raw parameters
     * @return array Normalized parameters
     */
    private function normalizeParams(array $params): array
    {
        // Sort to ensure consistent ordering
        ksort($params);

        // Recursively sort nested arrays
        array_walk_recursive($params, function (&$value) {
            if (is_array($value)) {
                ksort($value);
            }
        });

        return $params;
    }

    /**
     * Determine if response should be cached
     *
     * @param array $options Request options
     * @return bool
     */
    private function shouldCache(array $options): bool
    {
        if (!$this->cacheEnabled) {
            return false;
        }

        // Don't cache streaming
        if ($options['stream'] ?? false) {
            return false;
        }

        // Don't cache high-temperature requests (too random)
        if (($options['temperature'] ?? 0.7) > 0.9) {
            return false;
        }

        return true;
    }
}
