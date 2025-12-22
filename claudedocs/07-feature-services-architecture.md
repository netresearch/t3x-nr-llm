# Feature Services Architecture - nr-llm Extension

> Created: 2025-12-22
> Purpose: High-level service layer design with prompt engineering and response parsing

---

## 1. Architecture Overview

### Service Layer Hierarchy

```
┌─────────────────────────────────────────────────────────┐
│            Consuming Extensions Layer                    │
│  (rte-ckeditor-image, textdb, contexts)                 │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│              Feature Services Layer                      │
│  - CompletionService                                     │
│  - VisionService                                         │
│  - EmbeddingService                                      │
│  - TranslationService                                    │
│  - PromptTemplateService                                 │
│  ➜ Prompt engineering                                    │
│  ➜ Response parsing                                      │
│  ➜ Domain-specific logic                                 │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│              LlmServiceManager                           │
│  - Provider routing                                      │
│  - Caching                                               │
│  - Rate limiting                                         │
│  - Usage tracking                                        │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│            Provider Implementations                      │
│  (OpenAI, Anthropic, Gemini, etc.)                      │
└─────────────────────────────────────────────────────────┘
```

---

## 2. Service Design Principles

1. **Single Responsibility**: Each service handles one feature domain
2. **Prompt Engineering**: Encapsulate domain expertise in prompts
3. **Response Parsing**: Transform raw AI output into structured data
4. **Configuration Driven**: Customizable prompts and parameters
5. **Type Safety**: Strong typing for inputs and outputs
6. **Testability**: Easy mocking and unit testing

---

## 3. CompletionService

### Purpose
Simple text completion with system prompt support, creativity control, and format specification.

### Capabilities
- Text generation with configurable creativity
- System prompt + user prompt separation
- Response format control (text, JSON, markdown)
- Token limit management
- Temperature control

### Use Cases
- contexts: Rule generation from natural language
- textdb: Content suggestions
- General text generation tasks

### Configuration Options
```php
[
    'temperature' => 0.7,        // 0.0-2.0 (creativity)
    'max_tokens' => 1000,        // Output limit
    'top_p' => 1.0,              // Nucleus sampling
    'frequency_penalty' => 0.0,  // Repetition penalty
    'presence_penalty' => 0.0,   // Topic diversity
    'response_format' => 'text', // text|json|markdown
    'system_prompt' => '',       // Optional system context
    'stop_sequences' => [],      // Stop generation on these
]
```

### Example Usage
```php
$completion = $completionService->complete(
    prompt: 'Explain TYPO3 in simple terms',
    options: [
        'temperature' => 0.3,
        'max_tokens' => 200,
        'response_format' => 'markdown',
        'system_prompt' => 'You are a technical writer for CMS documentation.'
    ]
);

// $completion->text: Markdown-formatted explanation
// $completion->usage: Token statistics
// $completion->finishReason: 'stop'|'length'|'content_filter'
```

---

## 4. VisionService

### Purpose
Image analysis with specialized prompts for different use cases (alt text, SEO, descriptions).

### Capabilities
- Image analysis with custom prompts
- Alt text generation (accessibility-focused)
- Title generation (SEO-focused)
- Description generation (detailed analysis)
- Multiple image support
- Image URL vs base64 handling

### Use Cases
- rte-ckeditor-image: Alt text, title, description generation
- Content management: Image cataloging
- Accessibility compliance

### Specialized Methods

#### Alt Text Generation
```php
public function generateAltText(
    string|array $imageUrl,
    array $options = []
): string|array

// Prompt Template:
// System: "You are an accessibility expert. Generate concise, descriptive
// alt text that conveys the essential information in the image for users
// who cannot see it. Focus on content and function, not artistic details."
// User: "Analyze this image: {image_url}"
```

