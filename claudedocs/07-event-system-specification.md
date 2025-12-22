# PSR-14 Event System Specification - nr-llm Extension

> Analysis Date: 2025-12-22
> Purpose: Complete PSR-14 event architecture for extensibility and lifecycle hooks

---

## 1. Event Architecture Overview

### Design Principles
- **Immutable Events**: Events carry data but don't modify it directly
- **Propagation Control**: Support for event stopping
- **Context Preservation**: All events carry request context and user info
- **Performance**: Minimal overhead, lazy instantiation
- **Type Safety**: Full typed properties and return types

### Event Flow Diagram

```
Request → BeforeLlmRequestEvent
          ↓
       Provider Selection → ProviderSelectedEvent
          ↓
       Cache Check → CacheHitEvent (if cached)
          ↓
       Quota Check → QuotaExceededEvent (if exceeded)
          ↓
       API Call → RequestFailedEvent (if error)
          ↓
       Response → AfterLlmResponseEvent
          ↓
       Return to caller
```

---

## 2. Event Class Definitions

### BeforeLlmRequestEvent

**Purpose**: Modify or inspect request before sending to provider

```php
<?php
declare(strict_types=1);

namespace Netresearch\NrLlm\Event;

use Netresearch\NrLlm\Domain\Model\LlmRequest;
use TYPO3\CMS\Backend\Authentication\BackendUserAuthentication;

/**
 * Dispatched before an LLM request is sent to the provider.
 *
 * Allows listeners to:
 * - Modify the request (prompt, parameters, model selection)
 * - Add custom headers or metadata
 * - Cancel the request by stopping propagation
 * - Log or audit request details
 * - Apply content filtering or sanitization
 */
final class BeforeLlmRequestEvent
{
    private bool $propagationStopped = false;
    private ?string $cancellationReason = null;

    public function __construct(
        private LlmRequest $request,
        private string $provider,
        private readonly ?BackendUserAuthentication $user = null,
        private readonly array $context = []
    ) {}

    /**
     * Get the request object
     */
    public function getRequest(): LlmRequest
    {
        return $this->request;
    }

    /**
     * Replace the entire request (use with caution)
     */
    public function setRequest(LlmRequest $request): void
    {
        $this->request = $request;
    }

    /**
     * Get the selected provider name
     */
    public function getProvider(): string
    {
        return $this->provider;
    }

    /**
     * Change the provider (for request routing)
     */
    public function setProvider(string $provider): void
    {
        $this->provider = $provider;
    }

    /**
     * Get the backend user making the request
     */
    public function getUser(): ?BackendUserAuthentication
    {
        return $this->user;
    }

    /**
     * Get additional request context
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Cancel the request
     */
    public function cancelRequest(string $reason): void
    {
        $this->propagationStopped = true;
        $this->cancellationReason = $reason;
    }

    /**
     * Check if request was cancelled
     */
    public function isCancelled(): bool
    {
        return $this->propagationStopped;
    }

    /**
     * Get cancellation reason
     */
    public function getCancellationReason(): ?string
    {
        return $this->cancellationReason;
    }

    /**
     * PSR-14 propagation control
     */
    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }
}
```

---

### AfterLlmResponseEvent

**Purpose**: Process, modify, or log response after receiving from provider

```php
<?php
declare(strict_types=1);

namespace Netresearch\NrLlm\Event;

use Netresearch\NrLlm\Domain\Model\LlmRequest;
use Netresearch\NrLlm\Domain\Model\LlmResponse;
use TYPO3\CMS\Backend\Authentication\BackendUserAuthentication;

/**
 * Dispatched after receiving a response from the LLM provider.
 *
 * Allows listeners to:
 * - Modify response content (filtering, formatting)
 * - Extract and store metadata
 * - Trigger post-processing workflows
 * - Update analytics or usage tracking
 * - Cache responses with custom keys
 */
final class AfterLlmResponseEvent
{
    public function __construct(
        private readonly LlmRequest $request,
        private LlmResponse $response,
        private readonly string $provider,
        private readonly ?BackendUserAuthentication $user = null,
        private readonly array $context = []
    ) {}

    /**
     * Get the original request
     */
    public function getRequest(): LlmRequest
    {
        return $this->request;
    }

    /**
     * Get the response object
     */
    public function getResponse(): LlmResponse
    {
        return $this->response;
    }

    /**
     * Replace the response (for filtering/transformation)
     */
    public function setResponse(LlmResponse $response): void
    {
        $this->response = $response;
    }

    /**
     * Get the provider that handled the request
     */
    public function getProvider(): string
    {
        return $this->provider;
    }

    /**
     * Get the backend user
     */
    public function getUser(): ?BackendUserAuthentication
    {
        return $this->user;
    }

    /**
     * Get request context
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Get response processing duration
     */
    public function getProcessingTime(): float
    {
        return $this->response->getProcessingTime();
    }

    /**
     * Get token usage information
     */
    public function getTokenUsage(): array
    {
        return [
            'prompt_tokens' => $this->response->getPromptTokens(),
            'completion_tokens' => $this->response->getCompletionTokens(),
            'total_tokens' => $this->response->getTotalTokens(),
        ];
    }
}
```

