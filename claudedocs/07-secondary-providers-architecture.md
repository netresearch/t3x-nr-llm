# Secondary Providers Architecture: Gemini, DeepL, OpenRouter

> Analysis Date: 2025-12-22
> Purpose: Architectural design for MVP 0.5 secondary provider implementations
> Status: Planning Phase

---

## 1. Provider Capability Matrix

| Capability | OpenAI | Anthropic | Gemini | DeepL | OpenRouter | Ollama |
|------------|--------|-----------|---------|-------|------------|--------|
| Chat Completion | ✅ | ✅ | ✅ | ❌ | ✅ | ✅ |
| Streaming | ✅ | ✅ | ✅ | ❌ | ✅ | ✅ |
| Vision | ✅ | ✅ | ✅ | ❌ | ✅ | ✅ |
| Embeddings | ✅ | ❌ | ✅ | ❌ | ✅ | ✅ |
| Translation | via prompt | via prompt | via prompt | ✅ | via prompt | via prompt |
| JSON Mode | ✅ | ✅ | ✅ | ❌ | ✅ | ✅ |
| Function Calling | ✅ | ✅ | ✅ | ❌ | ✅ | ✅ |
| Safety Filters | ✅ | ✅ | ✅ | ❌ | varies | ❌ |
| Local Execution | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |

---

## 2. GeminiProvider Architecture

### API Overview
- **API Name**: Google Generative Language API
- **Base URL**: `https://generativelanguage.googleapis.com/v1beta`
- **Authentication**: API Key (query parameter) or OAuth 2.0
- **Protocol**: REST with JSON
- **Documentation**: https://ai.google.dev/docs

### Unique Features

#### 1. Native Multimodal Support
```
Gemini handles text + images in single request without special encoding:
- Inline images (base64 or URL)
- Multiple images per request
- Image + text reasoning
```

#### 2. Safety Settings
```php
[
    'HARM_CATEGORY_HARASSMENT' => 'BLOCK_MEDIUM_AND_ABOVE',
    'HARM_CATEGORY_HATE_SPEECH' => 'BLOCK_MEDIUM_AND_ABOVE',
    'HARM_CATEGORY_SEXUALLY_EXPLICIT' => 'BLOCK_MEDIUM_AND_ABOVE',
    'HARM_CATEGORY_DANGEROUS_CONTENT' => 'BLOCK_MEDIUM_AND_ABOVE',
]
```

#### 3. Model Variants
- **gemini-2.0-flash-exp**: Latest experimental (fastest)
- **gemini-1.5-pro**: Long context (2M tokens)
- **gemini-1.5-flash**: Fast and efficient
- **gemini-pro-vision**: Vision tasks (deprecated, use 1.5)

### Request Structure

```json
{
  "contents": [
    {
      "role": "user",
      "parts": [
        {"text": "Describe this image"},
        {
          "inline_data": {
            "mime_type": "image/jpeg",
            "data": "base64_encoded_image"
          }
        }
      ]
    }
  ],
  "generationConfig": {
    "temperature": 0.7,
    "topK": 40,
    "topP": 0.95,
    "maxOutputTokens": 1024,
    "stopSequences": []
  },
  "safetySettings": [
    {
      "category": "HARM_CATEGORY_HARASSMENT",
      "threshold": "BLOCK_MEDIUM_AND_ABOVE"
    }
  ]
}
```

### Response Structure

```json
{
  "candidates": [
    {
      "content": {
        "parts": [{"text": "Response text here"}],
        "role": "model"
      },
      "finishReason": "STOP",
      "safetyRatings": [
        {
          "category": "HARM_CATEGORY_HARASSMENT",
          "probability": "NEGLIGIBLE"
        }
      ]
    }
  ],
  "usageMetadata": {
    "promptTokenCount": 150,
    "candidatesTokenCount": 200,
    "totalTokenCount": 350
  }
}
```

### Embeddings API

```
Endpoint: v1beta/models/text-embedding-004:embedContent
Model: text-embedding-004
Max Input: 2048 tokens
Output Dimension: 768
```

### Cost Structure (as of Dec 2024)

