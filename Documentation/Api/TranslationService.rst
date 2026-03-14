.. include:: /Includes.rst.txt

.. _api-translation-service:

==================
TranslationService
==================

.. php:namespace:: Netresearch\NrLlm\Service\Feature

.. php:class:: TranslationService

   Language translation with quality control.

   .. php:method:: translate(string $text, string $targetLanguage, ?string $sourceLanguage = null, array $options = []): TranslationResult

      Translate text to target language.

      :param string $text: Text to translate
      :param string $targetLanguage: Target language code (e.g., 'de', 'fr')
      :param string|null $sourceLanguage: Source language code (auto-detected if null)
      :param array $options: Translation options
      :returns: TranslationResult

      **Options:**

      - ``formality``: 'formal', 'informal', 'default'
      - ``domain``: 'technical', 'legal', 'medical', 'general'
      - ``glossary``: array of term translations
      - ``preserve_formatting``: bool

   .. php:method:: translateBatch(array $texts, string $targetLanguage, array $options = []): array

      Translate multiple texts.

      :param array $texts: Array of texts
      :param string $targetLanguage: Target language code
      :param array $options: Translation options
      :returns: array<TranslationResult>

   .. php:method:: detectLanguage(string $text): string

      Detect the language of text.

      :param string $text: Text to analyze
      :returns: string Language code

   .. php:method:: scoreTranslationQuality(string $source, string $translation, string $targetLanguage): float

      Score translation quality.

      :param string $source: Original text
      :param string $translation: Translated text
      :param string $targetLanguage: Target language code
      :returns: float Quality score (0.0 to 1.0)