---

### ProviderSelectedEvent

**Purpose**: Notify when provider is chosen, allow override

```php
<?php
declare(strict_types=1);

namespace Netresearch\NrLlm\Event;

use Netresearch\NrLlm\Domain\Model\LlmRequest;
use TYPO3\CMS\Backend\Authentication\BackendUserAuthentication;

/**
 * Dispatched when a provider is selected for a request.
 *
 * Allows listeners to:
 * - Override provider selection based on custom logic
 * - Implement intelligent routing (by feature, user, cost)
 * - Apply provider-specific configuration
 * - Log provider usage patterns
 * - Implement A/B testing of providers
 */
final class ProviderSelectedEvent
{
    public function __construct(
        private string $selectedProvider,
        private readonly LlmRequest $request,
        private readonly ?string $requestedProvider = null,
        private readonly array $availableProviders = [],
        private readonly ?BackendUserAuthentication $user = null,
        private readonly array $selectionCriteria = []
    ) {}

    /**
     * Get the selected provider
     */
    public function getSelectedProvider(): string
    {
        return $this->selectedProvider;
    }

    /**
     * Change the selected provider
     */
    public function setSelectedProvider(string $provider): void
    {
        if (!in_array($provider, $this->availableProviders, true)) {
            throw new \InvalidArgumentException(
                sprintf('Provider "%s" is not available. Available: %s',
                    $provider,
                    implode(', ', $this->availableProviders)
                )
            );
        }
        $this->selectedProvider = $provider;
    }

    /**
     * Get the request object
     */
    public function getRequest(): LlmRequest
    {
        return $this->request;
    }

    /**
     * Get the originally requested provider (may be null for auto-selection)
     */
    public function getRequestedProvider(): ?string
    {
        return $this->requestedProvider;
    }

    /**
     * Get list of available providers
     */
    public function getAvailableProviders(): array
    {
        return $this->availableProviders;
    }

    /**
     * Get the backend user
     */
    public function getUser(): ?BackendUserAuthentication
    {
        return $this->user;
    }

    /**
     * Get selection criteria used (cost, performance, capabilities)
     */
    public function getSelectionCriteria(): array
    {
        return $this->selectionCriteria;
    }
}
```

---

### QuotaExceededEvent

**Purpose**: Handle quota violations with grace

