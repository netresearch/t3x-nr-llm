.. include:: /Includes.rst.txt

.. _adr-019:

=====================================
ADR-019: Internationalization Strategy
=====================================

:Status: Accepted
:Date: 2025-12
:Authors: Netresearch DTT GmbH

.. _adr-019-context:

Context
=======

The backend module needs multi-language support for all
UI elements. Additionally, LLM-powered features (test
prompts, wizard descriptions) should respect the backend
user's locale so that responses arrive in the expected
language.

.. _adr-019-decision:

Decision
========

Follow TYPO3 XLIFF conventions for static UI strings and add locale-aware
placeholder substitution for dynamic LLM interactions.

.. _adr-019-xliff:

XLIFF label files
-----------------

One XLIFF file per backend module, plus German translations:

.. csv-table::
   :header: "File", "Scope"
   :widths: 50, 50

   "locallang.xlf / de.locallang.xlf", "Shared labels, flash messages"
   "locallang_tca.xlf / de.locallang_tca.xlf", "TCA field labels and descriptions"
   "locallang_mod.xlf / de.locallang_mod.xlf", "Main module navigation"
   "locallang_mod_provider.xlf / de.*", "Provider sub-module"
   "locallang_mod_model.xlf / de.*", "Model sub-module"
   "locallang_mod_config.xlf / de.*", "Configuration sub-module"
   "locallang_mod_task.xlf / de.*", "Task sub-module"
   "locallang_mod_wizard.xlf / de.*", "Setup Wizard sub-module"
   "locallang_mod_overview.xlf / de.*", "Overview/Dashboard sub-module"

.. _adr-019-locale-aware:

Locale-aware LLM features
--------------------------

The :php:`TestPromptTrait` resolves the backend user's
language and substitutes a ``{lang}`` placeholder in
configurable test prompts:

.. code-block:: php
   :caption: TestPromptTrait locale resolution

   private function resolveTestPrompt(): string
   {
       $default = 'Say hello and introduce yourself in one sentence. Respond in {lang}.';
       // ... resolve from extension configuration ...

       $lang = $uc['lang'] ?? 'default';
       $languageName = $this->mapLanguageCodeToName($lang);

       return str_replace('{lang}', $languageName, $prompt);
   }

Language mapping covers 27 locales (English, German, French, Spanish, Italian,
Dutch, Portuguese, Danish, Swedish, Norwegian, Finnish, Polish, Czech, Slovak,
Hungarian, Romanian, Bulgarian, Croatian, Slovenian, Greek, Turkish, Russian,
Ukrainian, Chinese, Japanese, Korean, Arabic) with English as fallback.

The test prompt text itself is configurable via TYPO3 extension configuration
(``$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm']['testing']['testPrompt']``),
allowing administrators to customize it while preserving
the ``{lang}`` placeholder.

.. _adr-019-consequences:

Consequences
============
**Positive:**

- ●● Standard TYPO3 XLIFF approach ensures compatibility with the Translation
  Handling system and third-party translation tools.
- ● German translations shipped as first non-English locale.
- ● Locale-aware test prompts produce responses in the user's language.
- ◐ Configurable test prompt allows site-specific customization.
- ◐ ``{lang}`` placeholder pattern is extensible to other features.

**Negative:**

- ◑ Additional XLIFF files increase maintenance surface per feature.
- ◑ Language name mapping requires manual updates for new TYPO3 locales.

**Net Score:** +5.0 (Strong positive)

.. _adr-019-files-changed:

Files changed
=============

**Added:**

- :file:`Resources/Private/Language/locallang.xlf` and ``de.locallang.xlf``
- :file:`Resources/Private/Language/locallang_tca.xlf`
  and ``de.locallang_tca.xlf``
- :file:`Resources/Private/Language/locallang_mod.xlf`
  and ``de.locallang_mod.xlf``
- :file:`Resources/Private/Language/locallang_mod_provider.xlf` and ``de.*``
- :file:`Resources/Private/Language/locallang_mod_model.xlf` and ``de.*``
- :file:`Resources/Private/Language/locallang_mod_config.xlf` and ``de.*``
- :file:`Resources/Private/Language/locallang_mod_task.xlf` and ``de.*``
- :file:`Resources/Private/Language/locallang_mod_wizard.xlf` and ``de.*``
- :file:`Resources/Private/Language/locallang_mod_overview.xlf` and ``de.*``
- :file:`Classes/Controller/Backend/TestPromptTrait.php`