| Model | Input (per 1M tokens) | Output (per 1M tokens) |
|-------|----------------------|------------------------|
| Gemini 2.0 Flash | Free (rate limited) | Free (rate limited) |
| Gemini 1.5 Pro | $1.25 (≤128K), $2.50 (>128K) | $5.00 (≤128K), $10.00 (>128K) |
| Gemini 1.5 Flash | $0.075 (≤128K), $0.15 (>128K) | $0.30 (≤128K), $0.60 (>128K) |
| Embeddings | $0.00001/1K tokens | N/A |

### Implementation Strategy

```
1. API Key Management
   - Support both query param and OAuth
   - Environment variable: GEMINI_API_KEY
   - Region selection (US, EU)

2. Content Transformation
   - Convert standard prompt to Gemini "contents" format
   - Handle multimodal content (text + images)
   - Map roles: user/assistant/system

3. Safety Settings
   - Default: BLOCK_MEDIUM_AND_ABOVE for all categories
   - Configurable per request
   - Handle SAFETY finish reasons

4. Response Parsing
   - Extract text from candidates[0].content.parts
   - Parse safety ratings
   - Handle multiple candidates

5. Error Handling
   - Rate limits: 429 with Retry-After
   - Safety blocks: Return specific error
   - Invalid API key: 403
```

---

## 3. DeepLProvider Architecture

### API Overview
- **API Name**: DeepL Translation API
- **Base URL**:
  - Free: `https://api-free.deepl.com/v2`
  - Pro: `https://api.deepl.com/v2`
- **Authentication**: DeepL-Auth-Key header
- **Protocol**: REST with JSON
- **Documentation**: https://www.deepl.com/docs-api

### Critical Design Decision: Translation-Only Provider

```
DeepL is fundamentally different from LLM providers:
- NO chat completion
- NO embeddings
- NO vision
- ONLY translation

Implementation approach:
1. Implement ProviderInterface
2. Only translate() method works
3. All other methods throw NotSupportedException
4. Clear capability declaration
```

### Supported Languages

```php
const SUPPORTED_LANGUAGES = [
    // Source + Target
    'EN' => 'English',
    'DE' => 'German',
    'FR' => 'French',
    'ES' => 'Spanish',
    'IT' => 'Italian',
    'NL' => 'Dutch',
    'PL' => 'Polish',
    'PT' => 'Portuguese',
    'RU' => 'Russian',
    'JA' => 'Japanese',
    'ZH' => 'Chinese',

    // Target only variants
    'EN-US' => 'English (American)',
    'EN-GB' => 'English (British)',
    'PT-PT' => 'Portuguese (Portugal)',
    'PT-BR' => 'Portuguese (Brazil)',
];
```

### Translation Request

```json
{
  "text": ["Text to translate", "Another text"],
  "target_lang": "DE",
  "source_lang": "EN",
  "formality": "default",
  "preserve_formatting": true,
  "tag_handling": "html",
  "split_sentences": "1",
  "glossary_id": "optional-glossary-uuid"
}
```

### Unique Features

#### 1. Formality Control
```php
'formality' => 'default' | 'more' | 'less' | 'prefer_more' | 'prefer_less'
```

#### 2. Glossary Support
```
User-defined terminology dictionaries:
- Create glossary for domain-specific terms
- Ensure consistent translation of technical terms
- Per-language pair
```

#### 3. HTML/XML Handling
```php
'tag_handling' => 'html' | 'xml'
```

#### 4. Document Translation
```
Endpoint: /v2/document
Supports: PDF, DOCX, PPTX, HTML, TXT
Process: Upload → Poll status → Download
```

### Response Structure

```json
{
  "translations": [
    {
      "detected_source_language": "EN",
      "text": "Übersetzter Text"
    }
  ]
}
```

### Cost Structure

| Plan | Characters/month | Price | Extra characters |
|------|------------------|-------|------------------|
| Free | 500,000 | $0 | Not available |
| Starter | Unlimited | $6.49/month | $25 per 1M chars |
| Advanced | Unlimited | $32.49/month | $25 per 1M chars |
| Ultimate | Unlimited | $64.99/month | $25 per 1M chars |

### Usage Monitoring

```
Endpoint: /v2/usage
Response:
{
  "character_count": 12345,
  "character_limit": 500000
}
```

### Implementation Strategy