```php
<?php
declare(strict_types=1);

namespace Netresearch\NrLlm\Event;

use Netresearch\NrLlm\Domain\Model\LlmRequest;
use Netresearch\NrLlm\Domain\Model\Quota;
use TYPO3\CMS\Backend\Authentication\BackendUserAuthentication;

/**
 * Dispatched when a request would exceed quota limits.
 *
 * Allows listeners to:
 * - Send notifications to users/admins
 * - Implement emergency quota extensions
 * - Log quota violations
 * - Redirect to alternative providers
 * - Queue requests for later processing
 */
final class QuotaExceededEvent
{
    private bool $quotaOverridden = false;

    public function __construct(
        private readonly LlmRequest $request,
        private readonly Quota $quota,
        private readonly string $quotaType,
        private readonly int $currentUsage,
        private readonly int $limit,
        private readonly ?BackendUserAuthentication $user = null
    ) {}

    /**
     * Get the request that triggered quota exceeded
     */
    public function getRequest(): LlmRequest
    {
        return $this->request;
    }

    /**
     * Get the quota object
     */
    public function getQuota(): Quota
    {
        return $this->quota;
    }

    /**
     * Get quota type (daily, monthly, cost, requests)
     */
    public function getQuotaType(): string
    {
        return $this->quotaType;
    }

    /**
     * Get current usage amount
     */
    public function getCurrentUsage(): int
    {
        return $this->currentUsage;
    }

    /**
     * Get quota limit
     */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /**
     * Get overage amount
     */
    public function getOverage(): int
    {
        return max(0, $this->currentUsage - $this->limit);
    }

    /**
     * Get the backend user
     */
    public function getUser(): ?BackendUserAuthentication
    {
        return $this->user;
    }

    /**
     * Override quota check (use with extreme caution)
     */
    public function overrideQuota(): void
    {
        $this->quotaOverridden = true;
    }

    /**
     * Check if quota was overridden
     */
    public function isQuotaOverridden(): bool
    {
        return $this->quotaOverridden;
    }
}
```

---

### CacheHitEvent

**Purpose**: Track and optimize cache performance

```php
<?php
declare(strict_types=1);

namespace Netresearch\NrLlm\Event;

use Netresearch\NrLlm\Domain\Model\LlmRequest;
use Netresearch\NrLlm\Domain\Model\LlmResponse;

/**
 * Dispatched when a cached response is used instead of calling the provider.
 *
 * Allows listeners to:
 * - Track cache hit rates
 * - Log cost savings from caching
 * - Update cache statistics
 * - Validate cache freshness
 * - Implement cache warming strategies
 */
final class CacheHitEvent
{
    public function __construct(
        private readonly LlmRequest $request,
        private readonly LlmResponse $cachedResponse,
        private readonly string $cacheKey,
        private readonly int $cacheAge,
        private readonly float $costSaved
    ) {}

    /**
     * Get the request that triggered cache hit
     */
    public function getRequest(): LlmRequest
    {
        return $this->request;
    }

    /**
     * Get the cached response
     */
    public function getCachedResponse(): LlmResponse
    {
        return $this->cachedResponse;
    }

    /**
     * Get the cache key
     */
    public function getCacheKey(): string
    {
        return $this->cacheKey;
    }

    /**
     * Get cache age in seconds
     */
    public function getCacheAge(): int
    {
        return $this->cacheAge;
    }

    /**
     * Get estimated cost saved by using cache
     */
    public function getCostSaved(): float
    {
        return $this->costSaved;
    }

    /**
     * Get cache freshness percentage (0-100)
     */
    public function getFreshnessPercentage(): float
    {
        $ttl = $this->cachedResponse->getCacheTtl();
        if ($ttl === 0) {
            return 100.0;
        }
        return max(0, min(100, (1 - ($this->cacheAge / $ttl)) * 100));
    }
}
```

---

### RequestFailedEvent

**Purpose**: Handle errors gracefully with retry/fallback logic

