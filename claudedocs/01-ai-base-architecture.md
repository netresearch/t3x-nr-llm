# AI/LLM Base Extension Architecture for TYPO3

> Analysis Date: 2025-12-22
> Purpose: Comprehensive architectural design for TYPO3 AI integration layer

---

## 1. Architecture Patterns for AI Service Abstraction

### Provider Abstraction Layer

```
┌─────────────────────────────────────────────────────────┐
│                  CMS Application Layer                   │
│         (Content Modules, Backend, Frontend)             │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│              AI Service Facade/Manager                   │
│  - Request normalization                                 │
│  - Response formatting                                   │
│  - Feature routing (translation, generation, etc.)       │
│  - Caching layer                                         │
│  - Rate limiting & quota management                      │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│           Provider Abstraction Interface                 │
│  Common methods: complete(), stream(), embed(), etc.     │
└─┬────────┬────────┬────────┬────────┬────────┬──────────┘
  │        │        │        │        │        │
┌─▼──┐ ┌──▼─┐ ┌────▼──┐ ┌───▼───┐ ┌──▼──┐ ┌──▼────┐
│OpenAI│ │Anthropic│ │Gemini│ │OpenRouter│ │Ollama│ │Custom│
└────┘ └────┘ └──────┘ └───────┘ └─────┘ └───────┘
```

### Key Architectural Principles

1. **Strategy Pattern**: Interchangeable provider implementations
2. **Factory Pattern**: Dynamic provider instantiation based on configuration
3. **Adapter Pattern**: Normalize different API structures
4. **Decorator Pattern**: Add caching, logging, monitoring
5. **Circuit Breaker**: Graceful degradation on provider failures

---

## 2. LLM Provider Comparison Matrix

| Provider | Strengths | API Style | Cost Model | Best For |
|----------|-----------|-----------|------------|----------|
| **OpenAI** | Wide adoption, strong embeddings | REST | Token-based | General content, embeddings |
| **Anthropic** | Long context, safety | REST | Token-based | Content analysis, large docs |
| **Google Gemini** | Multimodal, competitive pricing | REST/gRPC | Token-based | Image+text tasks |
| **OpenRouter** | Multi-provider proxy | REST | Pay-per-use | Flexibility, fallback |
| **Ollama** | Local, no cost | REST | Free | Privacy, development |
| **Azure OpenAI** | Enterprise, SLA | REST | Token-based | Corporate, compliance |

### Unified Provider Interface

```php
interface LlmProviderInterface {
    public function complete(
        string $prompt,
        array $options = []
    ): CompletionResponse;

    public function stream(
        string $prompt,
        callable $callback,
        array $options = []
    ): void;

    public function embed(
        string|array $text
    ): EmbeddingResponse;

    public function getCapabilities(): array;
    public function estimateCost(array $tokens): float;
    public function checkAvailability(): bool;
}
```

---

## 3. TYPO3 Extension Structure

```
ai_base/
├── Classes/
│   ├── Domain/
│   │   ├── Model/
│   │   │   ├── AiRequest.php
│   │   │   ├── AiResponse.php
│   │   │   └── ProviderConfiguration.php
│   │   └── Repository/
│   │       └── AiHistoryRepository.php
│   ├── Service/
│   │   ├── AiServiceManager.php          # Main facade
│   │   ├── Provider/
│   │   │   ├── AbstractProvider.php
│   │   │   ├── OpenAiProvider.php
│   │   │   ├── AnthropicProvider.php
│   │   │   ├── GeminiProvider.php
│   │   │   └── ProviderFactory.php
│   │   ├── Feature/
│   │   │   ├── TranslationService.php
│   │   │   ├── ImageDescriptionService.php
│   │   │   ├── ContentEnhancementService.php
│   │   │   └── SeoOptimizationService.php
│   │   └── Cache/
│   │       └── AiResponseCache.php
│   ├── Middleware/
│   │   └── RateLimitMiddleware.php
│   ├── Backend/
│   │   ├── Module/
│   │   │   └── AiControlPanelController.php
│   │   └── FormDataProvider/
│   │       └── AiSuggestionsProvider.php
│   ├── EventListener/
│   │   └── AfterRecordSavedEventListener.php
│   ├── Configuration/
│   │   └── ConfigurationValidator.php
│   └── Security/
│       ├── ApiKeyManager.php
│       └── AccessControl.php
├── Configuration/
│   ├── Services.yaml                     # Dependency Injection
│   ├── TCA/                              # Backend forms
│   ├── Backend/
│   │   └── Modules.php                   # Backend module registration
│   └── TypoScript/
├── Resources/
│   ├── Private/
│   │   ├── Language/
│   │   └── Templates/
│   └── Public/
│       ├── JavaScript/
│       │   └── AiBackendModule.js
│       └── Icons/
└── ext_emconf.php
```

