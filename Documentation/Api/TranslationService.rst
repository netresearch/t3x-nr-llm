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