#### Title Generation
```php
public function generateTitle(
    string|array $imageUrl,
    array $options = []
): string|array

// Prompt Template:
// System: "You are an SEO specialist. Generate a compelling, keyword-rich
// title for this image that will improve search rankings. Keep it under
// 60 characters."
// User: "Create SEO-optimized title for: {image_url}"
```

#### Description Generation
```php
public function generateDescription(
    string|array $imageUrl,
    array $options = []
): string|array

// Prompt Template:
// System: "You are a content curator. Provide a detailed, accurate
// description of this image including subjects, setting, colors, mood,
// and notable details."
// User: "Describe this image in detail: {image_url}"
```

#### Custom Analysis
```php
public function analyzeImage(
    string|array $imageUrl,
    string $customPrompt,
    array $options = []
): string|array

// User-defined prompt for specialized analysis
```

### Configuration Options
```php
[
    'detail_level' => 'auto',     // auto|low|high (vision API detail)
    'max_tokens' => 300,          // Output limit
    'batch_mode' => true,         // Process multiple images efficiently
    'image_format' => 'url',      // url|base64
    'temperature' => 0.5,         // Lower for consistency
]
```

### Example Usage
```php
// Single image alt text
$altText = $visionService->generateAltText(
    'https://example.com/image.jpg'
);
// Result: "A red barn in a green field under blue sky"

// Batch processing
$altTexts = $visionService->generateAltText([
    'https://example.com/img1.jpg',
    'https://example.com/img2.jpg',
]);
// Result: ['alt text 1', 'alt text 2']

// Custom analysis
$analysis = $visionService->analyzeImage(
    'https://example.com/chart.jpg',
    'What data trends are shown in this chart?'
);
```

---

## 5. EmbeddingService

### Purpose
Text-to-vector conversion for semantic search and similarity calculations.

### Capabilities
- Text to vector conversion
- Batch embedding (efficient bulk processing)
- Dimension configuration per provider
- Similarity calculation utilities
- Caching strategy (embeddings are deterministic)

### Use Cases
- textdb: Semantic translation memory search
- contexts: Rule similarity matching
- Content recommendation
- Duplicate detection

### Methods

#### Single Embedding
```php
public function embed(
    string $text,
    array $options = []
): array

// Returns: [0.123, -0.456, 0.789, ...] (vector)
```

#### Batch Embedding
```php
public function embedBatch(
    array $texts,
    array $options = []
): array

// Returns: [
//     [0.123, -0.456, ...],
//     [0.234, -0.567, ...],
// ]
```

#### Similarity Calculation
```php
public function cosineSimilarity(
    array $vectorA,
    array $vectorB
): float

// Returns: 0.0-1.0 (similarity score)
```

```php
public function findMostSimilar(
    array $queryVector,
    array $candidateVectors,
    int $topK = 5
): array

// Returns: [
//     ['index' => 0, 'similarity' => 0.95],
//     ['index' => 2, 'similarity' => 0.87],
//     ...
// ]
```

### Configuration Options
```php
[
    'model' => 'text-embedding-3-small', // Provider-specific model
    'dimensions' => 1536,                 // Output dimensions (if supported)
    'encoding_format' => 'float',         // float|base64
    'cache_ttl' => 86400,                 // Cache for 24h (deterministic)
    'batch_size' => 100,                  // Max items per batch request
]
```

### Caching Strategy
```php
// Cache key: sha256(model + text)
// TTL: Long (embeddings are deterministic)
// Invalidation: Model change only

private function getCacheKey(string $text, string $model): string
{
    return 'embedding_' . hash('sha256', $model . '|' . $text);
}
```

### Example Usage
```php
// Single embedding
$vector = $embeddingService->embed('TYPO3 is a CMS');

// Batch processing
$vectors = $embeddingService->embedBatch([
    'Translation memory entry 1',
    'Translation memory entry 2',
    'Translation memory entry 3',
]);

// Find similar translations
$queryVector = $embeddingService->embed('new source text');
$similar = $embeddingService->findMostSimilar(
    $queryVector,
    $translationMemoryVectors,
    topK: 3
);
```

