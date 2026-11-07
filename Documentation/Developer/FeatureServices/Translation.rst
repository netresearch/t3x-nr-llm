.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

===================
Translation recipes
===================

.. _feature-services-translation:

TranslationService
==================

**Purpose**: Language translation with formatting and terminology options.

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
   // Finish-reason heuristic, not a translation-quality probability.
   $confidence = $result->confidence;

   // Batch translation
   $results = $translationService->translateBatch($texts, 'de');

   // Language detection
   $language = $translationService->detectLanguage($text);

   // A separate paid model judgment, not verified translation quality.
   $score = $translationService->scoreTranslationQuality($source, $translation, 'de');
