.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _feature-services:

================
Feature services
================

High-level AI services for TYPO3 with prompt engineering and response parsing.

.. contents::
   :local:
   :depth: 2

.. _feature-services-overview:

Overview
========

The feature services layer provides domain-specific AI
capabilities for TYPO3 extensions. Each service wraps
the core :php:`LlmServiceManager` with specialized
prompts, response parsing, and configuration optimized
for specific use cases.

.. _feature-services-architecture:

Architecture
============

.. code-block:: text
   :caption: Feature services architecture

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

.. _feature-services-completion:

CompletionService
=================

**Purpose**: Text generation and completion.

.. _feature-services-completion-use-cases:

Use cases
---------

- Content generation.
- Rule generation (contexts extension).
- Content summarization.
- SEO meta generation.

.. _feature-services-completion-features:

Key features
------------

- JSON response formatting (native JSON mode on every provider,
  :ref:`ADR-128 <adr-128>`).
- Schema-validated structured output — a strict JSON-schema subset,
  provider-enforced, with one repair round-trip (:ref:`ADR-126
  <adr-126>`).
- Markdown generation.
- Factual mode (low creativity).
- Creative mode (high creativity).
- System prompt support.

.. _feature-services-completion-example:

Example
-------

.. code-block:: php
   :caption: Example: Using CompletionService

   use Netresearch\NrLlm\Service\Feature\CompletionService;
   use Netresearch\NrLlm\Service\Option\ChatOptions;

   $completion = $completionService->complete(
       prompt: 'Explain TYPO3 in simple terms',
       options: new ChatOptions(
           temperature: 0.3,
           maxTokens: 200,
           responseFormat: 'markdown',
       ),
   );

   echo $completion->content;

.. _feature-services-completion-methods:

Methods
-------

.. code-block:: php
   :caption: CompletionService methods

   // Standard completion
   $response = $completionService->complete($prompt);

   // JSON output
   $data = $completionService->completeJson('List 5 colors as a JSON array');

   // Markdown output
   $markdown = $completionService->completeMarkdown('Write docs for this API');

   // Factual (low creativity, high consistency)
   $response = $completionService->completeFactual('What is the capital of France?');

   // Creative (high creativity)
   $response = $completionService->completeCreative('Write a haiku about coding');

   // Structured: schema-validated JSON (strict subset, ADR-126); ->data is
   // the payload, ->response the answering call, ->usage every attempt
   $data = $completionService->completeStructured('Rate this text', [
       'type'       => 'object',
       'required'   => ['score', 'reason'],
       'properties' => [
           'score'  => ['type' => 'number'],
           'reason' => ['type' => 'string'],
       ],
   ])->data;

.. _feature-services-vision:

VisionService
=============

**Purpose**: Image analysis and metadata generation.

.. _feature-services-vision-use-cases:

Use cases
---------

- Alt text generation (rte-ckeditor-image).
- SEO title generation.
- Detailed descriptions.
- Custom image analysis.

.. _feature-services-vision-features:

Key features
------------

- Prompts for accessible alt text, which needs review in its page context.
- Prompts for concise image titles.
- Sequential processing of image arrays.
- Base64 and URL support.

.. _feature-services-vision-example:

Example
-------

.. code-block:: php
   :caption: Example: Using VisionService

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

.. _feature-services-vision-methods:

Methods
-------

.. code-block:: php
   :caption: VisionService methods

   // Generate an alt-text suggestion for review
   $altText = $visionService->generateAltText('https://example.com/image.jpg');

   // Request a concise title
   $title = $visionService->generateTitle('https://example.com/image.png');

   // Generate detailed description
   $description = $visionService->generateDescription($imageUrl);

   // Custom analysis
   $analysis = $visionService->analyzeImage(
       $imageUrl,
       'What colors are prominent in this image?'
   );

.. _feature-services-embedding:

EmbeddingService
================

**Purpose**: Text-to-vector conversion and similarity search.

.. _feature-services-embedding-use-cases:

Use cases
---------

- Semantic translation memory (textdb).
- Content similarity.
- Duplicate detection.
- Semantic search.

.. _feature-services-embedding-features:

Key features
------------

- Aggressive caching (deterministic).
- Batch processing.
- Cosine similarity calculations.
- Top-K similarity search.

.. _feature-services-embedding-example:

Example
-------

.. code-block:: php
   :caption: Example: Using EmbeddingService

   use Netresearch\NrLlm\Service\Feature\EmbeddingService;

   // Generate embedding
   $vector = $embeddingService->embed('Search query text');

   // Find similar
   $similar = $embeddingService->findMostSimilar(
       queryVector: $vector,
       candidateVectors: $allVectors,
       topK: 5
   );

.. _feature-services-embedding-methods:

Methods
-------

.. code-block:: php
   :caption: EmbeddingService methods

   // Generate embedding (cached automatically)
   $vector = $embeddingService->embed('Some text');

   // Full response with metadata
   $response = $embeddingService->embedFull('Some text');

   // Batch embedding
   $vectors = $embeddingService->embedBatch(['Text 1', 'Text 2']);

   // Calculate cosine similarity
   $similarity = $embeddingService->cosineSimilarity($vectorA, $vectorB);

   // Find most similar vectors
   $results = $embeddingService->findMostSimilar(
       $queryVector,
       $candidateVectors,
       topK: 5
   );

   // Normalize a vector
   $normalized = $embeddingService->normalize($vector);

