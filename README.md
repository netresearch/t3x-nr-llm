# TYPO3 Extension: nr_llm

A unified LLM (Large Language Model) provider abstraction layer for TYPO3 v14.

## Features

- **Multi-Provider Support**: OpenAI, Anthropic Claude, Google Gemini
- **Unified API**: Single interface for all providers
- **Feature Services**: Translation, Completion, Embedding, Vision
- **Caching**: Built-in response caching for embeddings and completions
- **Streaming**: Server-Sent Events (SSE) support for real-time responses
- **Tool Calling**: Function/tool support for compatible providers
- **Backend Module**: Test and manage providers from TYPO3 backend

## Requirements

- PHP 8.5+
- TYPO3 v14.0+
- PSR-18 HTTP Client (e.g., guzzlehttp/guzzle)

## Installation

### Composer

```bash
composer require netresearch/nr-llm
```

### Manual

1. Download the extension
2. Extract to `typo3conf/ext/nr_llm`
3. Activate in Extension Manager

## Configuration

### Extension Settings

Configure API keys in **Admin Tools > Settings > Extension Configuration > nr_llm**:

| Setting | Description |
|---------|-------------|
| `openai_api_key` | OpenAI API key |
| `openai_default_model` | Default model (e.g., `gpt-4o`) |
| `claude_api_key` | Anthropic Claude API key |
| `claude_default_model` | Default model (e.g., `claude-sonnet-4-20250514`) |
| `gemini_api_key` | Google Gemini API key |
| `gemini_default_model` | Default model (e.g., `gemini-2.0-flash`) |
| `default_provider` | Default provider (`openai`, `claude`, `gemini`) |
| `request_timeout` | HTTP request timeout in seconds |

### TypoScript Constants

```typoscript
plugin.tx_nrllm {
    settings {
        defaultProvider = openai
        defaultTemperature = 0.7
        defaultMaxTokens = 1000
        cacheLifetime = 3600
    }
}
```

## Usage

### Basic Chat Completion

```php
use Netresearch\NrLlm\Service\LlmServiceManager;

class MyController
{
    public function __construct(
        private readonly LlmServiceManager $llmManager,
    ) {}

    public function chatAction(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
            ['role' => 'user', 'content' => 'Hello, how are you?'],
        ];

        $response = $this->llmManager->chat($messages);

        echo $response->content;
        echo "Tokens used: " . $response->usage->totalTokens;
    }
}
```

### Simple Completion

```php
$response = $this->llmManager->complete('Write a haiku about TYPO3');
echo $response->content;
```

### Using a Specific Provider

```php
$response = $this->llmManager->chat($messages, [
    'provider' => 'claude',
    'model' => 'claude-opus-4-20250514',
    'temperature' => 0.5,
]);
```

### Embeddings

```php
// Single text
$response = $this->llmManager->embed('Hello world');
$vector = $response->getVector(); // array<float>

// Multiple texts
$response = $this->llmManager->embed(['Text 1', 'Text 2']);
$vectors = $response->embeddings; // array<array<float>>
```

### Vision (Image Analysis)

```php
use Netresearch\NrLlm\Service\Feature\VisionService;

class ImageController
{
    public function __construct(
        private readonly VisionService $visionService,
    ) {}

    public function analyzeAction(): void
    {
        // Generate alt text
        $altText = $this->visionService->generateAltText(
            'https://example.com/image.jpg'
        );

        // Generate SEO title
        $title = $this->visionService->generateTitle(
            'https://example.com/image.jpg'
        );

        // Custom analysis
        $analysis = $this->visionService->analyzeImage(
            'https://example.com/image.jpg',
            'Describe the colors and composition of this image'
        );
    }
}
```

### Translation

```php
use Netresearch\NrLlm\Service\Feature\TranslationService;

class TranslationController
{
    public function __construct(
        private readonly TranslationService $translationService,
    ) {}

    public function translateAction(): void
    {
        $result = $this->translationService->translate(
            'Hello, world!',
            'de', // target language
            'en', // source language (optional, auto-detected)
            [
                'formality' => 'formal',
                'domain' => 'technical',
                'glossary' => [
                    'TYPO3' => 'TYPO3',
                    'extension' => 'Erweiterung',
                ],
            ]
        );

        echo $result->translation;
        echo "Detected source: " . $result->sourceLanguage;
    }
}
```