```php
<?php
declare(strict_types=1);

namespace Netresearch\NrLlm\Event;

use Netresearch\NrLlm\Domain\Model\LlmRequest;
use TYPO3\CMS\Backend\Authentication\BackendUserAuthentication;

/**
 * Dispatched when an LLM request fails.
 *
 * Allows listeners to:
 * - Implement retry logic with backoff
 * - Switch to fallback providers
 * - Log errors for debugging
 * - Send error notifications
 * - Provide default/cached responses
 */
final class RequestFailedEvent
{
    private bool $shouldRetry = false;
    private ?string $fallbackProvider = null;
    private ?int $retryDelayMs = null;

    public function __construct(
        private readonly LlmRequest $request,
        private readonly string $provider,
        private readonly \Throwable $exception,
        private readonly int $attemptNumber,
        private readonly ?BackendUserAuthentication $user = null
    ) {}

    /**
     * Get the failed request
     */
    public function getRequest(): LlmRequest
    {
        return $this->request;
    }

    /**
     * Get the provider that failed
     */
    public function getProvider(): string
    {
        return $this->provider;
    }

    /**
     * Get the exception/error
     */
    public function getException(): \Throwable
    {
        return $this->exception;
    }

    /**
     * Get error message
     */
    public function getErrorMessage(): string
    {
        return $this->exception->getMessage();
    }

    /**
     * Get error code
     */
    public function getErrorCode(): int
    {
        return $this->exception->getCode();
    }

    /**
     * Get current attempt number (1-based)
     */
    public function getAttemptNumber(): int
    {
        return $this->attemptNumber;
    }

    /**
     * Get the backend user
     */
    public function getUser(): ?BackendUserAuthentication
    {
        return $this->user;
    }

    /**
     * Mark for retry
     */
    public function retry(?int $delayMs = null): void
    {
        $this->shouldRetry = true;
        $this->retryDelayMs = $delayMs;
    }

    /**
     * Check if retry is requested
     */
    public function shouldRetry(): bool
    {
        return $this->shouldRetry;
    }

    /**
     * Get retry delay in milliseconds
     */
    public function getRetryDelayMs(): ?int
    {
        return $this->retryDelayMs;
    }

    /**
     * Set fallback provider
     */
    public function setFallbackProvider(string $provider): void
    {
        $this->fallbackProvider = $provider;
    }

    /**
     * Get fallback provider
     */
    public function getFallbackProvider(): ?string
    {
        return $this->fallbackProvider;
    }

    /**
     * Check if error is retryable (network, timeout, rate limit)
     */
    public function isRetryableError(): bool
    {
        $code = $this->getErrorCode();
        // HTTP 429 (rate limit), 503 (service unavailable), 504 (timeout)
        return in_array($code, [429, 503, 504], true);
    }
}
```

---

## 3. Event Listener Registration

### Services.yaml Configuration

```yaml
# Configuration/Services.yaml
services:
  _defaults:
    autowire: true
    autoconfigure: true
    public: false

  # Event Listeners
  Netresearch\NrLlm\EventListener\:
    resource: '../Classes/EventListener/*'
    tags:
      - name: 'event.listener'

  # Example: Content Sanitization Listener
  Netresearch\NrLlm\EventListener\ContentSanitizationListener:
    tags:
      - name: 'event.listener'
        identifier: 'nr-llm/content-sanitization'
        event: Netresearch\NrLlm\Event\BeforeLlmRequestEvent
        method: 'onBeforeRequest'
        priority: 100

  # Example: Cost Tracking Listener
  Netresearch\NrLlm\EventListener\CostTrackingListener:
    tags:
      - name: 'event.listener'
        identifier: 'nr-llm/cost-tracking'
        event: Netresearch\NrLlm\Event\AfterLlmResponseEvent
        method: 'onAfterResponse'
        priority: 50

  # Example: Cache Analytics Listener
  Netresearch\NrLlm\EventListener\CacheAnalyticsListener:
    tags:
      - name: 'event.listener'
        identifier: 'nr-llm/cache-analytics'
        event: Netresearch\NrLlm\Event\CacheHitEvent
        method: 'onCacheHit'

  # Example: Error Notification Listener
  Netresearch\NrLlm\EventListener\ErrorNotificationListener:
    tags:
      - name: 'event.listener'
        identifier: 'nr-llm/error-notification'
        event: Netresearch\NrLlm\Event\RequestFailedEvent
        method: 'onRequestFailed'
        priority: 200

  # Example: Provider Routing Listener
  Netresearch\NrLlm\EventListener\SmartProviderRoutingListener:
    tags:
      - name: 'event.listener'
        identifier: 'nr-llm/smart-routing'
        event: Netresearch\NrLlm\Event\ProviderSelectedEvent
        method: 'onProviderSelected'
        priority: 100

  # Example: Quota Alert Listener
  Netresearch\NrLlm\EventListener\QuotaAlertListener:
    tags:
      - name: 'event.listener'
        identifier: 'nr-llm/quota-alert'
        event: Netresearch\NrLlm\Event\QuotaExceededEvent
        method: 'onQuotaExceeded'
```

---

## 4. Example Event Listener Implementations

### ContentSanitizationListener

