# nr-llm Feature Services

High-level AI services for TYPO3 with prompt engineering and response parsing.

## Overview

The Feature Services layer provides domain-specific AI capabilities for TYPO3 extensions. Each service wraps the core LlmServiceManager with specialized prompts, response parsing, and configuration optimized for specific use cases.

## Services

### 1. CompletionService

**Purpose**: Text generation and completion

**Use Cases**:
- Content generation
- Rule generation (contexts extension)
- Content summarization
- SEO meta generation

**Key Features**:
- JSON response formatting
- Markdown generation
- Factual mode (low creativity)
- Creative mode (high creativity)
- System prompt support

**Example**:
```php
use Netresearch\NrLlm\Service\Feature\CompletionService;

$completion = $completionService->complete(
    prompt: 'Explain TYPO3 in simple terms',
    options: [
        'temperature' => 0.3,
        'max_tokens' => 200,
        'response_format' => 'markdown',
    ]
);

echo $completion->text;
```

### 2. VisionService

**Purpose**: Image analysis and metadata generation

**Use Cases**:
- Alt text generation (rte-ckeditor-image)
- SEO title generation
- Detailed descriptions
- Custom image analysis

**Key Features**:
- WCAG 2.1 compliant alt text
- SEO-optimized titles
- Batch processing
- Base64 and URL support

**Example**:
```php
use Netresearch\NrLlm\Service\Feature\VisionService;

// Single image
$altText = $visionService->generateAltText(
    'https://example.com/image.jpg'
);

// Batch processing
$altTexts = $visionService->generateAltText([
    'https://example.com/img1.jpg',
    'https://example.com/img2.jpg',
]);
```

### 3. EmbeddingService

**Purpose**: Text-to-vector conversion and similarity search

**Use Cases**:
- Semantic translation memory (textdb)
- Content similarity
- Duplicate detection
- Semantic search

**Key Features**:
- Aggressive caching (deterministic)
- Batch processing
- Cosine similarity calculations
- Top-K similarity search

**Example**:
```php
use Netresearch\NrLlm\Service\Feature\EmbeddingService;

// Generate embedding
$vector = $embeddingService->embed('Search query text');

// Find similar
$similar = $embeddingService->findMostSimilar(
    queryVector: $vector,
    candidateVectors: $allVectors,
    topK: 5
);
```

### 4. TranslationService

**Purpose**: Language translation with quality control

**Use Cases**:
- Translation suggestions (textdb)
- Content localization
- Glossary-aware translation

**Key Features**:
- Language detection
- Glossary support
- Formality levels
- Domain specialization
- Quality scoring

**Example**:
```php
use Netresearch\NrLlm\Service\Feature\TranslationService;

$result = $translationService->translate(
    text: 'The TYPO3 extension is great',
    targetLanguage: 'de',
    options: [
        'glossary' => ['TYPO3' => 'TYPO3'],
        'formality' => 'formal',
        'domain' => 'technical',
    ]
);

echo $result->translation;
echo $result->confidence;
```

### 5. PromptTemplateService

**Purpose**: Centralized prompt management

**Key Features**:
- Database-driven templates
- Variable substitution
- Conditional rendering
- Version control
- A/B testing
- Performance tracking

**Example**:
```php
use Netresearch\NrLlm\Service\PromptTemplateService;

$prompt = $promptService->render(
    identifier: 'vision.alt_text',
    variables: ['image_url' => 'https://example.com/img.jpg']
);

// Use with completion service
$response = $completionService->complete(
    prompt: $prompt->getUserPrompt(),
    options: [
        'system_prompt' => $prompt->getSystemPrompt(),
        'temperature' => $prompt->getTemperature(),
    ]
);
```

## Architecture

