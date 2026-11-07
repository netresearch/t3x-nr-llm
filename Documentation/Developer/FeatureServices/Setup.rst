.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

=============================
Injection and prompt defaults
=============================

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