---

## 4. Dependency Injection Configuration

```yaml
# Configuration/Services.yaml
services:
  _defaults:
    autowire: true
    autoconfigure: true
    public: false

  Netresearch\AiBase\:
    resource: '../Classes/*'

  # Main AI Service Manager
  Netresearch\AiBase\Service\AiServiceManager:
    public: true
    arguments:
      $providerFactory: '@Netresearch\AiBase\Service\Provider\ProviderFactory'
      $cache: '@cache.ai_responses'
      $logger: '@logger'

  # Provider Factory
  Netresearch\AiBase\Service\Provider\ProviderFactory:
    public: true
    arguments:
      $providers: !tagged_iterator ai.provider

  # Individual Providers
  Netresearch\AiBase\Service\Provider\OpenAiProvider:
    tags:
      - { name: 'ai.provider', identifier: 'openai' }

  Netresearch\AiBase\Service\Provider\AnthropicProvider:
    tags:
      - { name: 'ai.provider', identifier: 'anthropic' }

  # Feature Services
  Netresearch\AiBase\Service\Feature\TranslationService:
    public: true
    arguments:
      $aiService: '@Netresearch\AiBase\Service\AiServiceManager'

  # Cache
  cache.ai_responses:
    class: TYPO3\CMS\Core\Cache\Frontend\VariableFrontend
    factory: ['@TYPO3\CMS\Core\Cache\CacheManager', 'getCache']
    arguments: ['ai_responses']
```

---

## 5. Editor/User-Facing Features Priority

| Feature | Business Value | Technical Complexity | AI Model Req | Priority |
|---------|---------------|---------------------|--------------|----------|
| **Content Translation** | High | Medium | GPT-4, Claude | **P0** |
| **Image Alt Text** | High | Low | GPT-4V, Gemini Vision | **P0** |
| **SEO Meta Generation** | High | Low | GPT-3.5, Claude Haiku | **P0** |
| **Content Suggestions** | Medium | Medium | GPT-4, Claude | **P1** |
| **Auto Categorization** | Medium | Low | Embeddings | **P1** |
| **Content Enhancement** | Medium | High | GPT-4 | **P2** |
| **Form/Rule Generation** | Low | High | GPT-4 + Validation | **P3** |

---

## 6. Security Architecture

### Multi-Level API Key Storage

```
┌─────────────────────────────────────────────────────┐
│          Multi-Level API Key Storage                 │
├─────────────────────────────────────────────────────┤
│                                                       │
│  Level 1: Global Configuration (Admin Only)          │
│  ├─ System-wide fallback keys                        │
│  ├─ Encrypted in database/env                        │
│  └─ Access: Admin users only                         │
│                                                       │
│  Level 2: Site Configuration (Site Admins)           │
│  ├─ Per-site provider preferences                    │
│  ├─ Cost allocation per site                         │
│  └─ Access: Site administrators                      │
│                                                       │
│  Level 3: User Groups (Optional)                     │
│  ├─ Department/team specific keys                    │
│  ├─ Budget tracking per group                        │
│  └─ Access: Group managers                           │
│                                                       │
└─────────────────────────────────────────────────────┘
```

### Security Best Practices

1. **Encryption at Rest**: Use TYPO3's encryption API, never store in plain text
2. **Access Control**: Role-based permissions (RBAC)
3. **Rate Limiting**: Per-user, per-site, and global quotas
4. **Data Privacy**: Content sanitization, local model option, GDPR compliance
5. **API Key Rotation**: Support rotation without downtime

---

## 7. Database Schema

