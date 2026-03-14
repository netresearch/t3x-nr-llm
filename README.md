# nr-llm — The Shared AI Foundation for TYPO3

[![CI](https://github.com/netresearch/t3x-nr-llm/actions/workflows/ci.yml/badge.svg)](https://github.com/netresearch/t3x-nr-llm/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/netresearch/t3x-nr-llm/graph/badge.svg)](https://codecov.io/gh/netresearch/t3x-nr-llm)
[![Documentation](https://github.com/netresearch/t3x-nr-llm/actions/workflows/docs.yml/badge.svg)](https://github.com/netresearch/t3x-nr-llm/actions/workflows/docs.yml)
[![OpenSSF Scorecard](https://api.securityscorecards.dev/projects/github.com/netresearch/t3x-nr-llm/badge)](https://securityscorecards.dev/viewer/?uri=github.com/netresearch/t3x-nr-llm)
[![OpenSSF Best Practices](https://www.bestpractices.dev/projects/11697/badge)](https://www.bestpractices.dev/projects/11697)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%2010-brightgreen.svg)](https://phpstan.org/)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)](https://www.php.net/)
[![TYPO3 v13.4+](https://img.shields.io/badge/TYPO3-v13.4%2B-orange.svg)](https://typo3.org/)
[![License: GPL v2](https://img.shields.io/badge/License-GPL_v2-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.en.html)
[![Latest Release](https://img.shields.io/github/v/release/netresearch/t3x-nr-llm)](https://github.com/netresearch/t3x-nr-llm/releases)
[![Contributor Covenant](https://img.shields.io/badge/Contributor%20Covenant-2.0-4baaaa.svg)](CODE_OF_CONDUCT.md)
[![SLSA 3](https://slsa.dev/images/gh-badge-level3.svg)](https://slsa.dev)

**One LLM setup. Every extension. Full admin control.**

nr-llm is shared infrastructure for AI in TYPO3 — like the caching framework, but for language models.
Administrators configure providers once; every AI-powered extension on the site uses them automatically.

## The Problem

Every TYPO3 extension that wants AI capabilities today has to:

- Build its own provider integration (HTTP calls, auth, error handling, streaming)
- Store API keys in its own way (often plaintext in extension settings)
- Create its own backend configuration UI
- Leave administrators with no central overview of AI usage or costs

When a site runs three AI extensions, that's three separate API key configurations,
three places to check when something breaks, and no way to switch providers globally.

## The Solution

nr-llm provides the missing shared layer:

```
┌─────────────────────────────────────────────────┐
│  Your Extension  │  Cowriter  │  SEO Assistant  │
│  (3 lines of DI) │           │                 │
└────────┬─────────┴─────┬─────┴────────┬────────┘
         │               │              │
┌────────▼───────────────▼──────────────▼────────┐
│              nr-llm Service Layer               │
│  Chat · Translation · Vision · Embeddings       │
│  Streaming · Tool Calling · Caching             │
├─────────────────────────────────────────────────┤
│         Provider Abstraction Layer              │
│  OpenAI · Anthropic · Gemini · Ollama · …       │
├─────────────────────────────────────────────────┤
│     Admin Tools > LLM (Backend Module)          │
│  Encrypted keys · Usage tracking · Setup wizard │
└─────────────────────────────────────────────────┘
```

---

## For Extension Developers

**Add AI to your TYPO3 extension in 5 minutes** — no API key handling, no HTTP client code,
no provider-specific logic.

### 1. Require the package

```bash
composer require netresearch/nr-llm
```

### 2. Inject the service you need

```php
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;

class MyController
{
    public function __construct(
        private readonly LlmServiceManagerInterface $llm,
    ) {}

    public function summarizeAction(string $text): string
    {
        return $this->llm->complete("Summarize: {$text}")->content;
    }
}
```

That's it. Provider selection, API keys, caching, error handling — all managed by nr-llm.

### What you get for free

| Capability | Without nr-llm | With nr-llm |
|---|---|---|
| Provider switching | Rewrite HTTP calls | Change one admin setting |
| API key storage | `$GLOBALS` or plaintext | Encrypted (sodium / nr-vault) |
| Response caching | Build your own | Built-in, TYPO3 caching framework |
| Streaming (SSE) | Implement per provider | `foreach ($llm->streamChat($msg) as $chunk)` |
| Error handling | Parse each provider's errors | Typed exceptions with provider context |
| Multiple providers | N × integration effort | One interface, all providers |

### Available services

```php
// Chat & completion
$response = $llm->chat($messages);
$response = $llm->complete('Explain TYPO3 content elements');

// Translation
$result = $translationService->translate('Hello world', 'de');

// Vision / image analysis
$altText = $visionService->generateAltText($imageUrl);

// Embeddings & similarity
$vector = $embeddingService->embed('semantic search query');

// Streaming
foreach ($llm->streamChat($messages) as $chunk) { echo $chunk; }

// Tool/function calling
$response = $llm->chatWithTools($messages, $toolDefinitions);
```

### Why not call the OpenAI API directly?

You can — but then your extension only works with OpenAI. Your users can't switch to
Anthropic, use a local Ollama instance, or route through Azure. And every extension on the
site manages its own API keys, its own error handling, its own caching.

nr-llm solves this once for the entire TYPO3 ecosystem.

> **[Developer Guide](Documentation/Developer/Index.rst)** — Full API reference,
> custom provider registration, typed options, response objects
>
> **[Integration Guide](Documentation/Developer/IntegrationGuide.rst)** — Step-by-step
> tutorial for building your extension on nr-llm

---

## For TYPO3 Administrators

### One dashboard for all AI

The **Admin Tools > LLM** backend module gives you full control:

- **Providers** — Register API connections (OpenAI, Anthropic, Gemini, Ollama, …)
- **Models** — Define which models are available and their capabilities
- **Configurations** — Create use-case presets (temperature, system prompts, token limits)
- **Tasks** — Define reusable prompt templates for editors

### Security by default

- **Encrypted API keys** — All keys stored with sodium_crypto_secretbox (XSalsa20-Poly1305),
  optionally via [nr-vault](https://github.com/netresearch/t3x-nr-vault) envelope encryption
- **Admin-only access** — Backend module restricted to administrators
- **No plaintext secrets** — Keys never stored or logged in plain text

### Setup in 2 minutes

The **Setup Wizard** auto-detects your provider type from the endpoint URL, discovers
available models, and generates a ready-to-use configuration. Paste your API key and go.

### AI-powered wizards

- **Task Wizard** — Describe what you need in plain language; the AI generates a complete task with configuration, system prompt, and model recommendation in one step
- **Configuration Wizard** — AI-assisted configuration generation with system prompts, parameters, and model selection
- **Model Discovery** — "Fetch Models" button on model fields queries the provider API and auto-fills capabilities and pricing

### Supported providers

| Provider | Adapter | Capabilities |
|---|---|---|
| OpenAI | `openai` | Chat, Embeddings, Vision, Streaming, Tools |
| Anthropic Claude | `anthropic` | Chat, Vision, Streaming, Tools |
| Google Gemini | `gemini` | Chat, Embeddings, Vision, Streaming, Tools |
| Ollama | `ollama` | Chat, Embeddings, Streaming (local, no API key) |
| OpenRouter | `openrouter` | Chat, Embeddings, Vision, Streaming, Tools |
| Mistral | `mistral` | Chat, Embeddings, Streaming |
| Groq | `groq` | Chat, Streaming (fast inference) |
| Azure OpenAI | `azure_openai` | Same as OpenAI |
| Any OpenAI-compatible | `custom` | Varies (vLLM, LocalAI, LiteLLM, …) |

---

## For Agencies & Solution Architects

- **Reduce integration effort** — AI capabilities across client projects without per-project plumbing
- **No vendor lock-in** — Switch from OpenAI to Anthropic (or a local model) without code changes
- **Compliance-friendly** — Encrypted keys, admin-only access, SBOM and SLSA provenance on every release
- **Local-first option** — Ollama support means AI features work without sending data to external APIs
- **Production-proven** — Powers [t3x-cowriter](https://github.com/netresearch/t3x-cowriter),
  the CKEditor 5 AI writing assistant for TYPO3

---

## Built on nr-llm

| Extension | What it does | nr-llm services used |
|---|---|---|
| [t3x-cowriter](https://github.com/netresearch/t3x-cowriter) | AI writing assistant in CKEditor 5 | Chat, Streaming, Translation, Tasks |

*Building on nr-llm? [Open a PR](https://github.com/netresearch/t3x-nr-llm/pulls) to add your extension here.*

---

## Architecture

The three-tier configuration hierarchy separates concerns cleanly:

```
┌─────────────────────────────────────────────────────────┐
│ CONFIGURATION (Use-Case Specific)                       │
│ "blog-summarizer", "product-description", "translator"  │
│ → system_prompt, temperature, max_tokens                │
└───────────────────────────┬─────────────────────────────┘
                            │ references
┌───────────────────────────▼─────────────────────────────┐
│ MODEL (Available Models)                                │
│ "gpt-5.3-instant", "claude-sonnet-4-6", "llama-70b"     │
│ → model_id, capabilities, pricing                       │
└───────────────────────────┬─────────────────────────────┘
                            │ references
┌───────────────────────────▼─────────────────────────────┐
│ PROVIDER (API Connections)                              │
│ "openai-prod", "openai-dev", "local-ollama"             │
│ → endpoint, api_key (encrypted), adapter_type           │
└─────────────────────────────────────────────────────────┘
```

**Benefits:**
- Multiple API keys per provider type (prod/dev/backup)
- Custom endpoints (Azure OpenAI, Ollama, vLLM, local models)
- Reusable model definitions across configurations
- Clear separation of concerns

---

## Requirements

- PHP 8.2+
- TYPO3 v13.4+ or v14.x
- PSR-18 HTTP Client (e.g., guzzlehttp/guzzle)

## Installation

```bash
composer require netresearch/nr-llm
```

Then activate in **Admin Tools > Extensions** and run **Admin Tools > LLM > Setup Wizard**.

---

## Documentation

- **[Introduction](Documentation/Introduction/Index.rst)** — Overview and use cases
- **[Configuration](Documentation/Configuration/Index.rst)** — Backend module setup
- **[Developer Guide](Documentation/Developer/Index.rst)** — API reference, services, custom providers
- **[Integration Guide](Documentation/Developer/IntegrationGuide.rst)** — Build your extension on nr-llm
- **[Architecture](Documentation/Architecture/Index.rst)** — Design decisions and ADRs

---

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