```
1. Capability Declaration
   - Only 'translation' in getCapabilities()
   - All other methods throw NotSupportedException

2. Translation Method
   - Support single and batch translation
   - Auto-detect source language if not provided
   - Handle formality and glossary options
   - Preserve HTML tags

3. Integration Point
   - TranslationService should check provider capabilities
   - Fall back to LLM-based translation if DeepL unavailable
   - Prefer DeepL for pure translation tasks (higher quality)

4. Error Handling
   - 456: Quota exceeded
   - 403: Invalid API key
   - 400: Unsupported language pair

5. Configuration
   - API key storage (separate from LLM keys)
   - Default formality setting
   - Free vs Pro endpoint selection
   - Glossary management UI
```

---

## 4. OpenRouterProvider Architecture

### API Overview
- **API Name**: OpenRouter API
- **Base URL**: `https://openrouter.ai/api/v1`
- **Authentication**: Bearer token
- **Protocol**: OpenAI-compatible REST API
- **Documentation**: https://openrouter.ai/docs

### Unique Value Proposition

```
OpenRouter is a gateway to 100+ models from multiple providers:
- Anthropic (Claude)
- OpenAI (GPT-4, etc.)
- Google (Gemini/PaLM)
- Meta (Llama)
- Mistral AI
- Cohere
- And many open-source models

Benefits:
1. Single API key for all providers
2. Automatic fallback to alternative models
3. Competitive pricing (often cheaper than direct)
4. Model routing based on cost/performance
5. Credits system (no need for individual API keys)
```

### Request Structure (OpenAI-compatible)

```json
{
  "model": "anthropic/claude-3-opus",
  "messages": [
    {
      "role": "user",
      "content": "Hello!"
    }
  ],
  "temperature": 0.7,
  "max_tokens": 1000,

  // OpenRouter-specific headers
  "transforms": ["middle-out"],
  "route": "fallback"
}
```

### OpenRouter-Specific Headers

```http
Authorization: Bearer sk-or-v1-xxx
HTTP-Referer: https://yoursite.com
X-Title: Your App Name
```

### Model Selection

```php
// Available models (examples)
const MODELS = [
    // Anthropic
    'anthropic/claude-3-opus' => ['context': 200000, 'cost_per_1k': 0.015],
    'anthropic/claude-3-sonnet' => ['context': 200000, 'cost_per_1k': 0.003],
    'anthropic/claude-3-haiku' => ['context': 200000, 'cost_per_1k': 0.00025],

    // OpenAI
    'openai/gpt-4-turbo' => ['context': 128000, 'cost_per_1k': 0.01],
    'openai/gpt-3.5-turbo' => ['context': 16384, 'cost_per_1k': 0.0005],

    // Google
    'google/gemini-pro' => ['context': 32000, 'cost_per_1k': 0.000125],
    'google/gemini-pro-vision' => ['context': 32000, 'cost_per_1k': 0.000125],

    // Meta
    'meta-llama/llama-3-70b' => ['context': 8192, 'cost_per_1k': 0.00059],

    // Open-source
    'mistralai/mixtral-8x7b' => ['context': 32768, 'cost_per_1k': 0.00024],
];
```

### Fallback Mechanism

```json
{
  "route": "fallback",
  "models": [
    "anthropic/claude-3-opus",
    "anthropic/claude-3-sonnet",
    "openai/gpt-4-turbo"
  ]
}
```

### Response Structure (OpenAI-compatible + extras)

```json
{
  "id": "gen-xxx",
  "model": "anthropic/claude-3-opus",
  "object": "chat.completion",
  "created": 1234567890,
  "choices": [
    {
      "message": {
        "role": "assistant",
        "content": "Response text"
      },
      "finish_reason": "stop"
    }
  ],
  "usage": {
    "prompt_tokens": 10,
    "completion_tokens": 20,
    "total_tokens": 30
  },

  // OpenRouter extras
  "provider": "Anthropic",
  "native_tokens_prompt": 10,
  "native_tokens_completion": 20,
  "total_cost": 0.0003
}
```

### Cost Tracking

```
OpenRouter provides actual cost in response:
- total_cost: Exact cost in USD
- No estimation needed
- Per-request granular tracking
```

### Credit System

```
Users can:
1. Purchase credits via OpenRouter dashboard
2. Set budget limits per API key
3. Monitor usage in real-time
4. Auto-reload credits
```