---

## 6. TranslationService

### Purpose
Language translation with quality control and context awareness.

### Capabilities
- Language detection
- Translation with glossary support
- Formality levels (formal/informal)
- Context-aware translation (surrounding content)
- Batch translation
- Translation quality scoring

### Methods

#### Basic Translation
```php
public function translate(
    string $text,
    string $targetLanguage,
    ?string $sourceLanguage = null,
    array $options = []
): TranslationResult
```

#### Language Detection
```php
public function detectLanguage(string $text): string

// Returns: 'en', 'de', 'fr', etc.
```

#### Batch Translation
```php
public function translateBatch(
    array $texts,
    string $targetLanguage,
    ?string $sourceLanguage = null,
    array $options = []
): array
```

#### Quality Scoring
```php
public function scoreTranslationQuality(
    string $sourceText,
    string $translatedText,
    string $targetLanguage
): float

// Returns: 0.0-1.0 (quality score)
```

### Configuration Options
```php
[
    'formality' => 'default',      // default|formal|informal
    'glossary' => [],              // ['term' => 'translation', ...]
    'context' => '',               // Surrounding content for context
    'preserve_formatting' => true, // Keep HTML, markdown, etc.
    'domain' => 'general',         // general|technical|medical|legal
    'temperature' => 0.3,          // Low for consistency
    'max_tokens' => 2000,
]
```

### Prompt Template
```php
// System Prompt:
"You are a professional translator specializing in {domain} content.
Translate the following text from {source_language} to {target_language}.
Maintain {formality} tone and preserve all formatting.

Glossary terms (use these exact translations):
{glossary}

Context (for reference only):
{context}"

// User Prompt:
"Translate this text:
{text}"
```

### Example Usage
```php
// Basic translation
$result = $translationService->translate(
    text: 'Hello, world!',
    targetLanguage: 'de'
);
// $result->translation: 'Hallo, Welt!'
// $result->sourceLanguage: 'en' (auto-detected)
// $result->confidence: 0.95

// Translation with glossary and context
$result = $translationService->translate(
    text: 'The TYPO3 extension uses TypoScript configuration.',
    targetLanguage: 'de',
    sourceLanguage: 'en',
    options: [
        'formality' => 'formal',
        'glossary' => [
            'TYPO3' => 'TYPO3',
            'TypoScript' => 'TypoScript',
            'extension' => 'Erweiterung',
        ],
        'domain' => 'technical',
        'context' => 'This is from a technical documentation about CMS development.',
    ]
);

// Batch translation
$results = $translationService->translateBatch(
    texts: ['Hello', 'Goodbye', 'Thank you'],
    targetLanguage: 'fr'
);
```

---

## 7. Prompt Template System

### Purpose
Centralized, configurable prompt management with version control and A/B testing support.

### Architecture

```
┌──────────────────────────────────────────────────────┐
│              Prompt Template System                   │
├──────────────────────────────────────────────────────┤
│                                                        │
│  Storage Layer:                                        │
│  ├─ Database (tx_nrllm_prompts)                      │
│  ├─ File System (fallback/defaults)                   │
│  └─ Configuration (ext_conf override)                 │
│                                                        │
│  Template Engine:                                      │
│  ├─ Variable substitution                             │
│  ├─ Conditional sections                              │
│  ├─ Template inheritance                              │
│  └─ Localization support                              │
│                                                        │
│  Version Control:                                      │
│  ├─ Prompt versioning                                 │
│  ├─ A/B testing variants                              │
│  ├─ Rollback capability                               │
│  └─ Performance metrics per version                   │
│                                                        │
└──────────────────────────────────────────────────────┘
```

### Database Schema

