.. include:: /Includes.rst.txt

.. _api-translation-service:

==================
TranslationService
==================

.. php:namespace:: Netresearch\NrLlm\Service\Feature

.. php:class:: TranslationService

   Language translation with quality control.

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

      **TranslationOptions fields:**

      - ``formality``: 'formal', 'informal', 'default'
      - ``domain``: 'technical', 'legal', 'medical',
        'marketing', 'general'
      - ``glossary``: array of term translations
      - ``preserve_formatting``: bool
      - ``provider``, ``model``: pin the provider /
        model for this call
      - ``configuration``: identifier of a stored
        ``LlmConfiguration`` whose translator is used on
        the specialized-translator path
        (``translateWithTranslator()``)

   .. php:method:: translateForConfiguration(string $text, string $targetLanguage, LlmConfiguration $configuration, ?string $sourceLanguage = null, ?TranslationOptions $options = null): TranslationResult

      Translate with a stored ``LlmConfiguration``'s
      persona/tone.

      Unlike :php:meth:`translate`, this routes through
      ``LlmServiceManager::chatWithConfiguration()`` so the
      configuration's stored ``system_prompt``, model,
      provider and skills apply. ``translate()`` supplies
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
:php:meth:`TranslationService::translateForConfiguration` — the
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