```
┌─────────────────────────────────────────────────────────┐
│            Consuming Extensions                          │
│  (rte-ckeditor-image, textdb, contexts)                 │
└──────────────────────┬──────────────────────────────────┘
                       │ Dependency Injection
┌──────────────────────▼──────────────────────────────────┐
│              Feature Services                            │
│  - CompletionService                                     │
│  - VisionService                                         │
│  - EmbeddingService                                      │
│  - TranslationService                                    │
│  - PromptTemplateService                                 │
└──────────────────────┬──────────────────────────────────┘
                       │ LLM abstraction
┌──────────────────────▼──────────────────────────────────┐
│              LlmServiceManager                           │
│  (Provider routing, caching, rate limiting)             │
└──────────────────────┬──────────────────────────────────┘
                       │ Provider calls
┌──────────────────────▼──────────────────────────────────┐
│            Provider Implementations                      │
│  (OpenAI, Anthropic, Gemini, etc.)                      │
└─────────────────────────────────────────────────────────┘
```

## File Structure

```
nr-llm/
├── Classes/
│   ├── Domain/
│   │   └── Model/
│   │       ├── CompletionResponse.php
│   │       ├── VisionResponse.php
│   │       ├── TranslationResult.php
│   │       ├── EmbeddingResponse.php
│   │       ├── UsageStatistics.php
│   │       ├── PromptTemplate.php
│   │       └── RenderedPrompt.php
│   ├── Service/
│   │   ├── Feature/
│   │   │   ├── CompletionService.php
│   │   │   ├── VisionService.php
│   │   │   ├── EmbeddingService.php
│   │   │   └── TranslationService.php
│   │   └── PromptTemplateService.php
│   └── Exception/
│       ├── InvalidArgumentException.php
│       └── PromptTemplateNotFoundException.php
├── Configuration/
│   └── Services.yaml
├── Resources/
│   └── Private/
│       └── Data/
│           └── DefaultPrompts.php
└── Tests/
    └── Unit/
        └── Service/
            └── Feature/
                ├── CompletionServiceTest.php
                ├── VisionServiceTest.php
                └── EmbeddingServiceTest.php
```

## Installation

### 1. Dependency Injection

Add to your extension's `Configuration/Services.yaml`:

```yaml
services:
  Your\Extension\Service\YourService:
    public: true
    arguments:
      $visionService: '@Netresearch\NrLlm\Service\Feature\VisionService'
      $translationService: '@Netresearch\NrLlm\Service\Feature\TranslationService'
      $completionService: '@Netresearch\NrLlm\Service\Feature\CompletionService'
      $embeddingService: '@Netresearch\NrLlm\Service\Feature\EmbeddingService'
```

### 2. Usage in Your Extension

```php
<?php

namespace Your\Extension\Service;

use Netresearch\NrLlm\Service\Feature\VisionService;

class YourService
{
    public function __construct(
        private readonly VisionService $visionService
    ) {}

    public function enhanceImage(string $imageUrl): array
    {
        return [
            'alt' => $this->visionService->generateAltText($imageUrl),
            'title' => $this->visionService->generateTitle($imageUrl),
            'description' => $this->visionService->generateDescription($imageUrl),
        ];
    }
}
```

## Default Prompts

The extension includes 10 default prompts optimized for common use cases:

### Vision
- `vision.alt_text` - WCAG 2.1 compliant alt text
- `vision.seo_title` - SEO-optimized titles
- `vision.description` - Detailed descriptions

### Translation
- `translation.general` - General purpose translation
- `translation.technical` - Technical documentation
- `translation.marketing` - Marketing copy

### Completion
- `completion.rule_generation` - TYPO3 contexts rules
- `completion.content_summary` - Content summarization
- `completion.seo_meta` - SEO meta descriptions

### Embedding
- `embedding.semantic_search` - Semantic search configuration

## Testing

### Unit Tests

```bash
# Run all tests
vendor/bin/phpunit Tests/Unit/

# Run specific service tests
vendor/bin/phpunit Tests/Unit/Service/Feature/CompletionServiceTest.php
```