```sql
-- AI request history and audit log
CREATE TABLE tx_aibase_history (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,

    user_id int(11) DEFAULT '0' NOT NULL,
    feature varchar(100) DEFAULT '' NOT NULL,
    provider varchar(50) DEFAULT '' NOT NULL,
    model varchar(100) DEFAULT '' NOT NULL,

    prompt_tokens int(11) DEFAULT '0' NOT NULL,
    completion_tokens int(11) DEFAULT '0' NOT NULL,
    total_tokens int(11) DEFAULT '0' NOT NULL,

    estimated_cost decimal(10,6) DEFAULT '0.000000' NOT NULL,

    request_data text,
    response_data text,

    status varchar(20) DEFAULT 'success' NOT NULL,
    error_message text,

    processing_time int(11) DEFAULT '0' NOT NULL,

    tstamp int(11) DEFAULT '0' NOT NULL,
    crdate int(11) DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY user_feature (user_id, feature),
    KEY provider (provider)
);

-- API key storage (encrypted)
CREATE TABLE tx_aibase_apikeys (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,

    provider varchar(50) DEFAULT '' NOT NULL,
    scope varchar(20) DEFAULT 'global' NOT NULL,
    scope_id int(11) DEFAULT '0' NOT NULL,

    api_key text NOT NULL,
    encryption_key varchar(255) DEFAULT '' NOT NULL,

    is_active tinyint(1) DEFAULT '1' NOT NULL,

    tstamp int(11) DEFAULT '0' NOT NULL,
    crdate int(11) DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY provider_scope (provider, scope, scope_id)
);

-- Usage quotas
CREATE TABLE tx_aibase_quotas (
    uid int(11) NOT NULL auto_increment,

    user_id int(11) DEFAULT '0' NOT NULL,
    quota_period varchar(20) DEFAULT 'daily' NOT NULL,
    period_start int(11) DEFAULT '0' NOT NULL,

    requests_used int(11) DEFAULT '0' NOT NULL,
    requests_limit int(11) DEFAULT '0' NOT NULL,

    cost_used decimal(10,6) DEFAULT '0.000000' NOT NULL,
    cost_limit decimal(10,6) DEFAULT '0.000000' NOT NULL,

    PRIMARY KEY (uid),
    KEY user_period (user_id, quota_period, period_start)
);

-- System prompts and templates
CREATE TABLE tx_aibase_prompts (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,

    title varchar(255) DEFAULT '' NOT NULL,
    identifier varchar(100) DEFAULT '' NOT NULL,
    feature varchar(100) DEFAULT '' NOT NULL,

    system_prompt text,
    user_prompt_template text,

    provider varchar(50) DEFAULT '' NOT NULL,
    model varchar(100) DEFAULT '' NOT NULL,
    temperature decimal(3,2) DEFAULT '0.70' NOT NULL,
    max_tokens int(11) DEFAULT '1000' NOT NULL,

    is_default tinyint(1) DEFAULT '0' NOT NULL,

    tstamp int(11) DEFAULT '0' NOT NULL,
    crdate int(11) DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY identifier (identifier),
    KEY feature (feature)
);
```

---

## 8. Core Service Implementation

### AiServiceManager (Main Facade)

```php
<?php
namespace Netresearch\AiBase\Service;

class AiServiceManager
{
    private ProviderFactory $providerFactory;
    private CacheFrontend $cache;
    private RateLimiter $rateLimiter;
    private AccessControl $accessControl;

    public function execute(
        string $feature,
        array $params,
        array $options = []
    ): AiResponse {
        // 1. Access control check
        $this->accessControl->assertCanUseFeature($feature);

        // 2. Load provider (with fallback chain)
        $provider = $this->getProvider($options['provider'] ?? null);

        // 3. Check cache
        $cacheKey = $this->buildCacheKey($feature, $params);
        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }

        // 4. Rate limit check
        $this->rateLimiter->assertNotExceeded();

        // 5. Execute request
        $response = $provider->complete($params['prompt'], $params);

        // 6. Cache response
        $this->cache->set($cacheKey, $response, $options['cache_ttl'] ?? 3600);

        // 7. Log usage
        $this->logUsage($feature, $provider, $response);

        return $response;
    }

    public function getProvider(?string $preferred = null): LlmProviderInterface
    {
        return $this->providerFactory->create($preferred);
    }
}
```

### Abstract Feature Service