```sql
CREATE TABLE tx_nrllm_prompts (
    uid INT PRIMARY KEY AUTO_INCREMENT,
    pid INT DEFAULT 0 NOT NULL,

    -- Identification
    identifier VARCHAR(100) UNIQUE NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    feature VARCHAR(100) NOT NULL,

    -- Prompt Content
    system_prompt TEXT,
    user_prompt_template TEXT,

    -- Versioning
    version INT DEFAULT 1 NOT NULL,
    parent_uid INT DEFAULT 0,
    is_active TINYINT DEFAULT 1 NOT NULL,
    is_default TINYINT DEFAULT 0 NOT NULL,

    -- Provider Configuration
    provider VARCHAR(50),
    model VARCHAR(100),
    temperature DECIMAL(3,2) DEFAULT 0.7,
    max_tokens INT DEFAULT 1000,
    top_p DECIMAL(3,2) DEFAULT 1.0,

    -- Metadata
    variables TEXT,           -- JSON: available template variables
    example_output TEXT,      -- Example response for validation
    tags VARCHAR(255),        -- Comma-separated tags

    -- Performance Tracking
    usage_count INT DEFAULT 0,
    avg_response_time INT DEFAULT 0,
    avg_tokens_used INT DEFAULT 0,
    quality_score DECIMAL(3,2) DEFAULT 0,

    -- Timestamps
    tstamp INT DEFAULT 0 NOT NULL,
    crdate INT DEFAULT 0 NOT NULL,

    KEY feature_active (feature, is_active),
    KEY identifier_version (identifier, version)
);
```

### Service Interface

```php
interface PromptTemplateServiceInterface
{
    /**
     * Get active prompt by identifier
     */
    public function getPrompt(string $identifier): PromptTemplate;

    /**
     * Render prompt with variables
     */
    public function render(
        string $identifier,
        array $variables = [],
        array $options = []
    ): RenderedPrompt;

    /**
     * Create new prompt version
     */
    public function createVersion(
        string $identifier,
        array $updates
    ): PromptTemplate;

    /**
     * A/B test variants
     */
    public function getVariant(
        string $identifier,
        string $variantName
    ): PromptTemplate;

    /**
     * Track performance
     */
    public function recordUsage(
        string $identifier,
        int $responseTime,
        int $tokensUsed,
        float $qualityScore
    ): void;
}
```

### Default Prompts

#### Vision - Alt Text
```php
[
    'identifier' => 'vision.alt_text',
    'feature' => 'vision',
    'system_prompt' => 'You are an accessibility expert specializing in WCAG 2.1 Level AA compliance. Generate concise, descriptive alt text that conveys essential information for screen reader users. Focus on content and function, not artistic interpretation. Keep it under 125 characters.',
    'user_prompt_template' => 'Generate alt text for this image: {{image_url}}',
    'temperature' => 0.5,
    'max_tokens' => 100,
]
```

#### Vision - SEO Title
```php
[
    'identifier' => 'vision.seo_title',
    'feature' => 'vision',
    'system_prompt' => 'You are an SEO specialist. Generate compelling, keyword-rich titles for images that improve search rankings. Include primary subject and context. Keep under 60 characters. Use sentence case.',
    'user_prompt_template' => 'Create SEO-optimized title for: {{image_url}}',
    'temperature' => 0.7,
    'max_tokens' => 50,
]
```

#### Vision - Detailed Description
```php
[
    'identifier' => 'vision.description',
    'feature' => 'vision',
    'system_prompt' => 'You are a content curator. Provide detailed, accurate descriptions including: main subjects, setting, colors, mood, composition, and notable details. Be objective and precise.',
    'user_prompt_template' => 'Describe this image in detail: {{image_url}}',
    'temperature' => 0.6,
    'max_tokens' => 300,
]
```

