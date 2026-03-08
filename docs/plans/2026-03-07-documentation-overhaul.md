# nr-llm Documentation Overhaul

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Reposition nr-llm from "LLM abstraction layer" to "the shared AI foundation for TYPO3" — making it attractive for third-party extension developers, TYPO3 administrators, and agencies to adopt.

**Architecture:** Six independent commits updating metadata (GitHub, composer, ext_emconf), README (audience-segmented restructure), Documentation/Introduction (value story), and a new Integration Guide. Each commit is self-contained.

**Tech Stack:** Markdown (README), reStructuredText (Documentation/), JSON (composer.json), PHP (ext_emconf.php), GitHub API (repo metadata)

---

## Commit 1: Update package metadata (composer.json + ext_emconf.php)

**Files:**
- Modify: `composer.json` (description, keywords)
- Modify: `ext_emconf.php` (title, description)

**Step 1: Update composer.json description and keywords**

In `composer.json`, change:

```json
"description": "TYPO3 extension providing unified AI/LLM provider abstraction layer for TYPO3 projects - by Netresearch",
```

to:

```json
"description": "Shared AI/LLM foundation for TYPO3 — centralized provider management, encrypted API keys, and ready-to-use services for chat, translation, vision, and embeddings",
```

Replace the `keywords` array with:

```json
"keywords": [
    "TYPO3",
    "extension",
    "LLM",
    "AI",
    "OpenAI",
    "Claude",
    "Gemini",
    "Ollama",
    "embeddings",
    "translation",
    "streaming",
    "provider-abstraction",
    "chatbot"
],
```

**Step 2: Update ext_emconf.php title and description**

Change title from:
```php
'title' => 'NR LLM - AI Provider Abstraction',
```
to:
```php
'title' => 'LLM — Shared AI Foundation for TYPO3',
```

Change description from the current text to:
```php
'description' => 'Shared AI foundation for TYPO3. Configure LLM providers once — every AI extension uses them. Supports OpenAI, Anthropic, Google Gemini, Ollama, and more. Includes services for chat, translation, vision, and embeddings with encrypted API keys and full admin control.',
```

**Step 3: Commit**

```bash
git add composer.json ext_emconf.php
git commit -S --signoff -m "docs: update package metadata with value-oriented descriptions"
```

---

## Commit 2: Update GitHub repository metadata

**Step 1: Update repo description and topics**

```bash
gh api repos/netresearch/t3x-nr-llm \
  -X PATCH \
  -f description="The shared AI foundation for TYPO3 — one LLM setup for every extension on your site" \
  --silent

gh api repos/netresearch/t3x-nr-llm/topics \
  -X PUT \
  --input - <<'EOF'
{"names":["ai","anthropic","claude","gemini","gpt","llm","openai","php","typo3","typo3-extension","ollama","embeddings","streaming","provider-abstraction"]}
EOF
```

**Step 2: Verify**

```bash
gh api repos/netresearch/t3x-nr-llm --jq '{description: .description, topics: .topics}'
```

No git commit needed — this is GitHub API metadata only.

---

## Commit 3: Restructure README.md

**Files:**
- Modify: `README.md` (full rewrite)

**Step 1: Replace README.md**

