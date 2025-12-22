# Provider Integration Guide: Gemini, DeepL, OpenRouter

> Created: 2025-12-22
> Purpose: Developer guide for integrating and using secondary providers
> Audience: Extension developers, system administrators

---

## Table of Contents

1. [Quick Start](#quick-start)
2. [Provider Selection Strategy](#provider-selection-strategy)
3. [Gemini Integration](#gemini-integration)
4. [DeepL Integration](#deepl-integration)
5. [OpenRouter Integration](#openrouter-integration)
6. [Fallback Chains](#fallback-chains)
7. [Cost Optimization](#cost-optimization)
8. [Error Handling](#error-handling)
9. [Testing](#testing)

---

## Quick Start

### Installation

```bash
# Install AI Base extension
composer require netresearch/ai_base

# Set environment variables
export GEMINI_API_KEY="your-gemini-key"
export DEEPL_API_KEY="your-deepl-key"
export OPENROUTER_API_KEY="sk-or-v1-your-key"
```

### Basic Usage

```php
use Netresearch\AiBase\Service\AiServiceManager;

// Inject AiServiceManager
public function __construct(
    private AiServiceManager $aiService
) {}

// Translation (automatically uses DeepL if available)
$translation = $this->aiService->translate(
    'Hello world',
    'de'
);

// Vision (automatically uses Gemini for multimodal)
$description = $this->aiService->generateImageAltText(
    'https://example.com/image.jpg'
);

// Chat (routes to best available provider)
$response = $this->aiService->complete(
    'Explain TYPO3 content elements'
);
```

---

## Provider Selection Strategy

### Decision Matrix

```
Task Type → Provider Selection:

Translation:
├─ Pure translation → DeepL (best quality)
├─ Creative/context-aware → Gemini/OpenAI
└─ Cost-sensitive → OpenRouter

Vision/Multimodal:
├─ Text + Images → Gemini (native multimodal)
├─ Image analysis → GPT-4V via OpenRouter
└─ Multiple images → Gemini (better multimodal)

Embeddings:
├─ Standard (768d) → Gemini
├─ High-dimensional (1536d) → OpenAI via OpenRouter
└─ Cost-sensitive → Gemini (cheaper)

Chat Completion:
├─ Long context (>100K) → Anthropic via OpenRouter
├─ Multimodal → Gemini
├─ Cost-optimized → OpenRouter (auto-routing)
└─ Standard → OpenAI/Anthropic (primary providers)
```

### Automatic Routing

The AiServiceManager automatically selects the best provider based on:

1. **Feature Requirements**: Vision → Gemini, Translation → DeepL
2. **Availability**: Falls back if primary unavailable
3. **Quota**: Switches provider if quota exceeded
4. **Cost**: Routes to cheaper option when configured

---

## Gemini Integration

### Configuration

```php
# ext_conf_template.txt
providers.gemini.apiKey = your-api-key
providers.gemini.model = gemini-1.5-flash
providers.gemini.safetyLevel = BLOCK_MEDIUM_AND_ABOVE
```

### Basic Usage

```php
// Use Gemini explicitly
$response = $this->aiService->complete(
    'Describe the architecture of TYPO3',
    ['provider' => 'gemini']
);

// Multimodal request (text + image)
$description = $this->aiService->execute(
    'vision',
    [
        'image_url' => 'https://example.com/diagram.png',
        'prompt' => 'Explain this architecture diagram',
    ],
    ['provider' => 'gemini']
);
```

### Advanced Features

#### Safety Settings

```php
// Custom safety settings
$response = $this->aiService->complete(
    'Generate creative content',
    [
        'provider' => 'gemini',
        'safety_level' => 'BLOCK_ONLY_HIGH',  // Less restrictive
    ]
);
```

#### Multiple Images

```php
// Analyze multiple images in single request
$response = $this->aiService->execute(
    'vision',
    [
        'images' => [
            'https://example.com/before.jpg',
            'https://example.com/after.jpg',
        ],
        'prompt' => 'Compare these two images',
    ],
    ['provider' => 'gemini']  // Gemini handles multi-image best
);
```

#### Embeddings

```php
// Generate embeddings
$embeddings = $this->aiService->generateEmbedding(
    'TYPO3 is a content management system',
    ['provider' => 'gemini']
);

// Batch embeddings
$embeddings = $this->aiService->execute(
    'embeddings',
    ['texts' => $arrayOfTexts],
    ['provider' => 'gemini']
);
```

### Cost Optimization

```php
// Use Flash model for simple tasks
$response = $this->aiService->complete(
    'Summarize this text',
    [
        'provider' => 'gemini',
        'model' => 'gemini-1.5-flash',  // Cheaper
    ]
);

// Use Pro model for complex tasks
$response = $this->aiService->complete(
    'Analyze this complex code architecture',
    [
        'provider' => 'gemini',
        'model' => 'gemini-1.5-pro',  // More capable
    ]
);
```

### Error Handling

```php
use Netresearch\AiBase\Exception\ProviderException;

try {
    $response = $this->aiService->complete($prompt, ['provider' => 'gemini']);
} catch (ProviderException $e) {
    // Check if safety filter triggered
    if (str_contains($e->getMessage(), 'safety filters')) {
        $safetyRatings = $e->getContext()['safety_ratings'] ?? [];
        // Handle safety block
    }
}
```

---

## DeepL Integration

### Configuration

```php
# ext_conf_template.txt
providers.deepl.apiKey = your-api-key
providers.deepl.tier = free  # or pro
providers.deepl.formality = default
providers.deepl.preserveHtml = 1
```

### Basic Usage

```php
// Simple translation (DeepL automatically used for translation)
$translation = $this->aiService->translate(
    'Hello world',
    'de'
);

// Explicit DeepL usage
$translation = $this->aiService->translate(
    'Hello world',
    'de',
    null,  // Auto-detect source language
    ['provider' => 'deepl']
);
```

### Advanced Features

#### Formality Control

```php
// Formal translation (German business correspondence)
$translation = $this->aiService->translate(
    'Please send the report',
    'de',
    'en',
    [
        'provider' => 'deepl',
        'formality' => 'more',  // Formal German
    ]
);

// Informal translation
$translation = $this->aiService->translate(
    'Thanks for your help',
    'de',
    'en',
    [
        'provider' => 'deepl',
        'formality' => 'less',  // Casual German
    ]
);
```

#### HTML Preservation

```php
// Translate HTML content
$translation = $this->aiService->translate(
    '<h1>Welcome</h1><p>This is <strong>important</strong></p>',
    'de',
    'en',
    [
        'provider' => 'deepl',
        'tag_handling' => 'html',
        'preserve_formatting' => true,
    ]
);
// Result: <h1>Willkommen</h1><p>Das ist <strong>wichtig</strong></p>
```

#### Glossary Usage

```php
// Use custom glossary for consistent terminology
$translation = $this->aiService->translate(
    'The backend system uses a content repository',
    'de',
    'en',
    [
        'provider' => 'deepl',
        'glossary_id' => 'uuid-technical-glossary',
    ]
);
// Ensures "backend", "content repository" translated consistently
```

#### Batch Translation

```php
// Translate multiple texts efficiently
$deepLProvider = $this->providerFactory->create('deepl');

$translations = $deepLProvider->translateBatch(
    [
        'Hello',
        'Goodbye',
        'Thank you',
    ],
    'de'
);

foreach ($translations as $translation) {
    echo $translation->getTranslation() . "\n";
}
```

### Important Limitations

```php
// DeepL ONLY supports translation
try {
    $deepLProvider->complete('Generate text');  // Throws exception
} catch (NotSupportedException $e) {
    // Expected: "DeepL does not support completion"
}

// For other AI tasks, use different provider
$aiService->complete(
    'Generate content',
    ['provider' => 'openai']  // Not DeepL
);
```

### Usage Monitoring

```php
// Check DeepL quota
$deepLProvider = $this->providerFactory->create('deepl');
$usage = $deepLProvider->getUsage();

echo "Characters used: {$usage['character_count']}\n";
echo "Character limit: {$usage['character_limit']}\n";
echo "Usage: {$usage['usage_percent']}%\n";

// Alert if approaching limit
if ($usage['usage_percent'] > 90) {
    // Switch to LLM-based translation
    $this->aiService->translate($text, $targetLang, null, [
        'provider' => 'openai'
    ]);
}
```

---

## OpenRouter Integration

### Configuration

```php
# ext_conf_template.txt
providers.openrouter.apiKey = sk-or-v1-your-key
providers.openrouter.model = anthropic/claude-3-sonnet
providers.openrouter.autoFallback = 1
providers.openrouter.fallbackModels = anthropic/claude-3-haiku,openai/gpt-3.5-turbo
providers.openrouter.routingStrategy = balanced
```

### Basic Usage

```php
// Use OpenRouter (accesses 100+ models via single API)
$response = $this->aiService->complete(
    'Explain dependency injection',
    ['provider' => 'openrouter']
);
```

### Model Selection

```php
// Explicit model selection
$response = $this->aiService->complete(
    'Generate code',
    [
        'provider' => 'openrouter',
        'model' => 'openai/gpt-4-turbo',  // Specific model
    ]
);

// Cost-optimized model
$response = $this->aiService->complete(
    'Simple question',
    [
        'provider' => 'openrouter',
        'model' => 'anthropic/claude-3-haiku',  // Cheapest Claude
    ]
);

// Long context model
$response = $this->aiService->complete(
    $veryLongPrompt,
    [
        'provider' => 'openrouter',
        'model' => 'anthropic/claude-3-opus',  // 200K context
    ]
);
```

### Automatic Fallback

```php
// OpenRouter automatically falls back if primary model unavailable
$response = $this->aiService->complete(
    'Generate text',
    [
        'provider' => 'openrouter',
        'auto_fallback' => true,
        'fallback_models' => [
            'anthropic/claude-3-opus',     // Try first
            'anthropic/claude-3-sonnet',   // Then this
            'openai/gpt-4-turbo',          // Then this
            'openai/gpt-3.5-turbo',        // Last resort
        ]
    ]
);
```

### Cost Tracking

```php
// OpenRouter provides exact costs (no estimation)
$response = $this->aiService->complete(
    'Generate content',
    ['provider' => 'openrouter']
);

$cost = $response->getCost();  // Exact cost in USD
echo "Request cost: \${$cost}\n";
```

### Model Discovery

```php
// Get available models
$openRouterProvider = $this->providerFactory->create('openrouter');
$models = $openRouterProvider->getAvailableModels();

foreach ($models as $id => $model) {
    echo "{$id}: {$model['name']}\n";
    echo "  Context: {$model['context_length']} tokens\n";
    echo "  Cost: \${$model['pricing']['prompt']} per prompt token\n";
}
```

### Credits Management

```php
// Check credit balance
$openRouterProvider = $this->providerFactory->create('openrouter');
$credits = $openRouterProvider->getCredits();

echo "Balance: \${$credits['balance']}\n";
echo "Usage: \${$credits['usage']}\n";

if ($credits['balance'] < 10.0) {
    // Alert: low balance
}
```

### Cost Optimization Strategy

```php
// Implement smart model routing
class CostOptimizedService
{
    public function generate(string $prompt): string
    {
        // Estimate complexity
        $complexity = $this->estimateComplexity($prompt);

        // Route to appropriate model
        $model = match($complexity) {
            'simple' => 'anthropic/claude-3-haiku',      // $0.25/1M
            'medium' => 'anthropic/claude-3-sonnet',     // $3/1M
            'complex' => 'anthropic/claude-3-opus',      // $15/1M
        };

        return $this->aiService->complete($prompt, [
            'provider' => 'openrouter',
            'model' => $model,
        ])->getContent();
    }
}
```

---

## Fallback Chains

### Provider-Level Fallback

```php
// Configure fallback chain in ProviderFactory
$providers = [
    'primary' => 'deepl',       // Try DeepL first for translation
    'secondary' => 'gemini',    // Fall back to Gemini
    'tertiary' => 'openrouter', // Last resort
];

// AiServiceManager handles fallback automatically
$translation = $this->aiService->translate($text, 'de');
// Tries: DeepL → Gemini → OpenRouter
```

### Model-Level Fallback (OpenRouter)

```php
// OpenRouter handles model fallback internally
$response = $this->aiService->complete($prompt, [
    'provider' => 'openrouter',
    'auto_fallback' => true,
]);
// OpenRouter tries alternative models if primary unavailable
```

### Feature-Level Fallback

```php
// TranslationService with smart fallback
public function translate(string $text, string $targetLang): string
{
    try {
        // Try DeepL (best quality)
        return $this->aiService->translate($text, $targetLang, null, [
            'provider' => 'deepl'
        ])->getTranslation();
    } catch (QuotaExceededException $e) {
        // DeepL quota exceeded, use LLM
        return $this->aiService->translate($text, $targetLang, null, [
            'provider' => 'gemini'
        ])->getTranslation();
    } catch (ProviderException $e) {
        // DeepL unavailable, use LLM
        return $this->aiService->translate($text, $targetLang, null, [
            'provider' => 'openrouter'
        ])->getTranslation();
    }
}
```

---

## Cost Optimization

### 1. Provider Selection by Cost

```php
// Cost comparison (approximate, as of Dec 2024)

// Translation:
$deeplCost = 0.00;              // Free tier (500K chars/month)
$geminiCost = 0.000075;         // $0.075 per 1M input tokens
$openRouterCost = 0.0005;       // via GPT-3.5

// Vision:
$geminiCost = 0.000075;         // Gemini 1.5 Flash
$gpt4vCost = 0.00001;           // via OpenRouter

// Embeddings:
$geminiCost = 0.00001;          // per 1K tokens
$openaiCost = 0.00002;          // via OpenRouter

// Strategy: Use DeepL for translation (free), Gemini for vision/embeddings
```

### 2. Model Tiering

```php
class CostOptimizedAiService
{
    public function complete(string $prompt): string
    {
        $tokenCount = $this->estimateTokens($prompt);

        if ($tokenCount < 1000) {
            // Simple task → cheapest model
            $provider = 'openrouter';
            $model = 'anthropic/claude-3-haiku';  // $0.25/1M
        } elseif ($tokenCount < 10000) {
            // Medium task → balanced model
            $provider = 'gemini';
            $model = 'gemini-1.5-flash';  // $0.075/1M
        } else {
            // Complex task → best model
            $provider = 'openrouter';
            $model = 'anthropic/claude-3-opus';  // $15/1M
        }

        return $this->aiService->complete($prompt, [
            'provider' => $provider,
            'model' => $model,
        ])->getContent();
    }
}
```

### 3. Aggressive Caching

```php
// Cache translation results (rarely change)
$cacheKey = 'translation_' . md5($text . $targetLang);

if ($cached = $this->cache->get($cacheKey)) {
    return $cached;
}

$translation = $this->aiService->translate($text, $targetLang);

$this->cache->set($cacheKey, $translation, [], 86400);  // 24 hours

return $translation;
```

### 4. Batch Operations

```php
// Batch translations with DeepL (more efficient)
$deepLProvider = $this->providerFactory->create('deepl');

$translations = $deepLProvider->translateBatch(
    $arrayOfTexts,
    'de'
);
// Single API call vs multiple calls = cost savings
```

---

## Error Handling

### Provider-Specific Errors

```php
use Netresearch\AiBase\Exception\{
    ProviderException,
    QuotaExceededException,
    NotSupportedException,
    ConfigurationException
};

try {
    $response = $this->aiService->complete($prompt, [
        'provider' => 'gemini'
    ]);
} catch (NotSupportedException $e) {
    // DeepL doesn't support this operation
    // Fall back to LLM provider
} catch (QuotaExceededException $e) {
    // Quota exceeded, switch provider or notify admin
    $this->logger->warning('Quota exceeded', [
        'provider' => 'gemini'
    ]);
    // Try alternative
    $response = $this->aiService->complete($prompt, [
        'provider' => 'openrouter'
    ]);
} catch (ProviderException $e) {
    // General provider error
    $this->logger->error('Provider error', [
        'message' => $e->getMessage(),
        'context' => $e->getContext(),
    ]);
}
```

### Graceful Degradation

```php
public function generateImageAltText(string $imageUrl): string
{
    // Try Gemini (best multimodal)
    try {
        return $this->aiService->execute('vision', [
            'image_url' => $imageUrl,
            'prompt' => 'Generate alt text',
        ], ['provider' => 'gemini'])->getDescription();
    } catch (\Exception $e) {
        $this->logger->warning('Gemini vision failed', [
            'error' => $e->getMessage()
        ]);
    }

    // Try GPT-4V via OpenRouter
    try {
        return $this->aiService->execute('vision', [
            'image_url' => $imageUrl,
            'prompt' => 'Generate alt text',
        ], ['provider' => 'openrouter'])->getDescription();
    } catch (\Exception $e) {
        $this->logger->warning('OpenRouter vision failed', [
            'error' => $e->getMessage()
        ]);
    }

    // Final fallback: generic alt text
    return 'Image';
}
```

---

## Testing

### Unit Tests

```php
use Netresearch\AiBase\Tests\Unit\Provider\GeminiProviderTest;

class MyServiceTest extends UnitTestCase
{
    /**
     * @test
     */
    public function usesGeminiForVision(): void
    {
        $geminiProvider = $this->createMock(GeminiProvider::class);
        $geminiProvider
            ->expects($this->once())
            ->method('analyzeImage')
            ->with('https://example.com/image.jpg')
            ->willReturn(new VisionResponse('Cat image', 0.95));

        $this->providerFactory
            ->method('create')
            ->with('gemini')
            ->willReturn($geminiProvider);

        $result = $this->service->generateAltText('https://example.com/image.jpg');

        $this->assertEquals('Cat image', $result);
    }
}
```

### Integration Tests

```php
/**
 * @test
 * @group integration
 */
public function fallsBackFromDeepLToGemini(): void
{
    // Configure DeepL with expired quota
    $this->configureProvider('deepl', [
        'quotaExceeded' => true
    ]);

    // Translation should fall back to Gemini
    $translation = $this->aiService->translate('Hello', 'de');

    $this->assertEquals('Hallo', $translation->getTranslation());
    $this->assertEquals('gemini', $translation->getMetadata()['provider']);
}
```

### Live API Tests

```php
/**
 * @test
 * @group live
 * @requires env GEMINI_API_KEY
 */
public function geminiTranslationWorks(): void
{
    $response = $this->aiService->translate(
        'Hello world',
        'de',
        null,
        ['provider' => 'gemini']
    );

    $this->assertStringContainsString('Hallo', $response->getTranslation());
}
```

---

## Summary

### When to Use Each Provider

| Provider | Best For | Avoid For |
|----------|----------|-----------|
| **Gemini** | Vision, multimodal, embeddings, long context | - |
| **DeepL** | Translation (best quality), formality control | Anything except translation |
| **OpenRouter** | Cost optimization, fallback, experimentation | Privacy-sensitive (data leaves your control) |

### Quick Decision Guide

```
Need translation?
├─ High quality → DeepL
├─ Creative/contextual → Gemini
└─ Cost-sensitive → OpenRouter

Need vision?
├─ Multiple images → Gemini
├─ Single image → Gemini or GPT-4V
└─ Cost-sensitive → Gemini

Need embeddings?
├─ Standard (768d) → Gemini
└─ High-dimensional → OpenAI via OpenRouter

Need chat?
├─ Long context → OpenRouter (Claude)
├─ Multimodal → Gemini
└─ Cost-optimized → OpenRouter (auto-route)
```

---

## Next Steps

1. Configure API keys in `.env`
2. Set up provider preferences in site configuration
3. Implement fallback chains
4. Monitor costs and quotas
5. Optimize based on usage patterns