### Mocking Services

```php
use Netresearch\NrLlm\Service\Feature\VisionService;
use PHPUnit\Framework\TestCase;

class YourServiceTest extends TestCase
{
    public function testImageEnhancement(): void
    {
        $visionMock = $this->createMock(VisionService::class);
        $visionMock->method('generateAltText')
            ->willReturn('Test alt text');

        $service = new YourService($visionMock);
        $result = $service->enhanceImage('test.jpg');

        $this->assertEquals('Test alt text', $result['alt']);
    }
}
```

## Performance

### Caching

- **Embeddings**: 24h cache (deterministic)
- **Vision**: Short cache (subjective)
- **Translation**: Medium cache (context-dependent)
- **Completion**: Case-by-case basis

### Batch Processing

Use batch methods for better performance:

```php
// Good: Single request for multiple images
$altTexts = $visionService->generateAltText($imageUrls);

// Bad: Multiple individual requests
foreach ($imageUrls as $url) {
    $altText = $visionService->generateAltText($url);
}
```

## Configuration

### Custom Prompts

Override default prompts via database or configuration:

```sql
INSERT INTO tx_nrllm_prompts (
    identifier,
    title,
    feature,
    system_prompt,
    user_prompt_template,
    temperature,
    max_tokens,
    is_active
) VALUES (
    'custom.vision.alt_text',
    'Custom Alt Text',
    'vision',
    'Custom system prompt...',
    'Custom user prompt with {{image_url}}',
    0.5,
    100,
    1
);
```

### Service Options

All services accept configuration options:

```php
$result = $completionService->complete(
    prompt: 'Generate text',
    options: [
        'temperature' => 0.7,
        'max_tokens' => 1000,
        'top_p' => 0.9,
        'frequency_penalty' => 0.0,
        'presence_penalty' => 0.0,
        'response_format' => 'json',
        'system_prompt' => 'Custom instructions',
        'stop_sequences' => ['\n\n', 'END'],
    ]
);
```

## Extension Integration Examples

### rte-ckeditor-image

```php
use Netresearch\NrLlm\Service\Feature\VisionService;

class ImageAiService
{
    public function __construct(
        private readonly VisionService $visionService
    ) {}

    public function enhanceImage(FileReference $file): array
    {
        $url = $file->getPublicUrl();
        return [
            'alt' => $this->visionService->generateAltText($url),
            'title' => $this->visionService->generateTitle($url),
        ];
    }
}
```

### textdb

```php
use Netresearch\NrLlm\Service\Feature\TranslationService;
use Netresearch\NrLlm\Service\Feature\EmbeddingService;

class AiTranslationService
{
    public function __construct(
        private readonly TranslationService $translationService,
        private readonly EmbeddingService $embeddingService
    ) {}

    public function suggestTranslation(string $text, string $lang): array
    {
        return [
            'translation' => $this->translationService->translate($text, $lang),
            'similar' => $this->findSimilar($text),
        ];
    }
}
```

### contexts

```php
use Netresearch\NrLlm\Service\Feature\CompletionService;

class RuleGeneratorService
{
    public function __construct(
        private readonly CompletionService $completionService
    ) {}

    public function generateRule(string $description): ?array
    {
        return $this->completionService->completeJson(
            "Generate TYPO3 context rule: $description",
            ['temperature' => 0.2]
        );
    }
}
```

## Documentation

- Architecture: `claudedocs/07-feature-services-architecture.md`
- Integration Examples: `claudedocs/08-feature-services-integration-examples.md`
- Summary: `claudedocs/09-feature-services-summary.md`

## Requirements

- TYPO3 13.4+ / 14.x
- PHP 8.2+
- nr-llm core extension (LlmServiceManager)

## Support

For issues, questions, or contributions, please refer to the main nr-llm extension documentation.

## License

Same as parent nr-llm extension.