.. _feature-services-translation:

TranslationService
==================

**Purpose**: Language translation with quality control.

.. _feature-services-translation-use-cases:

Use cases
---------

- Translation suggestions (textdb).
- Content localization.
- Glossary-aware translation.

.. _feature-services-translation-features:

Key features
------------

- Language detection.
- Glossary support.
- Formality levels.
- Domain specialization.
- Quality scoring.

.. _feature-services-translation-example:

Example
-------

.. code-block:: php
   :caption: Example: Using TranslationService

   use Netresearch\NrLlm\Service\Feature\TranslationService;
   use Netresearch\NrLlm\Service\Option\TranslationOptions;

   $result = $translationService->translate(
       text: 'The TYPO3 extension is great',
       targetLanguage: 'de',
       options: new TranslationOptions(
           glossary: ['TYPO3' => 'TYPO3'],
           formality: 'formal',
           domain: 'technical',
       ),
   );

   echo $result->translation;
   echo $result->confidence;

.. _feature-services-translation-methods:

Methods
-------

.. code-block:: php
   :caption: TranslationService methods

   use Netresearch\NrLlm\Service\Option\TranslationOptions;

   // Basic translation
   $result = $translationService->translate('Hello, world!', 'de');

   // With options
   $result = $translationService->translate(
       $text,
       targetLanguage: 'de',
       sourceLanguage: 'en',
       options: new TranslationOptions(
           formality: 'formal',
           domain: 'technical',
           glossary: [
               'TYPO3' => 'TYPO3',
               'extension' => 'Erweiterung',
           ],
           preserveFormatting: true,
       ),
   );

   // TranslationResult properties
   $translation = $result->translation;
   $sourceLanguage = $result->sourceLanguage;
   $confidence = $result->confidence;

   // Batch translation
   $results = $translationService->translateBatch($texts, 'de');

   // Language detection
   $language = $translationService->detectLanguage($text);

   // Quality scoring
   $score = $translationService->scoreTranslationQuality($source, $translation, 'de');

.. _feature-services-installation:

Installation
============

.. _feature-services-di:

Dependency injection
--------------------

Add to your extension's :file:`Configuration/Services.yaml`:

.. code-block:: yaml
   :caption: Configuration/Services.yaml

   services:
     Your\Extension\Service\YourService:
       public: true
       arguments:
         $visionService: '@Netresearch\NrLlm\Service\Feature\VisionService'
         $translationService: '@Netresearch\NrLlm\Service\Feature\TranslationService'
         $completionService: '@Netresearch\NrLlm\Service\Feature\CompletionService'
         $embeddingService: '@Netresearch\NrLlm\Service\Feature\EmbeddingService'

.. _feature-services-usage:

Usage in your extension
-----------------------