### Implementation Strategy

```
1. Model Selection Strategy
   - Configuration: Default model preference
   - Fallback chain: Primary → Secondary → Tertiary
   - Cost-based routing: Choose cheapest that meets requirements
   - Performance-based: Choose fastest for interactive use

2. Header Management
   - Required: Authorization (Bearer token)
   - Recommended: HTTP-Referer, X-Title (for analytics)
   - Optional: X-API-Key-Name (for tracking multiple keys)

3. Cost Optimization
   - Use response.total_cost (no estimation)
   - Track per-model usage
   - Automatic model downgrade on quota limits
   - Budget alerts

4. Fallback Logic
   - Automatic: Use "route": "fallback" for resilience
   - Manual: Specify model array in order of preference
   - Provider-specific: Fall back to different providers

5. Model Capability Detection
   - Fetch model list: GET /api/v1/models
   - Cache capabilities (vision, function calling, etc.)
   - Runtime selection based on feature requirements

6. Error Handling
   - 402: Insufficient credits
   - 429: Rate limit (per model)
   - 503: Model unavailable (fallback triggers)

7. Integration Patterns
   - Transparent fallback: If OpenAI configured but down, auto-route to OpenRouter
   - Cost monitoring: Track OpenRouter spend vs direct provider
   - A/B testing: Route traffic to compare model performance
```

---

## 5. Provider Selection Logic

### Feature-to-Provider Mapping

```php
class ProviderSelectionStrategy
{
    public function selectProvider(string $feature, array $options = []): string
    {
        return match($feature) {
            // Translation: Prefer DeepL for quality
            'translation' => $this->preferDeepL()
                ?? $this->fallbackToLLM(),

            // Vision: Gemini for multimodal, GPT-4V for accuracy
            'vision' => $options['multimodal']
                ? 'gemini'
                : 'openai',

            // Embeddings: OpenAI or Gemini
            'embeddings' => $options['dimension'] > 768
                ? 'openai'  // 1536 dimensions
                : 'gemini', // 768 dimensions

            // Chat: Route based on cost/performance
            'chat' => $this->routeChat($options),

            // Default
            default => $this->getDefaultProvider(),
        };
    }

    private function routeChat(array $options): string
    {
        // Long context needs
        if (($options['context_length'] ?? 0) > 100000) {
            return 'anthropic'; // Claude 200K context
        }

        // Cost-sensitive
        if ($options['cost_priority'] === 'low') {
            return 'openrouter'; // Route to cheapest
        }

        // Multimodal
        if (!empty($options['images'])) {
            return 'gemini'; // Best multimodal
        }

        // Default: OpenAI
        return 'openai';
    }
}
```

---

## 6. Configuration Architecture

### Extension Configuration

```php
# ext_conf_template.txt

# Gemini Configuration
# cat=providers/gemini; type=string; label=Google Gemini API Key
providers.gemini.apiKey =

# cat=providers/gemini; type=options[gemini-2.0-flash-exp=2.0 Flash,gemini-1.5-pro=1.5 Pro,gemini-1.5-flash=1.5 Flash]; label=Default Model
providers.gemini.model = gemini-1.5-flash

# cat=providers/gemini; type=options[US=United States,EU=Europe]; label=API Region
providers.gemini.region = US

# cat=providers/gemini; type=options[BLOCK_NONE=None,BLOCK_LOW_AND_ABOVE=Low+,BLOCK_MEDIUM_AND_ABOVE=Medium+,BLOCK_ONLY_HIGH=High Only]; label=Default Safety Level
providers.gemini.safetyLevel = BLOCK_MEDIUM_AND_ABOVE

# DeepL Configuration
# cat=providers/deepl; type=string; label=DeepL API Key
providers.deepl.apiKey =

# cat=providers/deepl; type=options[free=Free API,pro=Pro API]; label=API Tier
providers.deepl.tier = free

# cat=providers/deepl; type=options[default=Default,more=More Formal,less=Less Formal]; label=Default Formality
providers.deepl.formality = default

# cat=providers/deepl; type=boolean; label=Preserve HTML Formatting
providers.deepl.preserveHtml = 1

# OpenRouter Configuration
# cat=providers/openrouter; type=string; label=OpenRouter API Key
providers.openrouter.apiKey =

# cat=providers/openrouter; type=string; label=Default Model (e.g., anthropic/claude-3-opus)
providers.openrouter.model = anthropic/claude-3-sonnet

# cat=providers/openrouter; type=boolean; label=Enable Automatic Fallback
providers.openrouter.autoFallback = 1

# cat=providers/openrouter; type=string; label=Fallback Models (comma-separated)
providers.openrouter.fallbackModels = anthropic/claude-3-haiku,openai/gpt-3.5-turbo

# cat=providers/openrouter; type=float; label=Monthly Budget Limit (USD)
providers.openrouter.budgetLimit = 100.00
```

