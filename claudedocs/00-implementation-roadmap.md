# AI Base Extension - Implementation Roadmap

> Project: netresearch/ai_base
> Created: 2025-12-22
> Purpose: Unified AI/LLM provider abstraction for TYPO3 extensions

---

## Executive Summary

This document outlines the implementation plan for the `ai_base` TYPO3 extension, which provides a unified abstraction layer for AI/LLM services. This extension will serve as the foundation for AI features in Netresearch's TYPO3 extensions including textdb, rte-ckeditor-image, and contexts.

---

## 1. Project Vision

### Goals
1. **Unified Provider Abstraction**: Single API for all AI providers
2. **Ecosystem Enablement**: Allow extensions to focus on features, not provider integration
3. **Flexibility**: Easy provider switching without code changes
4. **Security**: Centralized, encrypted API key management
5. **Cost Control**: Token tracking, quotas, and usage analytics
6. **Future-Proof**: Easy to add new providers as they emerge

### Non-Goals
- Not a full-featured AI extension (that's T3AI)
- Not end-user facing (infrastructure for developers)
- Not replacing TYPO3's planned GenAI Toolbox (complementary)

---

## 2. Extension Architecture

### Directory Structure

```
ai_base/
├── Classes/
│   ├── Domain/
│   │   ├── Model/
│   │   │   ├── AiRequest.php
│   │   │   ├── AiResponse.php
│   │   │   ├── ProviderConfiguration.php
│   │   │   └── UsageRecord.php
│   │   └── Repository/
│   │       ├── AiHistoryRepository.php
│   │       ├── PromptRepository.php
│   │       └── UsageRepository.php
│   ├── Service/
│   │   ├── AiServiceManager.php           # Main facade
│   │   ├── Provider/
│   │   │   ├── ProviderInterface.php
│   │   │   ├── AbstractProvider.php
│   │   │   ├── ProviderFactory.php
│   │   │   ├── OpenAiProvider.php
│   │   │   ├── AnthropicProvider.php
│   │   │   ├── GeminiProvider.php
│   │   │   ├── DeepLProvider.php
│   │   │   ├── OpenRouterProvider.php
│   │   │   └── OllamaProvider.php
│   │   ├── Feature/
│   │   │   ├── AbstractFeature.php
│   │   │   ├── TranslationService.php
│   │   │   ├── ImageDescriptionService.php
│   │   │   ├── ContentGenerationService.php
│   │   │   └── EmbeddingService.php
│   │   ├── Cache/
│   │   │   └── AiResponseCache.php
│   │   └── RateLimiter/
│   │       └── RateLimiterService.php
│   ├── Event/
│   │   ├── BeforeAiRequestEvent.php
│   │   ├── AfterAiResponseEvent.php
│   │   ├── ProviderSelectedEvent.php
│   │   └── QuotaExceededEvent.php
│   ├── Backend/
│   │   └── Module/
│   │       └── AiControlPanelController.php
│   ├── Security/
│   │   ├── ApiKeyManager.php
│   │   └── AccessControl.php
│   └── Exception/
│       ├── ProviderException.php
│       ├── QuotaExceededException.php
│       └── ConfigurationException.php
├── Configuration/
│   ├── Services.yaml
│   ├── Backend/
│   │   └── Modules.php
│   └── TCA/
├── Resources/
│   ├── Private/
│   │   ├── Language/
│   │   └── Templates/
│   └── Public/
│       ├── JavaScript/
│       └── Icons/
├── ext_emconf.php
├── ext_tables.sql
├── ext_localconf.php
└── composer.json
```

---

## 3. Core Interfaces

### ProviderInterface

```php
<?php
namespace Netresearch\AiBase\Service\Provider;

interface ProviderInterface
{
    /**
     * Send a completion request
     */
    public function complete(
        string $prompt,
        array $options = []
    ): CompletionResponse;

    /**
     * Stream a completion response
     */
    public function stream(
        string $prompt,
        callable $callback,
        array $options = []
    ): void;

    /**
     * Generate embeddings for text
     */
    public function embed(
        string|array $text,
        array $options = []
    ): EmbeddingResponse;

    /**
     * Analyze an image (vision)
     */
    public function analyzeImage(
        string $imageUrl,
        string $prompt,
        array $options = []
    ): VisionResponse;

    /**
     * Translate text
     */
    public function translate(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        array $options = []
    ): TranslationResponse;

    /**
     * Get provider capabilities
     */
    public function getCapabilities(): array;

    /**
     * Estimate cost for a request
     */
    public function estimateCost(int $inputTokens, int $outputTokens): float;

    /**
     * Check if provider is available
     */
    public function isAvailable(): bool;
}
```

### AiServiceManager (Main Facade)

```php
<?php
namespace Netresearch\AiBase\Service;

class AiServiceManager
{
    public function execute(
        string $feature,
        array $params,
        array $options = []
    ): AiResponse;

    public function getProvider(?string $preferred = null): ProviderInterface;

    public function complete(string $prompt, array $options = []): AiResponse;

    public function translate(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage = null
    ): TranslationResponse;

    public function generateImageAltText(string $imageUrl): string;

    public function generateEmbedding(string $text): array;
}
```

---

## 4. Implementation Phases

### Phase 1: Foundation (Weeks 1-3)

**Goal**: Core infrastructure with OpenAI + Anthropic providers

**Deliverables**:
- [ ] Extension skeleton with composer.json
- [ ] ProviderInterface and AbstractProvider
- [ ] OpenAiProvider implementation
- [ ] AnthropicProvider implementation
- [ ] ProviderFactory with configuration
- [ ] AiServiceManager facade
- [ ] Basic error handling and exceptions
- [ ] Unit tests for providers

**Database**:
```sql
CREATE TABLE tx_aibase_apikeys (
    uid INT PRIMARY KEY AUTO_INCREMENT,
    provider VARCHAR(50) NOT NULL,
    scope VARCHAR(20) DEFAULT 'global',
    scope_id INT DEFAULT 0,
    api_key TEXT NOT NULL,
    is_active TINYINT DEFAULT 1,
    tstamp INT,
    crdate INT
);
```

---

### Phase 2: Security & Configuration (Weeks 4-5)

**Goal**: Secure API key management and configuration

**Deliverables**:
- [ ] ApiKeyManager with encryption
- [ ] AccessControl service
- [ ] ext_conf_template.txt configuration
- [ ] Per-site configuration support
- [ ] Environment variable support
- [ ] Backend module skeleton

**Configuration**:
```php
# ext_conf_template.txt
# cat=basic; type=options[openai,anthropic,gemini,deepl,ollama]; label=Default Provider
defaultProvider = openai

# cat=providers/openai; type=string; label=OpenAI API Key
providers.openai.apiKey =

# cat=providers/anthropic; type=string; label=Anthropic API Key
providers.anthropic.apiKey =

# cat=quotas; type=int+; label=Daily Request Limit per User
quotas.dailyLimit = 100
```

---

### Phase 3: Caching & Rate Limiting (Weeks 6-7)

**Goal**: Performance optimization and cost control

**Deliverables**:
- [ ] AiResponseCache with configurable TTL
- [ ] RateLimiterService (per-user, per-site)
- [ ] Token counting utility
- [ ] Cost calculation service
- [ ] Usage tracking database table
- [ ] Quota management

**Database**:
```sql
CREATE TABLE tx_aibase_usage (
    uid INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    provider VARCHAR(50) NOT NULL,
    feature VARCHAR(100) NOT NULL,
    prompt_tokens INT DEFAULT 0,
    completion_tokens INT DEFAULT 0,
    estimated_cost DECIMAL(10,6) DEFAULT 0,
    tstamp INT
);

CREATE TABLE tx_aibase_quotas (
    uid INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    quota_period VARCHAR(20) DEFAULT 'daily',
    requests_used INT DEFAULT 0,
    requests_limit INT DEFAULT 100,
    cost_used DECIMAL(10,6) DEFAULT 0,
    cost_limit DECIMAL(10,6) DEFAULT 10
);
```

---

### Phase 4: Event System (Week 8)

**Goal**: Extensibility via PSR-14 events

**Deliverables**:
- [ ] BeforeAiRequestEvent
- [ ] AfterAiResponseEvent
- [ ] ProviderSelectedEvent
- [ ] QuotaExceededEvent
- [ ] Event listener registration
- [ ] Documentation for event usage

**Example**:
```php
// Listen to AI requests
class ContentSanitizationListener
{
    public function __invoke(BeforeAiRequestEvent $event): void
    {
        // Sanitize content before sending to AI
        $prompt = $event->getPrompt();
        $sanitized = $this->sanitizer->sanitize($prompt);
        $event->setPrompt($sanitized);
    }
}
```

---

### Phase 5: Feature Services (Weeks 9-11)

**Goal**: High-level feature abstractions

**Deliverables**:
- [ ] TranslationService
- [ ] ImageDescriptionService
- [ ] ContentGenerationService
- [ ] EmbeddingService
- [ ] Prompt template management
- [ ] Response parsing utilities

**Prompt Templates Database**:
```sql
CREATE TABLE tx_aibase_prompts (
    uid INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    identifier VARCHAR(100) UNIQUE NOT NULL,
    feature VARCHAR(100) NOT NULL,
    system_prompt TEXT,
    user_prompt_template TEXT,
    provider VARCHAR(50),
    model VARCHAR(100),
    temperature DECIMAL(3,2) DEFAULT 0.7,
    max_tokens INT DEFAULT 1000,
    is_default TINYINT DEFAULT 0
);
```

---

### Phase 6: Additional Providers (Weeks 12-14)

**Goal**: Expand provider support

**Deliverables**:
- [ ] GeminiProvider (Google)
- [ ] DeepLProvider (specialized translation)
- [ ] OpenRouterProvider (multi-model gateway)
- [ ] OllamaProvider (local models)
- [ ] Provider capability matrix
- [ ] Automatic provider fallback

---

### Phase 7: Backend Module (Weeks 15-16)

**Goal**: Admin UI for configuration and monitoring

**Deliverables**:
- [ ] Dashboard with usage statistics
- [ ] Provider configuration UI
- [ ] API key management UI
- [ ] Prompt template editor
- [ ] Usage reports and charts
- [ ] Quota management UI

---

### Phase 8: Documentation & Polish (Weeks 17-18)

**Goal**: Production-ready release

**Deliverables**:
- [ ] API documentation
- [ ] Integration guide for extension developers
- [ ] Example implementations
- [ ] Performance optimization
- [ ] Security audit
- [ ] TYPO3 Extension Repository submission

---

## 5. Integration with Netresearch Extensions

### textdb Integration

```php
// In textdb extension
use Netresearch\AiBase\Service\AiServiceManager;

class TranslationSuggestionService
{
    public function __construct(
        private AiServiceManager $aiService
    ) {}

    public function suggestTranslation(
        string $sourceText,
        string $targetLanguage
    ): array {
        $response = $this->aiService->translate(
            $sourceText,
            $targetLanguage
        );

        return [
            'suggestion' => $response->getTranslation(),
            'confidence' => $response->getConfidence(),
            'alternatives' => $response->getAlternatives(),
        ];
    }
}
```

### rte-ckeditor-image Integration

```php
// In rte-ckeditor-image extension
use Netresearch\AiBase\Service\AiServiceManager;

class ImageAltTextService
{
    public function __construct(
        private AiServiceManager $aiService
    ) {}

    public function generateAltText(string $imageUrl): string
    {
        return $this->aiService->generateImageAltText($imageUrl);
    }
}
```

### contexts Integration

```php
// In contexts extension
use Netresearch\AiBase\Service\AiServiceManager;

class RuleGeneratorService
{
    public function __construct(
        private AiServiceManager $aiService
    ) {}

    public function generateRule(string $naturalLanguage): array
    {
        $response = $this->aiService->complete(
            prompt: $this->buildPrompt($naturalLanguage),
            options: ['response_format' => 'json']
        );

        return json_decode($response->getContent(), true);
    }
}
```

---

## 6. Supported Providers

| Provider | Chat | Vision | Embeddings | Translation | Local |
|----------|------|--------|------------|-------------|-------|
| OpenAI | ✅ | ✅ | ✅ | ✅ | ❌ |
| Anthropic | ✅ | ✅ | ❌ | ✅ | ❌ |
| Google Gemini | ✅ | ✅ | ✅ | ✅ | ❌ |
| DeepL | ❌ | ❌ | ❌ | ✅ | ❌ |
| OpenRouter | ✅ | ✅ | ✅ | ✅ | ❌ |
| Ollama | ✅ | ✅ | ✅ | ✅ | ✅ |

---

## 7. Success Metrics

| Metric | Target |
|--------|--------|
| Provider integration time | < 4 hours |
| API response time overhead | < 50ms |
| Cache hit rate | > 60% |
| Test coverage | > 80% |
| Documentation completeness | 100% |

---

## 8. Dependencies

### Required
- TYPO3 13.4+ / 14.x
- PHP 8.2+
- ext-json
- ext-openssl (for encryption)

### Suggested
- `guzzlehttp/guzzle` (HTTP client)
- `symfony/cache` (caching)

---

## 9. Risk Mitigation

| Risk | Mitigation |
|------|------------|
| Provider API changes | Abstract provider interface, version pinning |
| Cost overruns | Quota system, usage alerts |
| Security breaches | Encrypted key storage, audit logging |
| Performance issues | Caching, rate limiting, async processing |
| Vendor lock-in | Multiple provider support, abstraction layer |

---

## 10. Next Steps

1. **Immediate**: Create extension skeleton and ProviderInterface
2. **Week 1**: Implement OpenAI provider with basic tests
3. **Week 2**: Add Anthropic provider
4. **Week 3**: Implement AiServiceManager facade
5. **Week 4**: Security layer and configuration

---

## Document Index

| Document | Description |
|----------|-------------|
| `01-ai-base-architecture.md` | Detailed architecture design |
| `02-contexts-extension-analysis.md` | Contexts AI integration analysis |
| `03-netresearch-extensions-analysis.md` | All Netresearch extensions overview |
| `04-textdb-extension-analysis.md` | TextDB AI integration analysis |
| `05-rte-ckeditor-image-analysis.md` | RTE Image AI integration analysis |
| `06-typo3-ai-landscape.md` | TYPO3 v14 AI features and gap analysis |