```php
<?php
declare(strict_types=1);

namespace Netresearch\NrLlm\EventListener;

use Netresearch\NrLlm\Event\BeforeLlmRequestEvent;
use Netresearch\NrLlm\Service\ContentSanitizer;

/**
 * Sanitizes content before sending to LLM providers
 */
final class ContentSanitizationListener
{
    public function __construct(
        private readonly ContentSanitizer $sanitizer
    ) {}

    public function onBeforeRequest(BeforeLlmRequestEvent $event): void
    {
        $request = $event->getRequest();

        // Sanitize prompt
        $originalPrompt = $request->getPrompt();
        $sanitizedPrompt = $this->sanitizer->sanitize($originalPrompt);

        if ($originalPrompt !== $sanitizedPrompt) {
            $request->setPrompt($sanitizedPrompt);
            $event->setRequest($request);
        }
    }
}
```

### CostTrackingListener

```php
<?php
declare(strict_types=1);

namespace Netresearch\NrLlm\EventListener;

use Netresearch\NrLlm\Event\AfterLlmResponseEvent;
use Netresearch\NrLlm\Service\CostCalculator;
use Netresearch\NrLlm\Repository\UsageRepository;

/**
 * Tracks API usage costs
 */
final class CostTrackingListener
{
    public function __construct(
        private readonly CostCalculator $costCalculator,
        private readonly UsageRepository $usageRepository
    ) {}

    public function onAfterResponse(AfterLlmResponseEvent $event): void
    {
        $response = $event->getResponse();
        $provider = $event->getProvider();

        $cost = $this->costCalculator->calculate(
            provider: $provider,
            promptTokens: $response->getPromptTokens(),
            completionTokens: $response->getCompletionTokens()
        );

        $this->usageRepository->recordUsage(
            user: $event->getUser(),
            provider: $provider,
            cost: $cost,
            tokens: $response->getTotalTokens()
        );
    }
}
```

### SmartProviderRoutingListener

```php
<?php
declare(strict_types=1);

namespace Netresearch\NrLlm\EventListener;

use Netresearch\NrLlm\Event\ProviderSelectedEvent;
use Netresearch\NrLlm\Service\ProviderSelector;

/**
 * Implements intelligent provider routing based on request characteristics
 */
final class SmartProviderRoutingListener
{
    public function __construct(
        private readonly ProviderSelector $selector
    ) {}

    public function onProviderSelected(ProviderSelectedEvent $event): void
    {
        $request = $event->getRequest();

        // Route vision requests to providers with vision capabilities
        if ($request->hasImages()) {
            $visionProviders = ['openai', 'anthropic', 'gemini'];
            $available = array_intersect(
                $visionProviders,
                $event->getAvailableProviders()
            );

            if (!empty($available) &&
                !in_array($event->getSelectedProvider(), $available, true)) {
                $event->setSelectedProvider(reset($available));
            }
        }

        // Route large context requests to Anthropic/Gemini
        if ($request->getEstimatedTokens() > 10000) {
            $longContextProviders = ['anthropic', 'gemini'];
            $available = array_intersect(
                $longContextProviders,
                $event->getAvailableProviders()
            );

            if (!empty($available)) {
                $event->setSelectedProvider(reset($available));
            }
        }
    }
}
```

---

## 5. Event Dispatcher Integration

### In LlmService