#### Translation - Technical Content
```php
[
    'identifier' => 'translation.technical',
    'feature' => 'translation',
    'system_prompt' => 'You are a professional technical translator. Translate from {{source_language}} to {{target_language}}. Maintain {{formality}} tone. Preserve all formatting, code snippets, and technical terms.

{{#if glossary}}
Use these exact term translations:
{{glossary}}
{{/if}}

{{#if context}}
Context (for reference):
{{context}}
{{/if}}',
    'user_prompt_template' => 'Translate this text:\n\n{{text}}',
    'temperature' => 0.3,
    'max_tokens' => 2000,
]
```

#### Completion - Rule Generation
```php
[
    'identifier' => 'completion.rule_generation',
    'feature' => 'completion',
    'system_prompt' => 'You are an expert in TYPO3 contexts extension. Convert natural language descriptions into valid context rules. Output valid JSON matching the schema. Be precise with condition syntax.',
    'user_prompt_template' => 'Generate TYPO3 context rule for: {{description}}

Schema:
{{schema}}',
    'temperature' => 0.2,
    'max_tokens' => 1000,
    'response_format' => 'json',
]
```

### Variable Substitution

```php
class PromptRenderer
{
    public function render(
        PromptTemplate $template,
        array $variables
    ): RenderedPrompt {
        $systemPrompt = $this->substitute(
            $template->getSystemPrompt(),
            $variables
        );

        $userPrompt = $this->substitute(
            $template->getUserPromptTemplate(),
            $variables
        );

        return new RenderedPrompt(
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            model: $template->getModel(),
            temperature: $template->getTemperature(),
            maxTokens: $template->getMaxTokens()
        );
    }

    private function substitute(string $template, array $vars): string
    {
        // Simple {{variable}} substitution
        $result = preg_replace_callback(
            '/\{\{(\w+)\}\}/',
            fn($m) => $vars[$m[1]] ?? '',
            $template
        );

        // Conditional sections: {{#if var}}...{{/if}}
        $result = preg_replace_callback(
            '/\{\{#if (\w+)\}\}(.*?)\{\{\/if\}\}/s',
            fn($m) => !empty($vars[$m[1]]) ? $m[2] : '',
            $result
        );

        return $result;
    }
}
```

---

## 8. Service-to-Extension Mapping

### rte-ckeditor-image

```php
use Netresearch\NrLlm\Service\VisionService;

class ImageAiService
{
    public function __construct(
        private VisionService $visionService
    ) {}

    public function enhanceImage(int $fileUid): array
    {
        $imageUrl = $this->getPublicUrl($fileUid);

        return [
            'alt' => $this->visionService->generateAltText($imageUrl),
            'title' => $this->visionService->generateTitle($imageUrl),
            'description' => $this->visionService->generateDescription($imageUrl),
        ];
    }

    public function batchEnhanceImages(array $fileUids): array
    {
        $imageUrls = array_map(
            fn($uid) => $this->getPublicUrl($uid),
            $fileUids
        );

        return [
            'alt' => $this->visionService->generateAltText($imageUrls),
            'title' => $this->visionService->generateTitle($imageUrls),
            'description' => $this->visionService->generateDescription($imageUrls),
        ];
    }
}
```

### textdb

```php
use Netresearch\NrLlm\Service\TranslationService;
use Netresearch\NrLlm\Service\EmbeddingService;

class AiTranslationService
{
    public function __construct(
        private TranslationService $translationService,
        private EmbeddingService $embeddingService
    ) {}

    public function suggestTranslation(
        string $sourceText,
        string $targetLanguage,
        array $context = []
    ): array {
        $result = $this->translationService->translate(
            text: $sourceText,
            targetLanguage: $targetLanguage,
            options: [
                'context' => $context['surrounding_text'] ?? '',
                'glossary' => $context['terminology'] ?? [],
                'formality' => $context['formality'] ?? 'default',
            ]
        );

        return [
            'translation' => $result->translation,
            'confidence' => $result->confidence,
            'alternatives' => $result->alternatives ?? [],
        ];
    }

    public function findSimilarTranslations(
        string $sourceText,
        int $limit = 5
    ): array {
        $queryVector = $this->embeddingService->embed($sourceText);

        $memoryVectors = $this->loadTranslationMemoryVectors();

        return $this->embeddingService->findMostSimilar(
            $queryVector,
            $memoryVectors,
            topK: $limit
        );
    }
}
```

