# TYPO3 Extension: nr_llm

[![CI](https://github.com/netresearch/t3x-nr-llm/actions/workflows/ci.yml/badge.svg)](https://github.com/netresearch/t3x-nr-llm/actions/workflows/ci.yml)
[![Documentation](https://github.com/netresearch/t3x-nr-llm/actions/workflows/docs.yml/badge.svg)](https://github.com/netresearch/t3x-nr-llm/actions/workflows/docs.yml)
[![OpenSSF Scorecard](https://api.securityscorecards.dev/projects/github.com/netresearch/t3x-nr-llm/badge)](https://securityscorecards.dev/viewer/?uri=github.com/netresearch/t3x-nr-llm)
[![PHP 8.5+](https://img.shields.io/badge/PHP-8.5%2B-blue.svg)](https://www.php.net/)
[![TYPO3 v14](https://img.shields.io/badge/TYPO3-v14-orange.svg)](https://typo3.org/)
[![License: GPL v2](https://img.shields.io/badge/License-GPL_v2-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.en.html)
[![Contributor Covenant](https://img.shields.io/badge/Contributor%20Covenant-2.0-4baaaa.svg)](CODE_OF_CONDUCT.md)
[![SLSA 3](https://slsa.dev/images/gh-badge-level3.svg)](https://slsa.dev)

A unified LLM (Large Language Model) provider abstraction layer for TYPO3 v14.

## Features

- **Multi-Provider Support**: OpenAI, Anthropic Claude, Google Gemini, Ollama, OpenRouter, Mistral, Groq
- **Three-Tier Architecture**: Providers (connections) → Models (capabilities) → Configurations (use cases)
- **Encrypted API Keys**: API keys stored encrypted using sodium_crypto_secretbox
- **Feature Services**: Translation, Completion, Embedding, Vision
- **Caching**: Built-in response caching for embeddings and completions
- **Streaming**: Server-Sent Events (SSE) support for real-time responses
- **Tool Calling**: Function/tool support for compatible providers
- **Backend Module**: Manage providers, models, and configurations from TYPO3 backend

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

## Architecture

The extension uses a three-tier configuration hierarchy:

```
┌─────────────────────────────────────────────────────────┐
│ CONFIGURATION (Use-Case Specific)                       │
│ "blog-summarizer", "product-description", "translator"  │
│ → system_prompt, temperature, max_tokens                │
└───────────────────────────┬─────────────────────────────┘
                            │ references
┌───────────────────────────▼─────────────────────────────┐
│ MODEL (Available Models)                                 │
│ "gpt-4o", "claude-sonnet", "llama-70b"                  │
│ → model_id, capabilities, pricing                       │
└───────────────────────────┬─────────────────────────────┘
                            │ references
┌───────────────────────────▼─────────────────────────────┐
│ PROVIDER (API Connections)                               │
│ "openai-prod", "openai-dev", "local-ollama"             │
│ → endpoint, api_key (encrypted), adapter_type           │
└─────────────────────────────────────────────────────────┘
```

**Benefits:**
- Multiple API keys per provider type (prod/dev/backup)
- Custom endpoints (Azure OpenAI, Ollama, vLLM, local models)
- Reusable model definitions across configurations
- Clear separation of concerns

## Configuration

Configuration is managed through the TYPO3 Backend Module at **Admin Tools > LLM**:

### Providers

Create providers with API credentials:

| Field | Description |
|-------|-------------|
| `identifier` | Unique slug (e.g., `openai-prod`) |
| `adapter_type` | Protocol: `openai`, `anthropic`, `gemini`, `ollama`, etc. |
| `api_key` | Encrypted API key |
| `endpoint_url` | Custom endpoint (optional) |
| `timeout` | Request timeout in seconds |

### Models

Define available models:

| Field | Description |
|-------|-------------|
| `identifier` | Unique slug (e.g., `gpt-4o`) |
| `provider` | Reference to Provider |
| `model_id` | API model identifier (e.g., `gpt-4o-2024-08-06`) |
| `capabilities` | CSV: `chat,vision,streaming,tools` |

### Configurations

Create use-case-specific configurations:

| Field | Description |
|-------|-------------|
| `identifier` | Unique slug (e.g., `blog-summarizer`) |
| `model` | Reference to Model |
| `system_prompt` | System message for the AI |
| `temperature` | Creativity (0.0-2.0) |

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

### Using Database Configurations

```php
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;

class MyController
{
    public function __construct(
        private readonly LlmConfigurationRepository $configRepository,
        private readonly ProviderAdapterRegistry $adapterRegistry,
    ) {}

    public function processAction(): void
    {
        // Get configuration by identifier
        $config = $this->configRepository->findByIdentifier('blog-summarizer');

        // Get the model and provider chain
        $model = $config->getModel();
        $provider = $model->getProvider();

        // Create adapter and make requests
        $adapter = $this->adapterRegistry->createAdapterFromModel($model);
        $response = $adapter->chatCompletion($messages, $config->toOptions());
    }
}
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
        $altText = $this->visionService->generateAltText('https://example.com/image.jpg');
        $title = $this->visionService->generateTitle('https://example.com/image.jpg');
    }
}
```

### Translation

```php
use Netresearch\NrLlm\Service\Feature\TranslationService;

$result = $this->translationService->translate(
    'Hello, world!',
    'de', // target language
    'en', // source language (optional)
    [
        'formality' => 'formal',
        'glossary' => ['TYPO3' => 'TYPO3'],
    ]
);
```

### Streaming Responses

```php
$stream = $this->llmManager->streamChat($messages);

foreach ($stream as $chunk) {
    echo $chunk;
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
                    'location' => ['type' => 'string', 'description' => 'City name'],
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

High-level services for common AI tasks:

- **CompletionService** - Text generation with format control
- **VisionService** - Image analysis and metadata generation
- **EmbeddingService** - Text-to-vector conversion and similarity
- **TranslationService** - Language translation with glossaries
- **PromptTemplateService** - Centralized prompt management

## Supported Providers

| Provider | Adapter Type | Features |
|----------|--------------|----------|
| OpenAI | `openai` | Chat, Embeddings, Vision, Streaming, Tools |
| Anthropic Claude | `anthropic` | Chat, Vision, Streaming, Tools |
| Google Gemini | `gemini` | Chat, Embeddings, Vision, Streaming, Tools |
| Ollama | `ollama` | Chat, Embeddings, Streaming (local) |
| OpenRouter | `openrouter` | Chat, Vision, Streaming, Tools |
| Mistral | `mistral` | Chat, Embeddings, Streaming |
| Groq | `groq` | Chat, Streaming (fast inference) |
| Azure OpenAI | `azure_openai` | Same as OpenAI |
| Custom | `custom` | OpenAI-compatible endpoints |

## Security

- **API Keys**: Encrypted at rest using sodium_crypto_secretbox (XSalsa20-Poly1305)
- **Key Derivation**: Domain-separated key derivation from TYPO3's encryptionKey
- **Backend Access**: Restrict module access to authorized administrators
- **Input Validation**: Always sanitize user input before sending to providers
- **Output Handling**: Treat LLM responses as untrusted content

## Caching

Responses are cached using TYPO3's caching framework:

- **Cache identifier**: `nrllm_responses`
- **Default TTL**: 3600 seconds (1 hour)
- **Embeddings TTL**: 86400 seconds (24 hours)

Clear cache:

```bash
vendor/bin/typo3 cache:flush --group=nrllm
```

## Error Handling

```php
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Provider\Exception\RateLimitException;
use Netresearch\NrLlm\Provider\Exception\AuthenticationException;

try {
    $response = $this->llmManager->chat($messages);
} catch (AuthenticationException $e) {
    // Invalid API key
} catch (RateLimitException $e) {
    $retryAfter = $e->getRetryAfter();
} catch (ProviderException $e) {
    $this->logger->error('LLM error: ' . $e->getMessage());
}
```

## Documentation

Full documentation available at [Documentation/Index.rst](Documentation/Index.rst)

## Supply Chain Security

This project implements supply chain security best practices:

### SLSA Level 3 Provenance

All releases include [SLSA Level 3](https://slsa.dev) build provenance attestations, providing:
- **Non-falsifiable provenance**: Cryptographically signed attestations
- **Isolated build environment**: Builds run in GitHub Actions with no user-controlled steps
- **Source integrity**: Provenance links artifacts to exact source commit

### Verifying Release Artifacts

```bash
# Install slsa-verifier
go install github.com/slsa-framework/slsa-verifier/v2/cli/slsa-verifier@latest

# Download release artifacts
gh release download v1.0.0 -R netresearch/t3x-nr-llm

# Verify SLSA provenance
slsa-verifier verify-artifact nr_llm-1.0.0.zip \
  --provenance-path multiple.intoto.jsonl \
  --source-uri github.com/netresearch/t3x-nr-llm \
  --source-tag v1.0.0
```

### Verifying Signatures (Cosign)

All release artifacts are signed using [Sigstore Cosign](https://www.sigstore.dev/) keyless signing:

```bash
# Install cosign
go install github.com/sigstore/cosign/v2/cmd/cosign@latest

# Verify signature
cosign verify-blob nr_llm-1.0.0.zip \
  --signature nr_llm-1.0.0.zip.sig \
  --certificate nr_llm-1.0.0.zip.pem \
  --certificate-identity-regexp 'https://github.com/netresearch/t3x-nr-llm/' \
  --certificate-oidc-issuer 'https://token.actions.githubusercontent.com'
```

### Software Bill of Materials (SBOM)

Each release includes SBOMs in both [SPDX](https://spdx.dev/) and [CycloneDX](https://cyclonedx.org/) formats:
- `nr_llm-X.Y.Z.sbom.spdx.json` - SPDX format
- `nr_llm-X.Y.Z.sbom.cdx.json` - CycloneDX format

### Checksums

SHA256 checksums for all artifacts are provided in `checksums.txt`, which is also signed.

## License

GPL-2.0-or-later

## Author

Netresearch DTT GmbH
https://www.netresearch.de