```php
<?php
declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Netresearch\NrLlm\Event\*;
use Psr\EventDispatcher\EventDispatcherInterface;

class LlmService
{
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ProviderFactory $providerFactory,
        private readonly CacheManager $cache
    ) {}

    public function execute(LlmRequest $request): LlmResponse
    {
        // 1. Before request event
        $beforeEvent = new BeforeLlmRequestEvent(
            request: $request,
            provider: $this->getPreferredProvider(),
            user: $this->getCurrentUser(),
            context: $this->getRequestContext()
        );

        $this->eventDispatcher->dispatch($beforeEvent);

        if ($beforeEvent->isCancelled()) {
            throw new RequestCancelledException(
                $beforeEvent->getCancellationReason()
            );
        }

        // Use potentially modified request/provider
        $request = $beforeEvent->getRequest();
        $providerName = $beforeEvent->getProvider();

        // 2. Provider selection event
        $providerEvent = new ProviderSelectedEvent(
            selectedProvider: $providerName,
            request: $request,
            requestedProvider: $request->getPreferredProvider(),
            availableProviders: $this->providerFactory->getAvailableProviders(),
            user: $this->getCurrentUser()
        );

        $this->eventDispatcher->dispatch($providerEvent);
        $providerName = $providerEvent->getSelectedProvider();

        // 3. Check cache
        $cacheKey = $this->buildCacheKey($request);
        if ($cached = $this->cache->get($cacheKey)) {
            $cacheEvent = new CacheHitEvent(
                request: $request,
                cachedResponse: $cached,
                cacheKey: $cacheKey,
                cacheAge: time() - $cached->getCachedAt(),
                costSaved: $this->estimateCost($request)
            );

            $this->eventDispatcher->dispatch($cacheEvent);
            return $cached;
        }

        // 4. Execute request with error handling
        try {
            $provider = $this->providerFactory->create($providerName);
            $response = $provider->complete($request);

            // 5. After response event
            $afterEvent = new AfterLlmResponseEvent(
                request: $request,
                response: $response,
                provider: $providerName,
                user: $this->getCurrentUser(),
                context: $this->getRequestContext()
            );

            $this->eventDispatcher->dispatch($afterEvent);

            return $afterEvent->getResponse();

        } catch (\Throwable $e) {
            $failedEvent = new RequestFailedEvent(
                request: $request,
                provider: $providerName,
                exception: $e,
                attemptNumber: $this->currentAttempt,
                user: $this->getCurrentUser()
            );

            $this->eventDispatcher->dispatch($failedEvent);

            if ($failedEvent->shouldRetry()) {
                return $this->retryRequest($request, $failedEvent);
            }

            if ($fallback = $failedEvent->getFallbackProvider()) {
                return $this->executeWithProvider($request, $fallback);
            }

            throw $e;
        }
    }
}
```

---

## 6. Testing Event Listeners

### Unit Test Example

```php
<?php
declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\EventListener;

use Netresearch\NrLlm\Event\BeforeLlmRequestEvent;
use Netresearch\NrLlm\EventListener\ContentSanitizationListener;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class ContentSanitizationListenerTest extends UnitTestCase
{
    private ContentSanitizationListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $sanitizer = $this->createMock(ContentSanitizer::class);
        $sanitizer->method('sanitize')
            ->willReturnCallback(fn($text) => strip_tags($text));

        $this->listener = new ContentSanitizationListener($sanitizer);
    }

    /**
     * @test
     */
    public function sanitizesPromptContent(): void
    {
        $request = new LlmRequest('Test <script>alert("xss")</script>');
        $event = new BeforeLlmRequestEvent($request, 'openai');

        $this->listener->onBeforeRequest($event);

        $modifiedRequest = $event->getRequest();
        self::assertSame('Test alert("xss")', $modifiedRequest->getPrompt());
    }
}
```

---

## 7. Documentation for Extension Developers

### Using Events in Your Extension

```php
<?php
// In your extension's Services.yaml

services:
  YourVendor\YourExtension\EventListener\CustomLlmListener:
    tags:
      - name: 'event.listener'
        identifier: 'your-extension/custom-logic'
        event: Netresearch\NrLlm\Event\BeforeLlmRequestEvent
        method: 'handleBeforeRequest'
```

```php
<?php
namespace YourVendor\YourExtension\EventListener;

use Netresearch\NrLlm\Event\BeforeLlmRequestEvent;

class CustomLlmListener
{
    public function handleBeforeRequest(BeforeLlmRequestEvent $event): void
    {
        // Add custom metadata
        $request = $event->getRequest();
        $request->setMetadata('source_extension', 'your-extension');

        // Modify prompt if needed
        $prompt = $request->getPrompt();
        $request->setPrompt($this->addContextToPrompt($prompt));

        $event->setRequest($request);
    }
}
```

---

## Summary

### Event Coverage
- ✅ Request lifecycle: Before/After
- ✅ Provider selection: Routing/Override
- ✅ Performance: Cache hits
- ✅ Limits: Quota exceeded
- ✅ Errors: Request failures

### Extensibility Points
- Request modification/cancellation
- Provider routing logic
- Response transformation
- Cost tracking/analytics
- Error handling/retry logic
- Cache optimization

### Integration Effort
- Minimal: Events auto-registered via DI
- Type-safe: Full PHP 8.2+ type hints
- Documented: Clear use cases and examples
- Testable: Unit/integration test support