### contexts

```php
use Netresearch\NrLlm\Service\CompletionService;

class RuleGeneratorService
{
    public function __construct(
        private CompletionService $completionService,
        private PromptTemplateService $promptService
    ) {}

    public function generateRule(string $description): array
    {
        $prompt = $this->promptService->render(
            'completion.rule_generation',
            [
                'description' => $description,
                'schema' => $this->getRuleSchema(),
            ]
        );

        $response = $this->completionService->complete(
            prompt: $prompt->getUserPrompt(),
            options: [
                'system_prompt' => $prompt->getSystemPrompt(),
                'temperature' => $prompt->getTemperature(),
                'max_tokens' => $prompt->getMaxTokens(),
                'response_format' => 'json',
            ]
        );

        $rule = json_decode($response->text, true);

        return $this->validateRule($rule) ? $rule : null;
    }
}
```

---

## 9. Response DTOs

### CompletionResponse
```php
class CompletionResponse
{
    public function __construct(
        public readonly string $text,
        public readonly UsageStatistics $usage,
        public readonly string $finishReason,
        public readonly ?string $model = null,
        public readonly ?array $metadata = null,
    ) {}
}
```

### VisionResponse
```php
class VisionResponse
{
    public function __construct(
        public readonly string $analysis,
        public readonly UsageStatistics $usage,
        public readonly ?float $confidence = null,
        public readonly ?array $detectedObjects = null,
        public readonly ?array $metadata = null,
    ) {}
}
```

### TranslationResult
```php
class TranslationResult
{
    public function __construct(
        public readonly string $translation,
        public readonly string $sourceLanguage,
        public readonly string $targetLanguage,
        public readonly float $confidence,
        public readonly UsageStatistics $usage,
        public readonly ?array $alternatives = null,
        public readonly ?array $metadata = null,
    ) {}
}
```

### EmbeddingResponse
```php
class EmbeddingResponse
{
    public function __construct(
        public readonly array $vector,
        public readonly int $dimensions,
        public readonly UsageStatistics $usage,
        public readonly ?string $model = null,
    ) {}
}
```

---

## 10. Testing Strategy

### Unit Tests with Mocking

```php
// CompletionServiceTest.php
class CompletionServiceTest extends TestCase
{
    public function testBasicCompletion(): void
    {
        $mockManager = $this->createMock(LlmServiceManager::class);
        $mockManager->expects($this->once())
            ->method('complete')
            ->with(
                $this->equalTo('Test prompt'),
                $this->arrayHasKey('temperature')
            )
            ->willReturn(new LlmResponse('Test response', []));

        $service = new CompletionService($mockManager);

        $result = $service->complete('Test prompt');

        $this->assertInstanceOf(CompletionResponse::class, $result);
        $this->assertEquals('Test response', $result->text);
    }
}

// VisionServiceTest.php
class VisionServiceTest extends TestCase
{
    public function testAltTextGeneration(): void
    {
        $mockManager = $this->createMock(LlmServiceManager::class);
        $mockPromptService = $this->createMock(PromptTemplateService::class);

        $mockPromptService->expects($this->once())
            ->method('render')
            ->with('vision.alt_text')
            ->willReturn(new RenderedPrompt(
                systemPrompt: 'Test system prompt',
                userPrompt: 'Test user prompt'
            ));

        $mockManager->expects($this->once())
            ->method('analyzeImage')
            ->willReturn(new LlmResponse('A red barn in a field', []));

        $service = new VisionService($mockManager, $mockPromptService);

        $result = $service->generateAltText('https://example.com/image.jpg');

        $this->assertEquals('A red barn in a field', $result);
    }
}

// TranslationServiceTest.php
class TranslationServiceTest extends TestCase
{
    public function testTranslationWithGlossary(): void
    {
        $mockManager = $this->createMock(LlmServiceManager::class);
        $mockPromptService = $this->createMock(PromptTemplateService::class);

        $service = new TranslationService($mockManager, $mockPromptService);

        $result = $service->translate(
            text: 'The TYPO3 extension is great',
            targetLanguage: 'de',
            options: [
                'glossary' => ['TYPO3' => 'TYPO3', 'extension' => 'Erweiterung']
            ]
        );

        $this->assertInstanceOf(TranslationResult::class, $result);
        $this->assertStringContainsString('TYPO3', $result->translation);
        $this->assertStringContainsString('Erweiterung', $result->translation);
    }
}
```