.. code-block:: php
   :caption: Example: Using feature services in your extension

   <?php

   namespace Your\Extension\Service;

   use Netresearch\NrLlm\Service\Feature\VisionServiceInterface;

   class YourService
   {
       public function __construct(
           private readonly VisionServiceInterface $visionService
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

.. _feature-services-default-prompts:

Default prompts
===============

:file:`Resources/Private/Data/DefaultPrompts.php` contains a reference catalog
of ten prompt templates. The runtime does not load that catalog. Feature
services use their own built-in prompts; applications can use the catalog
as a starting point for their own prompt snippets or custom analysis.

.. _feature-services-prompts-vision:

Vision
------

- ``vision.alt_text`` - Alt-text suggestions for accessibility review.
- ``vision.seo_title`` - SEO-optimized titles.
- ``vision.description`` - Detailed descriptions.

.. _feature-services-prompts-translation:

Translation
-----------

- ``translation.general`` - General purpose translation.
- ``translation.technical`` - Technical documentation.
- ``translation.marketing`` - Marketing copy.

.. _feature-services-prompts-completion:

Completion
----------

- ``completion.rule_generation`` - TYPO3 contexts rules.
- ``completion.content_summary`` - Content summarization.
- ``completion.seo_meta`` - SEO meta descriptions.

.. _feature-services-prompts-embedding:

Embedding
---------

- ``embedding.semantic_search`` - Semantic search configuration.

.. _feature-services-testing:

Testing
=======

.. _feature-services-testing-unit:

Unit tests
----------

.. code-block:: bash
   :caption: Run feature service tests

   # Run all unit tests
   Build/Scripts/runTests.sh -s unit

   # Alternative: Via Composer script
   composer ci:test:php:unit

.. _feature-services-testing-mocking:

Mocking services
----------------

.. code-block:: php
   :caption: Example: Mocking feature services in tests

   use Netresearch\NrLlm\Service\Feature\VisionServiceInterface;
   use PHPUnit\Framework\TestCase;

   class YourServiceTest extends TestCase
   {
       public function testImageEnhancement(): void
       {
           $visionMock = $this->createMock(VisionServiceInterface::class);
           $visionMock->method('generateAltText')
               ->willReturn('Test alt text');

           $service = new YourService($visionMock);
           $result = $service->enhanceImage('https://example.com/test.jpg');

           $this->assertEquals('Test alt text', $result['alt']);
       }
   }

.. _feature-services-performance:

Performance
===========

.. _feature-services-caching:

Caching
-------

- **Embeddings**: 24h cache (deterministic).
- **Vision and completion**: No response cache is enabled by these services.
- **Translation**: Opt in with a positive ``TranslationOptions::cacheTtl``;
  caching also requires a resolvable configuration and the cache service.

.. _feature-services-batch:

Batch processing
----------------

Image arrays provide a convenient batch interface. Each image makes its own
provider request, in order; batching does not reduce the number of requests.

.. code-block:: php
   :caption: Batch processing example

   // One provider request per image, with results in the same order
   $altTexts = $visionService->generateAltText($imageUrls);

.. _feature-services-configuration:

Configuration
=============

.. _feature-services-custom-prompts:

Custom prompts
--------------

Pass a custom analysis prompt to the vision service:

.. code-block:: php
   :caption: Custom image analysis prompt

   $analysis = $visionService->analyzeImage(
       $imageUrl,
       'Describe the visible objects for this product catalog.',
   );

For configuration-owned prompts, use the backend's LLM configurations and
prompt snippets. The API documentation describes those records and their
composition; they do not replace the feature service's built-in prompt constants.

.. _feature-services-service-options:

Service options
---------------

Services accept the options object for their operation:

.. code-block:: php
   :caption: Service options example

   use Netresearch\NrLlm\Service\Option\ChatOptions;

   $result = $completionService->complete(
       prompt: 'Generate text',
       options: new ChatOptions(
           temperature: 0.7,
           maxTokens: 1000,
           topP: 0.9,
           frequencyPenalty: 0.0,
           presencePenalty: 0.0,
           responseFormat: 'json',
           systemPrompt: 'Custom instructions',
           stopSequences: ["\n\n", 'END'],
       ),
   );

.. _feature-services-extension-integration:

Extension integration examples
==============================

.. _feature-services-integration-ckeditor:

rte-ckeditor-image
------------------

.. code-block:: php
   :caption: Example: CKEditor image integration

   use Netresearch\NrLlm\Service\Feature\VisionService;

   class ImageAiService
   {
       public function __construct(
           private readonly VisionService $visionService
       ) {}

       public function enhanceImage(string $absoluteImageUrl): array
       {
           return [
               'alt' => $this->visionService->generateAltText($absoluteImageUrl),
               'title' => $this->visionService->generateTitle($absoluteImageUrl),
           ];
       }
   }

Resolve a FAL file's public URL to an absolute HTTPS URL before calling this
service, or read the file and supply a base64 data URL.

.. _feature-services-integration-textdb:

textdb
------

.. code-block:: php
   :caption: Example: textdb translation integration

   use Netresearch\NrLlm\Service\Feature\TranslationService;
   use Netresearch\NrLlm\Service\Feature\EmbeddingService;

   class AiTranslationService
   {
       public function __construct(
           private readonly TranslationService $translationService,
           private readonly EmbeddingService $embeddingService
       ) {}

       public function suggestTranslation(
           string $text,
           string $lang,
           array $candidateVectors,
       ): array
       {
           $vector = $this->embeddingService->embed($text);
           return [
               'translation' => $this->translationService->translate($text, $lang),
               'similar' => $this->embeddingService->findMostSimilar(
                   $vector,
                   $candidateVectors,
               ),
           ];
       }
   }

.. _feature-services-integration-contexts:

contexts
--------

.. code-block:: php
   :caption: Example: Contexts rule generation

   use Netresearch\NrLlm\Service\Feature\CompletionService;
   use Netresearch\NrLlm\Service\Option\ChatOptions;

   class RuleGeneratorService
   {
       public function __construct(
           private readonly CompletionService $completionService
       ) {}

       public function generateRule(string $description): ?array
       {
           return $this->completionService->completeJson(
               "Generate TYPO3 context rule: $description",
               new ChatOptions(temperature: 0.2),
           );
       }
   }

.. _feature-services-file-structure:

File structure
==============

.. code-block:: text
   :caption: Feature services file structure

   nr-llm/
   ├── Classes/
   │   ├── Domain/
   │   │   └── Model/
   │   │       ├── CompletionResponse.php
   │   │       ├── VisionResponse.php
   │   │       ├── TranslationResult.php
   │   │       ├── EmbeddingResponse.php
   │   │       ├── UsageStatistics.php
   │   │       └── RenderedPrompt.php
   │   ├── Service/
   │   │   └── Feature/
   │   │       ├── CompletionService.php
   │   │       ├── VisionService.php
   │   │       ├── EmbeddingService.php
   │   │       └── TranslationService.php
   │   └── Exception/
   │       └── InvalidArgumentException.php
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

.. _feature-services-requirements:

Requirements
============

- TYPO3 v13.4+.
- PHP 8.2+.
- nr-llm core extension (:php:`LlmServiceManager`).