Write new README with this structure (full content below). Key principles:
- Lead with the problem/solution, not features
- Three audience sections (developers, admins, agencies)
- Keep architecture diagram (it's good)
- Move detailed API examples to "Quick Start" section (condensed)
- Keep supply chain security section (moved lower)
- Add "Built on nr-llm" section showcasing t3x-cowriter

The new README structure:

```markdown
# nr-llm — The Shared AI Foundation for TYPO3

[badges — keep existing badge block unchanged]

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

​```bash
composer require netresearch/nr-llm
​```

### 2. Inject the service you need

​```php
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
​```

That's it. Provider selection, API keys, caching, error handling — all managed by nr-llm.

### What you get for free

| Capability | Without nr-llm | With nr-llm |
|---|---|---|
| Provider switching | Rewrite HTTP calls | Change one admin setting |
| API key storage | `$GLOBALS` or plaintext | Encrypted (sodium/nr-vault) |
| Response caching | Build your own | Built-in, TYPO3 caching framework |
| Streaming (SSE) | Implement per provider | `foreach ($llm->streamChat($msg) as $chunk)` |
| Error handling | Parse each provider's errors | Typed exceptions with provider context |
| Rate limiting | Roll your own | Consumer extensions (like Cowriter) add their own |
| Multiple providers | N × integration effort | One interface, all providers |

### Available services

​```php
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
​```

### Why not call the OpenAI API directly?

You can — but then your extension only works with OpenAI. Your users can't switch to
Anthropic, use a local Ollama instance, or route through Azure. And every extension on the
site manages its own API keys, its own error handling, its own caching.

nr-llm solves this once for the entire TYPO3 ecosystem.

> 📖 **[Developer Guide](Documentation/Developer/Index.rst)** — Full API reference,
> custom provider registration, typed options, response objects

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
  optionally via nr-vault envelope encryption
- **Admin-only access** — Backend module restricted to administrators
- **No plaintext secrets** — Keys never stored or logged in plain text

### Setup in 2 minutes

The **Setup Wizard** auto-detects your provider type from the endpoint URL, discovers
available models, and generates a ready-to-use configuration. Paste your API key and go.

### Supported providers

| Provider | Adapter | Capabilities |
|---|---|---|
| OpenAI | `openai` | Chat, Embeddings, Vision, Streaming, Tools |
| Anthropic Claude | `anthropic` | Chat, Vision, Streaming, Tools |
| Google Gemini | `gemini` | Chat, Embeddings, Vision, Streaming, Tools |
| Ollama | `ollama` | Chat, Embeddings, Streaming (local, no API key) |
| OpenRouter | `openrouter` | Chat, Vision, Streaming, Tools |
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

​```
┌─────────────────────────────────────────────────────────┐
│ CONFIGURATION (Use-Case Specific)                       │
│ "blog-summarizer", "product-description", "translator"  │
│ → system_prompt, temperature, max_tokens                │
└───────────────────────────┬─────────────────────────────┘
                            │ references
┌───────────────────────────▼─────────────────────────────┐
│ MODEL (Available Models)                                │
│ "gpt-4o", "claude-sonnet", "llama-70b"                  │
│ → model_id, capabilities, pricing                       │
└───────────────────────────┬─────────────────────────────┘
                            │ references
┌───────────────────────────▼─────────────────────────────┐
│ PROVIDER (API Connections)                              │
│ "openai-prod", "openai-dev", "local-ollama"             │
│ → endpoint, api_key (encrypted), adapter_type           │
└─────────────────────────────────────────────────────────┘
​```

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

​```bash
composer require netresearch/nr-llm
​```

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

[keep existing supply chain section unchanged — SLSA, cosign, SBOM, checksums]

## License

GPL-2.0-or-later

## Author

Netresearch DTT GmbH
https://www.netresearch.de
```

**Step 2: Verify markdown renders correctly**

Visually scan for broken code fences, table alignment, link syntax.

**Step 3: Commit**

```bash
git add README.md
git commit -S --signoff -m "docs: restructure README around value proposition and audience segments"
```

---

## Commit 4: Rewrite Documentation/Introduction/Index.rst

**Files:**
- Modify: `Documentation/Introduction/Index.rst`

**Step 1: Rewrite introduction with value story**

Replace the opening "What does it do?" section (lines 9-18) with:

```rst
What does it do?
================

nr-llm is the **shared AI foundation for TYPO3**. It lets administrators configure
LLM providers once in the backend — and every AI-powered extension on the site uses
them automatically.

**For extension developers**, it eliminates the need to build provider integrations,
manage API keys, or implement caching and streaming. Add AI to your extension with
three lines of dependency injection.

**For administrators**, it provides a single backend module to manage all AI connections,
encrypted API keys, and provider configurations. Switch from OpenAI to Anthropic without
touching any extension code.

**For agencies**, it means consistent AI architecture across client projects, no vendor
lock-in, and a local-first option via Ollama for data-sensitive environments.
```

Keep the rest of the file (provider table, feature details, use cases, requirements) unchanged — they're good reference material.

**Step 2: Commit**

```bash
git add Documentation/Introduction/Index.rst
git commit -S --signoff -m "docs: rewrite introduction with value-oriented positioning"
```

---

## Commit 5: Add Integration Guide

**Files:**
- Create: `Documentation/Developer/IntegrationGuide.rst`
- Modify: `Documentation/Developer/Index.rst` (add toctree entry)
- Modify: `Documentation/Index.rst` (no change needed — Developer/Index already in toctree)

**Step 1: Create IntegrationGuide.rst**

```rst
.. include:: /Includes.rst.txt

.. _integration-guide:

=========================================
Build your extension on nr-llm
=========================================

This guide walks you through adding AI capabilities to a TYPO3 extension using
nr-llm as a dependency. By the end, your extension will have working AI features
without any provider-specific code.

.. contents::
   :local:
   :depth: 2

.. _integration-guide-why:

Why build on nr-llm?
====================

When your extension calls an LLM API directly, it takes on responsibility for:

- HTTP client setup, authentication, and error handling per provider
- Secure API key storage (not in :file:`ext_conf_template.txt` or :php:`$GLOBALS`)
- Response caching to control costs
- Streaming implementation for real-time UX
- A configuration UI for administrators

nr-llm handles all of this. Your extension focuses on *what* to ask the AI, not
*how* to reach it.

.. _integration-guide-step1:

Step 1: Add the dependency
==========================

.. code-block:: bash

   composer require netresearch/nr-llm

Add the dependency to your :file:`ext_emconf.php`:

.. code-block:: php
   :caption: ext_emconf.php

   'constraints' => [
       'depends' => [
           'typo3' => '13.4.0-14.99.99',
           'nr_llm' => '0.4.0-0.99.99',
       ],
   ],

.. _integration-guide-step2:

Step 2: Inject the service
==========================

All nr-llm services are available via TYPO3's dependency injection. Pick the
service that matches your use case:

.. code-block:: php
   :caption: Classes/Service/MyAiService.php

   <?php

   declare(strict_types=1);

   namespace MyVendor\MyExtension\Service;

   use Netresearch\NrLlm\Service\LlmServiceManagerInterface;

   final readonly class MyAiService
   {
       public function __construct(
           private LlmServiceManagerInterface $llm,
       ) {}

       public function summarize(string $text): string
       {
           $response = $this->llm->complete(
               "Summarize the following text in 2-3 sentences:\n\n" . $text,
           );

           return $response->content;
       }
   }

No :file:`Services.yaml` configuration needed — TYPO3's autowiring handles it.

.. _integration-guide-step3:

Step 3: Use feature services for specialized tasks
===================================================

For common AI tasks, use the specialized feature services instead of raw chat:

.. code-block:: php
   :caption: Translation example

   use Netresearch\NrLlm\Service\Feature\TranslationService;

   final readonly class ContentTranslator
   {
       public function __construct(
           private TranslationService $translator,
       ) {}

       public function translateToGerman(string $text): string
       {
           $result = $this->translator->translate($text, 'de');
           return $result->translation;
       }
   }

.. code-block:: php
   :caption: Image analysis example

   use Netresearch\NrLlm\Service\Feature\VisionService;

   final readonly class ImageMetadataGenerator
   {
       public function __construct(
           private VisionService $vision,
       ) {}

       public function generateAltText(string $imageUrl): string
       {
           return $this->vision->generateAltText($imageUrl);
       }
   }

.. code-block:: php
   :caption: Embedding/similarity example

   use Netresearch\NrLlm\Service\Feature\EmbeddingService;

   final readonly class ContentRecommender
   {
       public function __construct(
           private EmbeddingService $embeddings,
       ) {}

       /**
        * @param list<array{id: int, text: string, vector: list<float>}> $candidates
        * @return list<int> Top 5 most similar content IDs
        */
       public function findSimilar(string $query, array $candidates): array
       {
           $queryVector = $this->embeddings->embed($query);
           $results = $this->embeddings->findMostSimilar(
               $queryVector,
               array_column($candidates, 'vector'),
               topK: 5,
           );

           return array_map(
               fn(int $index) => $candidates[$index]['id'],
               array_keys($results),
           );
       }
   }

.. _integration-guide-step4:

Step 4: Handle errors gracefully
================================

nr-llm throws typed exceptions so you can provide meaningful feedback:

.. code-block:: php

   use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;
   use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
   use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
   use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;

   try {
       $response = $this->llm->complete($prompt);
   } catch (ProviderConfigurationException) {
       // No provider configured — guide the admin
       return 'AI features require LLM configuration. '
            . 'An administrator can set this up in Admin Tools > LLM.';
   } catch (ProviderConnectionException) {
       // Network issue — suggest retry
       return 'Could not reach the AI provider. Please try again.';
   } catch (ProviderResponseException $e) {
       // Provider returned an error (rate limit, invalid input, etc.)
       $this->logger->warning('LLM provider error', ['exception' => $e]);
       return 'The AI service returned an error. Please try again later.';
   }

.. _integration-guide-step5:

Step 5: Use database configurations (optional)
================================================

For advanced use cases, reference named configurations that admins create in the
backend module:

.. code-block:: php

   use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;

   final readonly class BlogSummarizer
   {
       public function __construct(
           private LlmConfigurationRepository $configRepo,
           private LlmServiceManagerInterface $llm,
       ) {}

       public function summarize(string $article): string
       {
           // Uses the "blog-summarizer" configuration created by the admin
           // (specific model, temperature, system prompt, etc.)
           $config = $this->configRepo->findByIdentifier('blog-summarizer');

           $response = $this->llm->chat(
               [['role' => 'user', 'content' => "Summarize:\n\n" . $article]],
               $config->toChatOptions(),
           );

           return $response->content;
       }
   }

.. _integration-guide-testing:

Testing your integration
========================

Mock the nr-llm interfaces in your unit tests:

.. code-block:: php
   :caption: Tests/Unit/Service/MyAiServiceTest.php

   use Netresearch\NrLlm\Domain\Model\CompletionResponse;
   use Netresearch\NrLlm\Domain\Model\UsageStatistics;
   use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
   use PHPUnit\Framework\TestCase;

   final class MyAiServiceTest extends TestCase
   {
       public function testSummarizeReturnsCompletionContent(): void
       {
           $llm = $this->createStub(LlmServiceManagerInterface::class);
           $llm->method('complete')->willReturn(
               new CompletionResponse(
                   content: 'A short summary.',
                   model: 'gpt-4o',
                   usage: new UsageStatistics(50, 20, 70),
                   finishReason: 'stop',
                   provider: 'openai',
               ),
           );

           $service = new MyAiService($llm);
           self::assertSame('A short summary.', $service->summarize('Long text...'));
       }
   }

.. _integration-guide-checklist:

Integration checklist
=====================

.. rst-class:: bignums

1. **composer.json** — Added ``netresearch/nr-llm`` to ``require``

2. **ext_emconf.php** — Added ``nr_llm`` to ``depends`` constraints

3. **Services** — Inject :php:`LlmServiceManagerInterface` or feature services via DI

4. **Error handling** — Catch typed exceptions and show user-friendly messages

5. **Testing** — Mock :php:`LlmServiceManagerInterface` in unit tests

6. **Documentation** — Tell your users they need to configure a provider in Admin Tools > LLM
```

**Step 2: Add to Developer toctree**

In `Documentation/Developer/Index.rst`, add `IntegrationGuide` to the existing toctree (after FeatureServices):

```rst
.. toctree::
   :maxdepth: 2
   :hidden:

   FeatureServices/Index
   IntegrationGuide
```

**Step 3: Commit**

```bash
git add Documentation/Developer/IntegrationGuide.rst Documentation/Developer/Index.rst
git commit -S --signoff -m "docs: add integration guide for extension developers"
```

---

## Commit 6: Version bump and tag

**Step 1: Bump version**

In `ext_emconf.php`, bump version from `0.4.7` to `0.4.8`.

**Step 2: Commit, push, tag**

```bash
git add ext_emconf.php
git commit -S --signoff -m "chore: bump version to 0.4.8"
git push origin main
git tag -s v0.4.8 -m "v0.4.8"
git push origin v0.4.8
```

---

## Verification

```bash
# Markdown lint (if available)
npx markdownlint-cli2 README.md

# RST syntax check
cd Documentation && make html 2>&1 | grep -i error

# Verify GitHub metadata
gh api repos/netresearch/t3x-nr-llm --jq '{description, topics}'

# Verify composer validates
composer validate --strict
```

Manual checks:
1. README renders correctly on GitHub (code blocks, tables, diagram)
2. Documentation renders on docs.typo3.org (after merge)
3. ext_emconf description shows correctly in Extension Manager
4. Packagist description updates after push
