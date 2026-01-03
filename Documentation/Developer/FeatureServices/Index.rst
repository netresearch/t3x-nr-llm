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

The feature services layer provides domain-specific AI capabilities for TYPO3 extensions.
Each service wraps the core :php:`LlmServiceManager` with specialized prompts, response parsing,
and configuration optimized for specific use cases.

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

- JSON response formatting.
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

   $completion = $completionService->complete(
       prompt: 'Explain TYPO3 in simple terms',
       options: [
           'temperature' => 0.3,
           'max_tokens' => 200,
           'response_format' => 'markdown',
       ]
   );

   echo $completion->text;

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

- WCAG 2.1 compliant alt text.
- SEO-optimized titles.
- Batch processing.
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

   // Generate WCAG-compliant alt text
   $altText = $visionService->generateAltText('https://example.com/image.jpg');

   // Generate SEO-optimized title
   $title = $visionService->generateTitle('/path/to/local/image.png');

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

.. _feature-services-translation-methods:

Methods
-------

.. code-block:: php
   :caption: TranslationService methods

   // Basic translation
   $result = $translationService->translate('Hello, world!', 'de');

   // With options
   $result = $translationService->translate(
       $text,
       targetLanguage: 'de',
       sourceLanguage: 'en',
       options: [
           'formality' => 'formal',
           'domain' => 'technical',
           'glossary' => [
               'TYPO3' => 'TYPO3',
               'extension' => 'Erweiterung',
           ],
           'preserve_formatting' => true,
       ]
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

.. _feature-services-prompt-template:

PromptTemplateService
=====================

**Purpose**: Centralized prompt management.

.. _feature-services-prompt-template-features:

Key features
------------

- Database-driven templates.
- Variable substitution.
- Conditional rendering.
- Version control.
- A/B testing.
- Performance tracking.

.. _feature-services-prompt-template-example:

Example
-------

.. code-block:: php
   :caption: Example: Using PromptTemplateService

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

.. _feature-services-default-prompts:

Default prompts
===============

The extension includes 10 default prompts optimized for common use cases:

.. _feature-services-prompts-vision:

Vision
------

- ``vision.alt_text`` - WCAG 2.1 compliant alt text.
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

   # Run all tests
   vendor/bin/phpunit Tests/Unit/

   # Run specific service tests
   vendor/bin/phpunit Tests/Unit/Service/Feature/CompletionServiceTest.php

.. _feature-services-testing-mocking:

Mocking services
----------------

.. code-block:: php
   :caption: Example: Mocking feature services in tests

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

.. _feature-services-performance:

Performance
===========

.. _feature-services-caching:

Caching
-------

- **Embeddings**: 24h cache (deterministic).
- **Vision**: Short cache (subjective).
- **Translation**: Medium cache (context-dependent).
- **Completion**: Case-by-case basis.

.. _feature-services-batch:

Batch processing
----------------

Use batch methods for better performance:

.. code-block:: php
   :caption: Batch processing example

   // Good: Single request for multiple images
   $altTexts = $visionService->generateAltText($imageUrls);

   // Bad: Multiple individual requests
   foreach ($imageUrls as $url) {
       $altText = $visionService->generateAltText($url);
   }

.. _feature-services-configuration:

Configuration
=============

.. _feature-services-custom-prompts:

Custom prompts
--------------

Override default prompts via database or configuration:

.. code-block:: sql
   :caption: Custom prompt template in database

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

.. _feature-services-service-options:

Service options
---------------

All services accept configuration options:

.. code-block:: php
   :caption: Service options example

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

       public function enhanceImage(FileReference $file): array
       {
           $url = $file->getPublicUrl();
           return [
               'alt' => $this->visionService->generateAltText($url),
               'title' => $this->visionService->generateTitle($url),
           ];
       }
   }

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

       public function suggestTranslation(string $text, string $lang): array
       {
           return [
               'translation' => $this->translationService->translate($text, $lang),
               'similar' => $this->findSimilar($text),
           ];
       }
   }

.. _feature-services-integration-contexts:

contexts
--------

.. code-block:: php
   :caption: Example: Contexts rule generation

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

.. _feature-services-requirements:

Requirements
============

- TYPO3 v14.0+ (v14.x).
- PHP 8.2+.
- nr-llm core extension (:php:`LlmServiceManager`).
