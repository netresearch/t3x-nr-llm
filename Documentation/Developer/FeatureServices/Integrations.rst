.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

=============================
Consumer integration examples
=============================

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