```php
<?php
namespace Netresearch\AiBase\Service\Feature;

abstract class AbstractAiFeature
{
    protected AiServiceManager $aiService;
    protected string $featureIdentifier;

    abstract protected function buildPrompt(array $params): string;
    abstract protected function parseResponse(string $response): mixed;
    abstract protected function validateInput(array $params): void;

    public function execute(array $params): mixed
    {
        $this->validateInput($params);

        $prompt = $this->buildPrompt($params);

        $response = $this->aiService->execute(
            feature: $this->featureIdentifier,
            params: [
                'prompt' => $prompt,
                'temperature' => $this->getTemperature(),
                'max_tokens' => $this->getMaxTokens(),
            ],
            options: [
                'cache_ttl' => $this->getCacheTtl(),
            ]
        );

        return $this->parseResponse($response->getContent());
    }
}
```

---

## 9. Backend Module Registration

```php
<?php
// Configuration/Backend/Modules.php
return [
    'ai_control_panel' => [
        'parent' => 'tools',
        'position' => ['after' => 'extensionmanager'],
        'access' => 'admin',
        'workspaces' => 'live',
        'path' => '/module/tools/ai',
        'labels' => 'LLL:EXT:ai_base/Resources/Private/Language/locallang_mod.xlf',
        'extensionName' => 'AiBase',
        'controllerActions' => [
            \Netresearch\AiBase\Backend\Module\AiControlPanelController::class => [
                'overview',
                'providers',
                'prompts',
                'usage',
                'settings',
            ],
        ],
    ],
];
```

---

## 10. Extension Configuration

```php
# ext_conf_template.txt

# cat=basic; type=string; label=Default AI Provider
defaultProvider = openai

# cat=providers/openai; type=string; label=OpenAI API Key (encrypted)
providers.openai.apiKey =

# cat=providers/openai; type=options[gpt-4=gpt-4,gpt-4-turbo=gpt-4-turbo,gpt-3.5-turbo=gpt-3.5-turbo]; label=Default Model
providers.openai.model = gpt-4-turbo

# cat=providers/anthropic; type=string; label=Anthropic API Key (encrypted)
providers.anthropic.apiKey =

# cat=providers/anthropic; type=options[claude-3-opus=claude-3-opus-20240229,claude-3-sonnet=claude-3-sonnet-20240229,claude-3-haiku=claude-3-haiku-20240307]; label=Default Model
providers.anthropic.model = claude-3-sonnet-20240229

# cat=providers/gemini; type=string; label=Google Gemini API Key
providers.gemini.apiKey =

# cat=providers/openrouter; type=string; label=OpenRouter API Key
providers.openrouter.apiKey =

# cat=providers/ollama; type=string; label=Ollama Base URL
providers.ollama.baseUrl = http://localhost:11434

# cat=features; type=boolean; label=Enable Content Translation
features.translation = 1

# cat=features; type=boolean; label=Enable Image Description
features.imageDescription = 1

# cat=features; type=boolean; label=Enable SEO Generation
features.seoGeneration = 1

# cat=quotas; type=int+; label=Daily Request Limit per User
quotas.dailyLimit = 100

# cat=quotas; type=float; label=Daily Cost Limit (USD)
quotas.costLimit = 10.00

# cat=cache; type=int+; label=Default Cache TTL (seconds)
cache.defaultTtl = 3600
```

---

## 11. Anti-Patterns to Avoid

- Hard-coding provider APIs in feature code
- Storing unencrypted API keys
- No rate limiting or cost controls
- Blocking UI during AI requests
- No fallback when providers fail
- Sending sensitive data without user consent
- No audit trail for compliance

---

## 12. Implementation Phases

### Phase 1: Foundation
- Core provider abstraction layer
- API key management and encryption
- Basic OpenAI + Anthropic integration
- Rate limiting and quota system
- Backend module skeleton

### Phase 2: Essential Features
- Content translation service
- Image alt text generation
- SEO meta generation
- Basic caching layer
- Usage tracking and reporting

### Phase 3: Advanced Features
- Content enhancement service
- Auto-categorization with embeddings
- Batch processing jobs
- Additional providers (Gemini, OpenRouter, Ollama)
- User preference management

### Phase 4: Polish & Scale
- Performance optimization
- Advanced caching strategies
- Cost optimization algorithms
- Documentation and examples
