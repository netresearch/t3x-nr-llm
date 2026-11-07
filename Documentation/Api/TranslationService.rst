.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _api-translation-service:

==================
TranslationService
==================

.. php:namespace:: Netresearch\NrLlm\Service\Feature

.. php:class:: TranslationService

   Language translation with formatting and terminology options.

   The LLM path's ``confidence`` is a finish-reason heuristic: ``0.9`` for
   ``stop``, ``0.6`` for ``length`` and ``0.5`` otherwise. It is not a measured
   probability of a correct translation. Review important translations in
   context.

   .. php:method:: translate(string $text, string $targetLanguage, ?string $sourceLanguage = null, ?TranslationOptions $options = null): TranslationResult

      Translate text to target language.

      :param string $text: Text to translate
      :param string $targetLanguage: Target language
         code (e.g., 'de', 'fr')
      :param string|null $sourceLanguage: Source
         language code (auto-detected if null)
      :param TranslationOptions|null $options:
         Translation options
      :returns: TranslationResult

      See :ref:`api-translation-options` for translator and polishing options.

   .. php:method:: translateForConfiguration(string $text, string $targetLanguage, LlmConfiguration $configuration, ?string $sourceLanguage = null, ?TranslationOptions $options = null): TranslationResult

      Translate with a stored ``LlmConfiguration``'s
      persona/tone.

      Unlike ``translate()``, this routes through
      ``LlmServiceManager::chatWithConfiguration()`` so the
      configuration's stored ``system_prompt``, model
      and provider apply. This lower-level path does not inject attached
      skills. ``translate()`` supplies
      its own system message and therefore short-circuits
      ``MessageShaper::applySystemPrompt()``, so a
      configuration's ``system_prompt`` never reaches the
      model on that path. Here the translation task and
      constraints (target/source language, formality,
      glossary, "output only the translation") are layered
      into the **user** message instead, keeping the
      configuration's ``system_prompt`` as the system
      message.

      Mirrors ``chatWithToolsForConfiguration()`` and
      ``embedForConfiguration()``.

      :param string $text: Text to translate
      :param string $targetLanguage: Target language
         code (e.g., 'de', 'fr')
      :param LlmConfiguration $configuration: The
         configuration whose persona/model drive the call
      :param string|null $sourceLanguage: Source
         language code (auto-detected if null)
      :param TranslationOptions|null $options:
         Translation options; ``temperature``,
         ``max_tokens`` and ``model`` override the
         configuration's stored defaults when set. The
         ``provider`` field is ignored — the configuration
         selects the provider.
      :returns: TranslationResult

   .. php:method:: translateBatch(array $texts, string $targetLanguage, ?string $sourceLanguage = null, ?TranslationOptions $options = null): array

      Translate multiple texts.

      :param array $texts: Array of texts
      :param string $targetLanguage: Target language
         code
      :param string|null $sourceLanguage: Source
         language code (auto-detected if null)
      :param TranslationOptions|null $options:
         Translation options
      :returns: array<TranslationResult>

   .. php:method:: detectLanguage(string $text, ?TranslationOptions $options = null): string

      Detect the language of text.

      :param string $text: Text to analyze
      :param TranslationOptions|null $options:
         Translation options
      :returns: string Language code (ISO 639-1)

   .. php:method:: scoreTranslationQuality(string $sourceText, string $translatedText, string $targetLanguage, ?TranslationOptions $options = null): float

      Score translation quality.

      :param string $sourceText: Original text
      :param string $translatedText: Translated text
      :param string $targetLanguage: Target language
         code
      :param TranslationOptions|null $options:
         Translation options
      :returns: float Quality score (0.0 to 1.0)

.. _api-translation-service-configuration-recipe:

Editor localization menu: translate with a chosen configuration
===============================================================

An editor localization menu that lets the user pick between
configurations with different tones/prompts resolves the chosen
``LlmConfiguration`` and hands it to
``TranslationService::translateForConfiguration()`` — the
configuration's ``system_prompt`` (persona/tone) and model then drive
the call, while the translation task itself is layered in automatically.

.. code-block:: php

   use Netresearch\NrLlm\Service\Feature\TranslationServiceInterface;
   use Netresearch\NrLlm\Service\LlmConfigurationServiceInterface;

   public function __construct(
       private readonly TranslationServiceInterface $translationService,
       private readonly LlmConfigurationServiceInterface $configurationService,
   ) {}

   // 1. Offer the configurations the current backend user may use.
   $choices = $this->configurationService->getAccessibleConfigurations();

   // 2. Resolve the one the editor selected in the menu.
   $configuration = $this->configurationService->getConfiguration($selectedIdentifier);

   // 3. Translate with that configuration's persona/tone and model.
   $result = $this->translationService->translateForConfiguration(
       $text,
       'de',
       $configuration,
       // $sourceLanguage: null => auto-detected
   );

.. _api-translation-paths:

LLM and specialized translator paths
====================================

.. list-table:: Choose the result contract
   :header-rows: 1

   * - Entry point
     - Return type
     - Execution
   * - ``translate()`` / ``translateForConfiguration()``
     - ``Domain\Model\TranslationResult``
     - LLM call through the provider pipeline; ``translation`` holds the text.
   * - ``translateWithTranslator()``
     - ``Specialized\Translation\TranslatorResult``
     - Translator registry, such as DeepL or the LLM translator;
       ``translatedText`` holds the text.
   * - Batch variants
     - Array of the corresponding result type
     - Empty input returns an empty array.

.. php:method:: translateWithTranslator(string $text, string $targetLanguage, ?string $sourceLanguage = null, ?TranslationOptions $options = null): TranslatorResult

   Selects a registered translator from options/configuration/default and
   returns its specialized result. Optional polishing can make another paid
   model call; a guardrail policy exception propagates rather than returning
   the first translation. See :ref:`api-translation-options`.

.. php:method:: translateBatchWithTranslator(array $texts, string $targetLanguage, ?string $sourceLanguage = null, ?TranslationOptions $options = null): array

   Returns an array of ``TranslatorResult`` objects.

.. php:method:: getAvailableTranslators(): array

   Returns entries keyed by translator, each with ``identifier``, ``name``
   and ``available``. Registration and availability are different states.

.. php:method:: hasTranslator(string $identifier): bool

   Reports whether the translator is registered.

.. php:method:: getTranslator(string $identifier): TranslatorInterface

   Returns the registered translator; throws ``ServiceUnavailableException``
   for an unknown identifier.

.. php:method:: findBestTranslator(string $sourceLanguage, string $targetLanguage)
   :returntype: TranslatorInterface|null

   Returns the highest-priority translator supporting the pair, or ``null``.

.. _api-translation-detection-cost:

Detection, confidence and scoring costs
=======================================

On the LLM path, ``sourceLanguage=null`` makes a separate detection request
before translating. An uninterpretable detection result or exhausted output
budget falls back to English. Other provider failures can still propagate.
Pass a known source language when a silent language assumption is unsuitable.

``scoreTranslationQuality()`` makes another model request. Its output is cast
to a number and clamped to the supported range; it is a model judgment, not a
verified quality measurement. This cost is separate from translation and
from the finish-reason ``confidence`` heuristic.

.. toctree::
   :maxdepth: 1

   TranslationOptions