### Streaming Responses

```php
// Get a generator for streaming
$stream = $this->llmManager->streamChat($messages);

foreach ($stream as $chunk) {
    echo $chunk; // Output each chunk as it arrives
    flush();
}
```

### Tool/Function Calling

```php
$tools = [
    [
        'type' => 'function',
        'function' => [
            'name' => 'get_weather',
            'description' => 'Get current weather for a location',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'location' => [
                        'type' => 'string',
                        'description' => 'City name',
                    ],
                ],
                'required' => ['location'],
            ],
        ],
    ],
];

$response = $this->llmManager->chatWithTools($messages, $tools);

if ($response->toolCalls) {
    foreach ($response->toolCalls as $toolCall) {
        $functionName = $toolCall['function']['name'];
        $arguments = json_decode($toolCall['function']['arguments'], true);
        // Execute function and continue conversation
    }
}
```

## Feature Services

The extension provides high-level feature services for common AI tasks:

- **CompletionService** - Text generation and completion with format control
- **VisionService** - Image analysis and metadata generation (alt text, SEO titles)
- **EmbeddingService** - Text-to-vector conversion and similarity search
- **TranslationService** - Language translation with quality control
- **PromptTemplateService** - Centralized prompt management

For detailed documentation on each service, see [Documentation/Developer/FeatureServices/Index.rst](Documentation/Developer/FeatureServices/Index.rst)

## Supported Providers

### OpenAI

- **Models**: gpt-4o, gpt-4o-mini, gpt-4-turbo, o1-preview, o1-mini
- **Features**: Chat, Completion, Embeddings, Vision, Streaming, Tools

### Anthropic Claude

- **Models**: claude-opus-4, claude-sonnet-4, claude-3.5-sonnet, claude-3.5-haiku
- **Features**: Chat, Completion, Vision, Streaming, Tools
- **Note**: No native embeddings support

### Google Gemini

- **Models**: gemini-2.0-flash, gemini-1.5-pro, gemini-1.5-flash
- **Features**: Chat, Completion, Embeddings, Vision, Streaming, Tools

## Adding Custom Providers

Implement `ProviderInterface` and tag your service:

```php
<?php
namespace MyVendor\MyExtension\Provider;

use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\AbstractProvider;

class MyProvider extends AbstractProvider implements ProviderInterface
{
    public function getName(): string
    {
        return 'My Custom Provider';
    }

    public function getIdentifier(): string
    {
        return 'myprovider';
    }

    // Implement remaining methods...
}
```

Register in `Configuration/Services.yaml`:

```yaml
MyVendor\MyExtension\Provider\MyProvider:
  public: true
  tags:
    - name: nr_llm.provider
      priority: 50
```

## Backend Module

Access the LLM management module at **Admin Tools > LLM Providers**:

- View all registered providers and their status
- Test provider connections with sample prompts
- See available models and capabilities

## Caching

Responses are cached automatically using TYPO3's caching framework:

- **Cache identifier**: `nrllm_responses`
- **Default TTL**: 3600 seconds (1 hour)
- **Embeddings TTL**: 86400 seconds (24 hours)

Configure in TypoScript:

```typoscript
plugin.tx_nrllm.settings.cacheLifetime = 7200
```

Clear cache via CLI:

```bash
vendor/bin/typo3 cache:flush --group=nrllm
```

## Error Handling

The extension throws specific exceptions:

- `ProviderException`: General provider errors
- `AuthenticationException`: Invalid API key
- `RateLimitException`: Rate limit exceeded
- `InvalidArgumentException`: Invalid parameters

```php
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Provider\Exception\RateLimitException;

try {
    $response = $this->llmManager->chat($messages);
} catch (RateLimitException $e) {
    // Handle rate limiting, retry after delay
    $retryAfter = $e->getRetryAfter();
} catch (ProviderException $e) {
    // Handle general provider errors
    $this->logger->error('LLM error: ' . $e->getMessage());
}
```

## License

GPL-2.0-or-later

## Author

Netresearch DTT GmbH
https://www.netresearch.de