---

## 11. Configuration File

```yaml
# Configuration/Services.yaml

services:
  _defaults:
    autowire: true
    autoconfigure: true
    public: false

  Netresearch\NrLlm\:
    resource: '../Classes/*'

  # Feature Services (public for consuming extensions)
  Netresearch\NrLlm\Service\CompletionService:
    public: true

  Netresearch\NrLlm\Service\VisionService:
    public: true

  Netresearch\NrLlm\Service\EmbeddingService:
    public: true

  Netresearch\NrLlm\Service\TranslationService:
    public: true

  Netresearch\NrLlm\Service\PromptTemplateService:
    public: true

  # Repository for prompt templates
  Netresearch\NrLlm\Domain\Repository\PromptTemplateRepository:
    public: true
```

---

## 12. Implementation Checklist

### Phase 1: Core Services
- [ ] CompletionService class
- [ ] VisionService class
- [ ] EmbeddingService class
- [ ] TranslationService class
- [ ] Response DTO classes

### Phase 2: Prompt System
- [ ] PromptTemplateService class
- [ ] PromptTemplateRepository class
- [ ] Database migration for prompts table
- [ ] Default prompt seed data
- [ ] Template rendering engine

### Phase 3: Integration
- [ ] Service registration in Services.yaml
- [ ] Documentation for consuming extensions
- [ ] Example implementations

### Phase 4: Testing
- [ ] Unit tests for all services
- [ ] Mock providers for testing
- [ ] Integration tests with real providers
- [ ] Performance benchmarks

---

## 13. Performance Considerations

### Caching Strategy
- **Embeddings**: Long TTL (deterministic)
- **Translations**: Medium TTL (context-dependent)
- **Vision**: Short TTL (subjective)
- **Completions**: Case-by-case basis

### Batch Processing
- VisionService: Process multiple images in single request
- EmbeddingService: Batch embed up to 100 texts
- TranslationService: Batch translate related texts

### Token Optimization
- Compress system prompts
- Remove unnecessary whitespace
- Use concise variable names in JSON responses
- Set appropriate max_tokens limits

---

## 14. Migration Path

### From Direct Provider Usage
```php
// Before (direct provider)
$provider = GeneralUtility::makeInstance(OpenAiProvider::class);
$response = $provider->complete('Translate: Hello');

// After (feature service)
$translationService = GeneralUtility::makeInstance(TranslationService::class);
$result = $translationService->translate('Hello', 'de');
```

### Benefits
1. **Prompt Engineering**: Domain expertise built-in
2. **Type Safety**: Structured responses instead of raw text
3. **Provider Independence**: Switch providers without code changes
4. **Testability**: Easy to mock and test
5. **Consistency**: Standardized behavior across extensions

---

## Document Cross-References

- Architecture: `01-ai-base-architecture.md`
- Roadmap: `00-implementation-roadmap.md`
- Provider Layer: (to be designed)
- Service Manager: (to be designed)