### Runtime Configuration

```yaml
# config/sites/main/ai_providers.yaml
providers:
  gemini:
    enabled: true
    safety_settings:
      harassment: BLOCK_MEDIUM_AND_ABOVE
      hate_speech: BLOCK_MEDIUM_AND_ABOVE
      sexual: BLOCK_MEDIUM_AND_ABOVE
      dangerous: BLOCK_MEDIUM_AND_ABOVE

  deepl:
    enabled: true
    glossaries:
      technical_terms: 'uuid-xxx-xxx'
      brand_terms: 'uuid-yyy-yyy'

  openrouter:
    enabled: true
    routing_strategy: 'cost_optimized' # or 'performance', 'balanced'
    model_preferences:
      chat: 'anthropic/claude-3-sonnet'
      vision: 'google/gemini-pro-vision'
      long_context: 'anthropic/claude-3-opus'
```

---

## 7. Testing Strategy

### Unit Tests

```php
// Tests/Unit/Provider/GeminiProviderTest.php
- testCompleteRequest()
- testMultimodalRequest()
- testEmbeddings()
- testSafetyFiltering()
- testErrorHandling()

// Tests/Unit/Provider/DeepLProviderTest.php
- testTranslation()
- testBatchTranslation()
- testFormality()
- testHtmlPreservation()
- testUnsupportedMethodsThrowException()

// Tests/Unit/Provider/OpenRouterProviderTest.php
- testModelSelection()
- testFallbackChain()
- testCostTracking()
- testMultiProviderRouting()
```

### Integration Tests

```php
// Tests/Integration/ProviderFallbackTest.php
- testDeepLToOpenAIFallback()
- testOpenRouterModelFallback()
- testProviderDowntimeHandling()

// Tests/Integration/CostOptimizationTest.php
- testCheapestModelSelection()
- testBudgetEnforcement()
```

---

## 8. Migration Path from Primary Providers

### Backward Compatibility

```php
// Existing code continues to work
$response = $aiService->translate($text, 'de');

// But now intelligently routes to DeepL if available
// Falls back to OpenAI/Anthropic if DeepL fails
```

### Feature Flag Rollout

```yaml
features:
  use_deepl_translation: true  # Use DeepL instead of LLM
  use_gemini_vision: false     # Gradual rollout
  use_openrouter_fallback: true # Safety net
```

---

## 9. Documentation Requirements

### Provider-Specific Docs

1. **GeminiProvider.md**
   - Setup guide
   - Safety settings configuration
   - Multimodal usage examples
   - Cost optimization tips

2. **DeepLProvider.md**
   - When to use vs LLM translation
   - Glossary management
   - Formality guidelines
   - HTML/XML handling

3. **OpenRouterProvider.md**
   - Model selection guide
   - Fallback configuration
   - Cost tracking setup
   - Multi-provider strategy

---

## 10. Success Criteria

| Criterion | Target | Measurement |
|-----------|--------|-------------|
| Provider integration time | < 4 hours | Developer survey |
| Translation quality (DeepL) | > LLM baseline | BLEU score |
| Cost reduction (OpenRouter) | 30-50% vs direct | Usage analytics |
| Fallback reliability | 99.9% uptime | Error rate monitoring |
| API compatibility | 100% with ProviderInterface | Unit test coverage |

---

## Next Steps

1. Implement GeminiProvider.php
2. Implement DeepLProvider.php with capability constraints
3. Implement OpenRouterProvider.php with model routing
4. Add provider-specific configuration
5. Create comprehensive tests
6. Document integration patterns
7. Validate cost optimization
